<?php

declare(strict_types=1);

/**
 * Воркер очереди: один проход, ограничение по числу писем, отметка «жив»,
 * запрошенный перезапуск и чистка старых записей по срокам.
 *
 * Воркер разгребает очередь до дна, поэтому работает на своей базе: на общей
 * он отправлял бы письма соседних тестов, а те ждут их в очереди.
 * Отправка идёт через транспорт-заглушку, сеть не нужна.
 */

use Mailer\MailService;
use Mailer\Queue\Worker;
use Mailer\Repository\MessageRepository;
use Mailer\Repository\SettingRepository;
use Mailer\Repository\TransportRepository;
use Mailer\Repository\WebhookRepository;
use Mailer\Storage\Database;
use Mailer\Support\Str;
use Mailer\Webhook\Event;

/**
 * Воркер на своей базе, молчащий в консоль.
 */
function silentWorker(Database $db): Worker
{
    return new Worker($db, static function (string $line): void {
        // Воркер печатает ход работы, тестам этот шум не нужен
    });
}

/**
 * Транспорт-заглушка в своей базе.
 */
function workerTransport(): void
{
    (new TransportRepository())->create([
        'name'       => 'воркер-null',
        'type'       => 'null',
        'settings'   => [],
        'from_email' => 'noreply@example.com',
        'is_default' => true,
    ]);
}

/**
 * Ставит письмо в очередь и возвращает его id.
 *
 * @param array<string, mixed> $extra
 */
function workerMessage(string $subject, array $extra = []): int
{
    $accepted = (new MailService())->accept(array_merge([
        'to'        => 'worker@example.com',
        'subject'   => $subject,
        'text'      => 'текст',
        'transport' => 'воркер-null',
    ], $extra));

    return (int) $accepted['id'];
}

test('за один проход воркер разгребает очередь', function (): void {
    withOwnDatabase(static function (Database $db): void {
        workerTransport();

        $first  = workerMessage('Воркер, письмо 1');
        $second = workerMessage('Воркер, письмо 2');

        $processed = silentWorker($db)->run(true);

        assertSame(2, $processed, 'воркер должен был отправить оба письма');

        $messages = new MessageRepository();

        assertSame('sent', (string) ((array) $messages->find($first))['status']);
        assertSame('sent', (string) ((array) $messages->find($second))['status']);
    });
});

test('ограничение по числу писем останавливает воркер', function (): void {
    withOwnDatabase(static function (Database $db): void {
        workerTransport();

        $ids = [workerMessage('Ограничение 1'), workerMessage('Ограничение 2'), workerMessage('Ограничение 3')];

        $processed = silentWorker($db)->run(true, 2);

        assertSame(2, $processed, 'больше указанного воркер брать не должен');

        $messages = new MessageRepository();
        $sent     = 0;

        foreach ($ids as $id) {
            if ((string) ((array) $messages->find($id))['status'] === 'sent') {
                $sent++;
            }
        }

        assertSame(2, $sent, 'отправлено должно быть ровно столько, сколько разрешили');
    });
});

test('воркер оставляет отметку «жив»', function (): void {
    withOwnDatabase(static function (Database $db): void {
        workerTransport();

        silentWorker($db)->run(true);

        $beat = json_decode((string) (new SettingRepository($db))->get(Worker::HEARTBEAT_KEY, ''), true);

        assertTrue(is_array($beat), 'отметка должна быть JSON-ом');
        assertTrue(($beat['worker'] ?? '') !== '', 'в отметке должен быть номер воркера');
        assertSame(getmypid(), (int) ($beat['pid'] ?? 0), 'в отметке должен быть pid процесса');
    });
});

test('запрошенный перезапуск выключает воркер', function (): void {
    withOwnDatabase(static function (Database $db): void {
        workerTransport();

        $settings = new SettingRepository($db);
        $id       = workerMessage('Письмо до перезапуска');
        $worker   = silentWorker($db);

        // Воркер выходит по просьбе, поданной после его старта: отметка секундой позже.
        // Просьба, поданная раньше, — это перезапуск, который уже случился
        $settings->set(Worker::RESTART_KEY, (string) (time() + 1));

        $processed = $worker->run(false);

        assertSame(0, $processed, 'воркер должен выйти до разбора очереди');

        $messages = new MessageRepository();
        assertSame('queued', (string) ((array) $messages->find($id))['status'], 'письмо остаётся в очереди');

        $settings->set(Worker::RESTART_KEY, '0');

        silentWorker($db)->run(true);

        assertSame(
            'sent',
            (string) ((array) $messages->find($id))['status'],
            'после снятия просьбы письмо должно уйти'
        );
    });
});

test('воркер не берёт письма, которым ещё рано', function (): void {
    withOwnDatabase(static function (Database $db): void {
        workerTransport();

        $id = workerMessage('Письмо на потом', ['send_at' => date('Y-m-d H:i:s', time() + 3600)]);

        silentWorker($db)->run(true);

        assertSame(
            'queued',
            (string) ((array) (new MessageRepository())->find($id))['status'],
            'отложенное письмо воркер трогать не должен'
        );
    });
});

test('воркер возвращает в очередь зависшие письма', function (): void {
    withOwnDatabase(static function (Database $db): void {
        workerTransport();

        $id = workerMessage('Письмо зависшего воркера');

        // Так выглядит письмо, которое взял и не довёл до конца упавший воркер
        $db->update(
            'messages',
            ['status' => 'sending', 'locked_at' => date('Y-m-d H:i:s', time() - 7200), 'locked_by' => 'умерший-воркер'],
            ['id' => $id]
        );

        silentWorker($db)->run(true);

        assertSame(
            'sent',
            (string) ((array) (new MessageRepository())->find($id))['status'],
            'зависшее письмо должно вернуться в очередь и уйти'
        );
    });
});

test('старые доставки вебхуков чистятся по сроку', function (): void {
    withOwnDatabase(static function (Database $db): void {
        $webhooks = new WebhookRepository($db);

        $old = $webhooks->enqueue([
            'uuid'       => Str::uuid(),
            'project_id' => 0,
            'url'        => 'http://127.0.0.1:9/hook',
            'event'      => Event::MESSAGE_SENT,
            'payload'    => ['data' => []],
        ]);

        // Разобранная доставка сорокадневной давности: чистят только такие
        $db->update(
            'webhook_deliveries',
            ['status' => 'sent', 'created_at' => date('Y-m-d H:i:s', strtotime('-40 days'))],
            ['id' => $old]
        );

        $fresh = $webhooks->enqueue([
            'uuid'       => Str::uuid(),
            'project_id' => 0,
            'url'        => 'http://127.0.0.1:9/hook',
            'event'      => Event::MESSAGE_SENT,
            'payload'    => ['data' => []],
        ]);

        assertSame(1, $webhooks->purge(30), 'должна удалиться только старая доставка');
        assertNull($db->selectOne('SELECT id FROM webhook_deliveries WHERE id = :id', ['id' => $old]));
        assertNotNull(
            $db->selectOne('SELECT id FROM webhook_deliveries WHERE id = :id', ['id' => $fresh]),
            'свежая доставка остаётся'
        );
    });
});
