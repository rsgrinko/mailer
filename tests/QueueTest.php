<?php

declare(strict_types=1);

/**
 * Тесты очереди, отправки, лимитов и приёма писем из API.
 */

use Mailer\MailService;
use Mailer\Message\MessageFactory;
use Mailer\Queue\Queue;
use Mailer\Queue\Sender;
use Mailer\RateLimit\RateLimiter;
use Mailer\Repository\MessageRepository;
use Mailer\Repository\ProjectRepository;
use Mailer\Repository\TransportRepository;
use Mailer\Support\ValidationException;

/**
 * Транспорт-заглушка, который никуда не отправляет — на нём удобно проверять очередь.
 */
function testTransport(string $name = 'test-null'): array
{
    $transports = new TransportRepository();
    $existing   = $transports->findByName($name);

    if ($existing !== null) {
        return $existing;
    }

    $transports->create([
        'name'       => $name,
        'type'       => 'null',
        'settings'   => [],
        'from_email' => 'noreply@example.com',
        'from_name'  => 'Тест',
        'is_default' => true,
    ]);

    return (array) $transports->findByName($name);
}

test('письмо проходит путь от приёма до отправки', function (): void {
    testTransport();

    $service = new MailService();
    $result  = $service->accept([
        'to'      => 'user@example.com',
        'subject' => 'Проверка очереди',
        'text'    => 'Текст письма',
    ]);

    assertSame('queued', $result['status']);

    $messages = new MessageRepository();
    $row      = $messages->find((int) $result['id']);

    assertTrue($row !== null);
    assertSame('queued', (string) $row['status']);

    // Забираем письмо как воркер и отправляем
    $claimed = (new Queue())->claim(10, 'тестовый-воркер');
    assertTrue($claimed !== []);

    $sent = (new Sender())->send($claimed[0]);
    assertSame('sent', $sent['status']);

    $row = $messages->find((int) $result['id']);
    assertSame('sent', (string) $row['status']);
    assertSame('test-null', (string) $row['transport_used']);
});

test('одно и то же письмо не принимается дважды с одним ключом идемпотентности', function (): void {
    testTransport();

    $projects = new ProjectRepository();
    $project  = $projects->findOrCreate('тест-идемпотентность');

    $service = new MailService();
    $payload = [
        'to'              => 'user@example.com',
        'subject'         => 'Повтор',
        'text'            => 'Текст',
        'idempotency_key' => 'ключ-123',
    ];

    $first  = $service->accept($payload, $project);
    $second = $service->accept($payload, $project);

    assertSame($first['uuid'], $second['uuid']);
    assertTrue((bool) $second['duplicate']);
});

test('письмо без получателя не принимается', function (): void {
    $error = assertThrows(static function (): void {
        (new MessageFactory())->build(['subject' => 'Без адресата', 'text' => 'текст']);
    });

    assertTrue($error instanceof ValidationException);
    assertContains('получател', implode(' ', $error->errors()));
});

test('пустое письмо не принимается', function (): void {
    $error = assertThrows(static function (): void {
        (new MessageFactory())->build(['to' => 'user@example.com', 'subject' => 'Тема']);
    });

    assertTrue($error instanceof ValidationException);
});

test('отложенное письмо не выдаётся воркеру раньше времени', function (): void {
    testTransport();

    $result = (new MailService())->accept([
        'to'      => 'later@example.com',
        'subject' => 'Позже',
        'text'    => 'текст',
        'send_at' => date('Y-m-d H:i:s', time() + 3600),
    ]);

    foreach ((new Queue())->claim(50, 'воркер-2') as $row) {
        assertFalse((int) $row['id'] === (int) $result['id'], 'отложенное письмо не должно попадать в работу');
    }
});

test('отмена письма работает, отправленное отменить нельзя', function (): void {
    testTransport();

    $queue  = new Queue();
    $result = (new MailService())->accept([
        'to'      => 'cancel@example.com',
        'subject' => 'Отмена',
        'text'    => 'текст',
    ]);

    assertTrue($queue->cancel((int) $result['id']));
    assertFalse($queue->cancel((int) $result['id']), 'повторная отмена не должна проходить');
});

test('лимит проекта не даёт принять лишнее письмо', function (): void {
    testTransport();

    $projects = new ProjectRepository();
    $created  = $projects->create(['name' => 'проект-с-лимитом', 'rate_limit_hour' => 1]);
    $project  = $created['project'];

    $service = new MailService();
    $payload = ['to' => 'limit@example.com', 'subject' => 'Лимит', 'text' => 'текст'];

    $service->accept($payload, $project);

    $error = assertThrows(static fn () => $service->accept($payload, $project));
    assertContains('лимит', mb_strtolower($error->getMessage()));
});

test('счётчик лимитов увеличивается', function (): void {
    $limiter = new RateLimiter();

    $before = $limiter->projectUsage(999)['day'];
    $limiter->hitProject(999);
    $after = $limiter->projectUsage(999)['day'];

    assertSame($before + 1, $after);
});

test('готовое письмо из sendmail принимается вместе с конвертом', function (): void {
    testTransport();

    $raw = "From: app@example.com\r\nTo: dest@example.com\r\nSubject: Из sendmail\r\n\r\nтело";

    $result = (new MailService())->accept(
        ['raw' => $raw, 'envelope_from' => 'app@example.com', 'envelope_to' => ['dest@example.com']],
        null,
        MessageRepository::SOURCE_SENDMAIL
    );

    $row = (new MessageRepository())->find((int) $result['id']);

    assertSame('sendmail', (string) $row['source']);
    assertContains('Из sendmail', (string) $row['subject']);
    assertContains('dest@example.com', (string) $row['envelope_to']);
});

test('отправленное письмо нельзя ни повторить, ни отменить', function (): void {
    testTransport();

    $service  = new MailService();
    $messages = new MessageRepository();
    $queue    = new Queue();

    $result = $service->accept([
        'to'      => 'done@example.com',
        'subject' => 'Уже ушло',
        'text'    => 'текст',
        'sync'    => true,
    ]);

    assertSame(MessageRepository::SENT, (string) $messages->find((int) $result['id'])['status']);

    // Повтор отправил бы получателю дубль, отмена соврала бы о судьбе письма
    assertFalse($queue->retry((int) $result['id']), 'повтор отправленного письма недопустим');
    assertFalse($queue->cancel((int) $result['id']), 'отмена отправленного письма недопустима');

    assertSame(MessageRepository::SENT, (string) $messages->find((int) $result['id'])['status']);
});

test('повтор неудачного письма стирает прежнюю отметку об отправке', function (): void {
    testTransport();

    $messages = new MessageRepository();
    $result   = (new MailService())->accept([
        'to'      => 'retry@example.com',
        'subject' => 'Повтор',
        'text'    => 'текст',
    ]);

    $id = (int) $result['id'];
    $messages->update($id, ['status' => MessageRepository::FAILED, 'sent_at' => '2026-01-01 10:00:00']);

    assertTrue((new Queue())->retry($id));

    $row = $messages->find($id);
    assertSame(MessageRepository::QUEUED, (string) $row['status']);
    assertTrue($row['sent_at'] === null, 'отметка об отправке должна быть очищена');
});

test('ошибка отправки записывается транспорту', function (): void {
    $transports = new TransportRepository();

    // sendmail с несуществующим путём падает сразу и без сети
    if ($transports->findByName('test-broken') === null) {
        $transports->create([
            'name'       => 'test-broken',
            'type'       => 'sendmail',
            'settings'   => ['path' => '/нет/такого/sendmail'],
            'from_email' => 'noreply@example.com',
        ]);
    }

    $result = (new MailService())->accept([
        'to'        => 'user@example.com',
        'subject'   => 'Письмо в сломанный транспорт',
        'text'      => 'текст',
        'transport' => 'test-broken',
    ]);

    $id  = (int) $result['id'];
    $row = (new MessageRepository())->find($id);

    $sent = (new Sender())->send((array) $row);
    assertSame(MessageRepository::FAILED, $sent['status']);

    // Раньше ошибка оставалась только у письма, и в панели транспорт выглядел здоровым
    $transport = (array) $transports->findByName('test-broken');
    assertTrue(
        str_contains((string) $transport['last_error'], 'sendmail'),
        'у транспорта должна остаться последняя ошибка, а там: ' . var_export($transport['last_error'], true)
    );
});
