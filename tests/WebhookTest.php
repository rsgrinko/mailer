<?php

declare(strict_types=1);

/**
 * Вебхуки: подписки, конверт и доставка.
 *
 * Доставка проверяется на игрушечном приёмнике (tests/webhook-stub.php) — он пишет
 * в журнал всё, что получил, и по этому журналу видно и заголовки, и подпись.
 * Настоящая сеть наружу при этом не нужна.
 */

use Mailer\Repository\ProjectRepository;
use Mailer\Repository\WebhookRepository;
use Mailer\Repository\WebhookSubscriptionRepository;
use Mailer\Security\Crypto;
use Mailer\Storage\Database;
use Mailer\Support\Config;
use Mailer\Webhook\Dispatcher;
use Mailer\Webhook\Event;
use Mailer\Webhook\Payload;
use Mailer\Webhook\WebhookSender;

/**
 * Проект и письмо, от лица которых шлём события.
 *
 * @return array{project: array<string, mixed>, message: array<string, mixed>}
 */
function webhookFixtures(): array
{
    static $fixtures = null;

    if ($fixtures !== null) {
        return $fixtures;
    }

    $created = (new ProjectRepository())->create(['name' => 'вебхук-тест']);

    $accepted = (new Mailer\MailService())->accept([
        'to'      => 'user@example.com',
        'subject' => 'Письмо для вебхука',
        'text'    => 'Текст',
        'sync'    => false,
    ], $created['project']);

    $message = (array) (new Mailer\Repository\MessageRepository())->find((int) $accepted['id']);

    // Письмо в очереди мешало бы соседним тестам — оно нам нужно только строкой
    (new Mailer\Queue\Queue())->cancel((int) $message['id'], 'Тестовое письмо, отправлять не нужно');

    $fixtures = ['project' => $created['project'], 'message' => $message];

    return $fixtures;
}

/**
 * Заводит подписку и убирает за собой чужие: каждый тест начинает с чистого листа.
 *
 * @param array<string, mixed> $data
 */
function webhookSubscription(array $data = []): int
{
    $ids  = webhookFixtures();
    $repo = new WebhookSubscriptionRepository();

    foreach ($repo->forProject((int) $ids['project']['id']) as $existing) {
        $repo->delete((int) $existing['id']);
    }

    return $repo->create(array_merge([
        'project_id' => (int) $ids['project']['id'],
        'url'        => 'http://127.0.0.1:9/hook',
    ], $data));
}

/**
 * Доставки, поставленные в очередь по этой подписке.
 *
 * @return array<int, array<string, mixed>>
 */
function webhookDeliveries(int $subscriptionId): array
{
    return (new WebhookRepository())->paginate(['subscription_id' => $subscriptionId], 1, 50)['items'];
}

/**
 * Поднимает игрушечный приёмник отдельным процессом.
 *
 * @return array{port: int, log: string, process: resource, pipes: array<int, resource>}
 */
function startWebhookStub(int $status = 200, int $requests = 1): array
{
    if (!function_exists('proc_open')) {
        skipTest('proc_open выключен');
    }

    $log     = (string) tempnam(sys_get_temp_dir(), 'webhook-stub-');
    $command = [PHP_BINARY, MAILER_ROOT . '/tests/webhook-stub.php', $log, '--status=' . $status, '--requests=' . $requests];

    $pipes   = [];
    $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (!is_resource($process)) {
        @unlink($log);
        skipTest('не удалось запустить приёмник вебхуков');
    }

    $port = (int) trim((string) fgets($pipes[1]));

    if ($port <= 0) {
        stopWebhookStub(['process' => $process, 'pipes' => $pipes, 'log' => $log, 'port' => 0]);
        skipTest('приёмник вебхуков не занял порт');
    }

    return ['port' => $port, 'log' => $log, 'process' => $process, 'pipes' => $pipes];
}

/**
 * @param array{port: int, log: string, process: resource, pipes: array<int, resource>} $stub
 */
function stopWebhookStub(array $stub): string
{
    $log = is_file($stub['log']) ? (string) file_get_contents($stub['log']) : '';

    foreach ($stub['pipes'] as $pipe) {
        @fclose($pipe);
    }

    @proc_terminate($stub['process']);
    @proc_close($stub['process']);
    @unlink($stub['log']);

    return $log;
}

test('событие уходит всем подписчикам, которые его ждут', function (): void {
    $ids  = webhookFixtures();
    $repo = new WebhookSubscriptionRepository();

    $all = webhookSubscription(['name' => 'на всё']);
    $one = $repo->create([
        'project_id' => (int) $ids['project']['id'],
        'name'       => 'только отправленные',
        'url'        => 'http://127.0.0.1:9/only-sent',
        'events'     => [Event::MESSAGE_SENT],
    ]);

    $dispatcher = new Dispatcher();

    assertSame(2, $dispatcher->message(Event::MESSAGE_SENT, $ids['message']), 'ждут оба');
    assertSame(1, $dispatcher->message(Event::MESSAGE_FAILED, $ids['message']), 'ошибку ждёт только первый');

    assertSame(2, count(webhookDeliveries($all)));
    assertSame(1, count(webhookDeliveries($one)));

    $repo->delete($one);
});

test('выключенная подписка ничего не получает', function (): void {
    $ids = webhookFixtures();
    $id  = webhookSubscription(['active' => false]);

    assertSame(0, (new Dispatcher())->message(Event::MESSAGE_SENT, $ids['message']));
    assertSame(0, count(webhookDeliveries($id)));
});

test('тело события — конверт с письмом внутри', function (): void {
    $ids = webhookFixtures();
    $id  = webhookSubscription();

    (new Dispatcher())->message(Event::MESSAGE_SENT, $ids['message'], ['info' => 'ушло']);

    $delivery = webhookDeliveries($id)[0];
    $payload  = (array) json_decode((string) $delivery['payload'], true);

    assertSame($delivery['uuid'], $payload['id'], 'идентификатор доставки виден и в теле');
    assertSame(Event::MESSAGE_SENT, $payload['event']);
    assertTrue(isset($payload['occurred_at']), 'время события обязательно');
    assertSame((int) $ids['project']['id'], $payload['project']['id']);
    assertSame((string) $ids['message']['uuid'], $payload['data']['message']['id']);
    assertSame(['user@example.com'], $payload['data']['message']['to']);
    assertSame('ушло', $payload['data']['info']);
});

test('старой подписке приходит прежний плоский формат', function (): void {
    $ids = webhookFixtures();
    $id  = webhookSubscription(['payload_version' => Payload::V1]);

    $dispatcher = new Dispatcher();

    // Событий первой версии всего два, остальные до неё не доходят
    assertSame(0, $dispatcher->message(Event::MESSAGE_QUEUED, $ids['message']));
    assertSame(1, $dispatcher->message(Event::MESSAGE_SENT, $ids['message']));

    $delivery = webhookDeliveries($id)[0];
    $payload  = (array) json_decode((string) $delivery['payload'], true);

    assertSame('sent', $delivery['event'], 'имя события тоже прежнее');
    assertSame('sent', $payload['event']);
    assertSame((string) $ids['message']['uuid'], $payload['message_id']);
    assertTrue(!isset($payload['data']), 'конверта во второй версии тут быть не должно');
});

test('доставка подписывает тело и сохраняет ответ', function (): void {
    $ids  = webhookFixtures();
    $stub = startWebhookStub();

    $id = webhookSubscription([
        'url'    => 'http://127.0.0.1:' . $stub['port'] . '/hook',
        'secret' => 'секрет-для-подписи',
    ]);

    (new Dispatcher())->message(Event::MESSAGE_SENT, $ids['message']);
    $delivery = webhookDeliveries($id)[0];

    try {
        $ok = (new WebhookSender())->deliver($delivery);
    } finally {
        $log = stopWebhookStub($stub);
    }

    assertTrue($ok, 'приёмник ответил 200');
    assertContains('X-Mailer-Event: ' . Event::MESSAGE_SENT, $log);
    assertContains('X-Mailer-Delivery: ' . (string) $delivery['uuid'], $log);
    assertContains('X-Mailer-Attempt: 1', $log);

    // Подпись считается от «время.тело» — иначе перехваченный запрос можно переиграть
    assertTrue(preg_match('/X-Mailer-Signature: t=(\d+),v1=([0-9a-f]{64})/', $log, $m) === 1, 'подписи нет в заголовках');
    assertTrue(preg_match('/X-Mailer-Timestamp: (\d+)/', $log, $t) === 1);
    assertSame($t[1], $m[1], 'время в подписи и в заголовке должно совпадать');

    $signed = hash_hmac('sha256', $m[1] . '.' . (string) $delivery['payload'], 'секрет-для-подписи');
    assertSame($signed, $m[2], 'подпись не сошлась');

    $stored = (new WebhookRepository())->find((int) $delivery['id']);
    assertSame(WebhookRepository::DELIVERED, $stored['status']);
    assertSame(200, (int) $stored['response_code']);
    assertContains('{"ok":true}', (string) $stored['response_body'], 'ответ сервера должен сохраниться целиком');
    assertContains('X-Mailer-Signature', (string) $stored['request_headers']);
});

test('после отказа доставка ждёт повтора, а подписка считает неудачи', function (): void {
    $ids  = webhookFixtures();
    $stub = startWebhookStub(500);

    $id = webhookSubscription(['url' => 'http://127.0.0.1:' . $stub['port'] . '/hook']);

    (new Dispatcher())->message(Event::MESSAGE_SENT, $ids['message']);
    $delivery = webhookDeliveries($id)[0];

    try {
        $ok = (new WebhookSender())->deliver($delivery);
    } finally {
        stopWebhookStub($stub);
    }

    assertFalse($ok);

    $stored = (new WebhookRepository())->find((int) $delivery['id']);
    assertSame(WebhookRepository::QUEUED, $stored['status'], 'попытки ещё остались');
    assertSame(1, (int) $stored['attempts']);
    assertSame(500, (int) $stored['response_code']);
    assertContains('так вышло', (string) $stored['response_body']);
    assertTrue((string) $stored['available_at'] > Database::now(), 'повтор назначается на будущее');

    $subscription = (new WebhookSubscriptionRepository())->find($id);
    assertSame(1, (int) $subscription['failures']);
    assertSame('failed', (string) $subscription['last_status']);
});

test('проверка связи уходит без письма и возвращает ответ сервера', function (): void {
    $ids  = webhookFixtures();
    $stub = startWebhookStub();

    $id           = webhookSubscription(['url' => 'http://127.0.0.1:' . $stub['port'] . '/hook']);
    $subscription = (array) (new WebhookSubscriptionRepository())->find($id);

    $deliveryId = (new Dispatcher())->ping($subscription, $ids['project']);
    $delivery   = (array) (new WebhookRepository())->find($deliveryId);

    try {
        $ok = (new WebhookSender())->deliver($delivery);
    } finally {
        $log = stopWebhookStub($stub);
    }

    assertTrue($ok);
    assertSame(null, $delivery['message_id'], 'за проверкой связи письма нет');
    assertContains('X-Mailer-Event: ' . Event::PING, $log);

    $payload = (array) json_decode((string) $delivery['payload'], true);
    assertSame(Event::PING, $payload['event']);
    assertTrue(!isset($payload['data']['message']), 'письма в теле быть не должно');
});

test('секрет подписки лежит в базе зашифрованным', function (): void {
    Config::set('app.key', Crypto::generateKey());

    $id  = webhookSubscription(['secret' => 'очень-секретно']);
    $row = Database::instance()->selectOne('SELECT secret FROM project_webhooks WHERE id = :id', ['id' => $id]);

    assertNotContains('очень-секретно', (string) $row['secret'], 'секрет не должен лежать открытым');
    assertSame('очень-секретно', WebhookSubscriptionRepository::secret((array) (new WebhookSubscriptionRepository())->find($id)));
});

test('прибираем за собой вебхуки проверок', function (): void {
    $ids  = webhookFixtures();
    $repo = new WebhookSubscriptionRepository();
    $db   = Database::instance();

    foreach ($repo->forProject((int) $ids['project']['id']) as $subscription) {
        $db->delete('webhook_deliveries', ['subscription_id' => (int) $subscription['id']]);
        $repo->delete((int) $subscription['id']);
    }

    assertSame(0, count($repo->forProject((int) $ids['project']['id'])));
});
