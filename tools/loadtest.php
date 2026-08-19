<?php

declare(strict_types=1);

/**
 * Нагрузочные замеры сервиса. Отдельный инструмент для разработки, в боевой работе не нужен.
 *
 * Работать нужно на отдельной базе, боевую не трогаем:
 *
 *   set DB_SQLITE_PATH=%CD%/var/loadtest.sqlite
 *   php bin/mailer migrate
 *   php tools/loadtest.php prepare              данные для замеров: проект, транспорт, ключ
 *   php tools/loadtest.php fill 50000           наполнить базу письмами
 *   php tools/loadtest.php kernel               время обработки запроса без веб-сервера
 *   php tools/loadtest.php queries              сколько занимает каждый запрос панели
 *   php tools/loadtest.php worker 500           пропускная способность очереди
 *   php tools/loadtest.php http <адрес> <сценарий> [параллельность] [запросов]
 *
 * Сценарии http: api-accept, api-list, api-health, ui-dashboard, ui-messages, ui-search.
 */

require dirname(__DIR__) . '/bootstrap.php';

use Mailer\Http\ApiKernel;
use Mailer\Http\Request;
use Mailer\MailService;
use Mailer\Queue\Worker;
use Mailer\Repository\EventRepository;
use Mailer\Repository\MessageRepository;
use Mailer\Repository\ProjectRepository;
use Mailer\Repository\TransportRepository;
use Mailer\Storage\Database;
use Mailer\Support\Config;
use Mailer\Ui\UiKernel;

$command = $argv[1] ?? 'help';
$keyFile = MAILER_ROOT . '/var/loadtest.key';

/**
 * Замер: сколько в среднем занимает один вызов.
 */
function measure(string $label, int $times, callable $fn): void
{
    $fn();
    $started = microtime(true);

    for ($i = 0; $i < $times; $i++) {
        $fn();
    }

    $elapsed = microtime(true) - $started;

    printf("%-38s %7.2f мс   (%6.0f в секунду)\n", $label, $elapsed / $times * 1000, $times / $elapsed);
}

/**
 * Запрос для ядра — как будто пришёл из браузера.
 *
 * @param array<string, mixed>  $body
 * @param array<string, string> $headers
 */
function fakeRequest(string $method, string $path, array $body = [], array $headers = []): Request
{
    $request           = new Request();
    $request->method   = $method;
    $request->path     = $path;
    $request->query    = [];
    $request->headers  = $headers;
    $request->rawBody  = $body === [] ? '' : (string) json_encode($body);
    $request->body     = $body;

    return $request;
}

if ($command === 'prepare') {
    $transports = new TransportRepository();
    if ($transports->findByName('loadtest-null') === null) {
        $transports->create(['name' => 'loadtest-null', 'type' => 'null', 'settings' => [], 'active' => 1, 'is_default' => 1]);
    }

    $projects = new ProjectRepository();
    if ($projects->findByName('loadtest') === null) {
        $created = $projects->create(['name' => 'loadtest']);
        file_put_contents($keyFile, $created['key']);
    }

    echo 'готово, ключ проекта: ', trim((string) file_get_contents($keyFile)), "\n";

    exit(0);
}

if ($command === 'fill') {
    $target = (int) ($argv[2] ?? 10000);
    $db     = Database::instance();
    $have   = (int) $db->value('SELECT COUNT(*) FROM messages');
    $need   = max(0, $target - $have);

    if ($need === 0) {
        echo "в базе уже $have писем\n";

        exit(0);
    }

    $statuses = ['sent', 'sent', 'sent', 'failed', 'queued', 'canceled'];
    $started  = microtime(true);

    $db->transaction(static function (Database $db) use ($need, $statuses): void {
        for ($i = 0; $i < $need; $i++) {
            $moment = date('Y-m-d H:i:s', time() - random_int(0, 14 * 86400));
            $status = $statuses[$i % count($statuses)];

            $db->insert('messages', [
                'uuid'         => bin2hex(random_bytes(16)),
                'project_id'   => 1,
                'status'       => $status,
                'source'       => 'api',
                'from_email'   => 'noreply@example.com',
                'to_json'      => (string) json_encode([['email' => 'user' . $i . '@example.com']], JSON_UNESCAPED_UNICODE),
                'subject'      => 'Письмо для нагрузочной проверки №' . $i,
                'text_body'    => str_repeat('Текст письма. ', 20),
                'headers_json' => '{}',
                'attempts'     => $status === 'failed' ? 3 : 1,
                'max_attempts' => 5,
                'priority'     => 100,
                'size'         => 1024,
                'available_at' => $moment,
                'created_at'   => $moment,
                'updated_at'   => $moment,
                'sent_at'      => $status === 'sent' ? $moment : null,
                'tag'          => 'loadtest',
            ]);
        }
    });

    printf("добавлено %d писем за %.1f с, всего: %d\n", $need, microtime(true) - $started, (int) $db->value('SELECT COUNT(*) FROM messages'));

    exit(0);
}

if ($command === 'queries') {
    $messages = new MessageRepository();
    $events   = new EventRepository();
    $db       = Database::instance();

    echo 'писем в базе: ', (int) $db->value('SELECT COUNT(*) FROM messages'), "\n\n";

    measure('stats() — весь блок обзора', 20, static fn () => $messages->stats());
    measure('countByStatus()', 20, static fn () => $messages->countByStatus());
    measure('dailyStats(14) — график', 10, static fn () => $messages->dailyStats(14));
    measure('paginate() первая страница', 20, static fn () => $messages->paginate([], 1, 30));
    measure('paginate() со статусом', 20, static fn () => $messages->paginate(['status' => 'failed'], 1, 30));
    measure('paginate() с поиском', 10, static fn () => $messages->paginate(['search' => 'проверочной'], 1, 30));
    measure('events->latest(12)', 20, static fn () => $events->latest(12));

    exit(0);
}

if ($command === 'kernel') {
    Config::set('ui.auth', false);

    $key  = is_file($keyFile) ? trim((string) file_get_contents($keyFile)) : '';
    $auth = ['authorization' => 'Bearer ' . $key];
    $api  = new ApiKernel();
    $ui   = new UiKernel();

    measure('POST /api/v1/messages', 100, static fn () => $api->handle(fakeRequest('POST', '/api/v1/messages', [
        'to' => 'user@example.com', 'subject' => 'Замер', 'text' => 'Текст', 'transport' => 'loadtest-null',
    ], $auth)));

    measure('GET /api/v1/messages', 50, static fn () => $api->handle(fakeRequest('GET', '/api/v1/messages', [], $auth)));
    measure('GET /api/v1/health', 50, static fn () => $api->handle(fakeRequest('GET', '/api/v1/health')));
    measure('GET /ui — обзор', 30, static fn () => $ui->handle(fakeRequest('GET', '/ui')));
    measure('GET /ui/messages — список', 30, static fn () => $ui->handle(fakeRequest('GET', '/ui/messages')));
    measure('GET /ui/system — состояние', 30, static fn () => $ui->handle(fakeRequest('GET', '/ui/system')));

    exit(0);
}

if ($command === 'worker') {
    $count   = (int) ($argv[2] ?? 500);
    $service = new MailService();
    $started = microtime(true);

    for ($i = 0; $i < $count; $i++) {
        $service->accept([
            'to'        => 'user' . $i . '@example.com',
            'subject'   => 'Письмо для воркера №' . $i,
            'text'      => str_repeat('Текст. ', 30),
            'transport' => 'loadtest-null',
        ]);
    }

    $accept = microtime(true) - $started;
    printf("приём:    %4d писем за %5.2f с — %5.0f писем/с (%.1f мс на письмо)\n", $count, $accept, $count / $accept, $accept / $count * 1000);

    $started   = microtime(true);
    $processed = (new Worker(null, static function (string $line): void {
    }))->run(false, $count);
    $elapsed   = microtime(true) - $started;

    printf("отправка: %4d писем за %5.2f с — %5.0f писем/с (%.1f мс на письмо)\n", $processed, $elapsed, $processed / max($elapsed, 0.001), $elapsed / max($processed, 1) * 1000);

    exit(0);
}

if ($command === 'http') {
    $base        = $argv[2] ?? 'http://127.0.0.1:8210';
    $scenario    = $argv[3] ?? 'api-accept';
    $concurrency = (int) ($argv[4] ?? 1);
    $requests    = (int) ($argv[5] ?? 100);
    $key         = is_file($keyFile) ? trim((string) file_get_contents($keyFile)) : '';

    $payload = static fn (int $i): string => (string) json_encode([
        'to'        => 'user' . $i . '@example.com',
        'subject'   => 'Нагрузочное письмо №' . $i,
        'text'      => str_repeat('Строка письма для нагрузки. ', 10),
        'transport' => 'loadtest-null',
        'tag'       => 'loadtest',
    ], JSON_UNESCAPED_UNICODE);

    $scenarios = [
        'api-accept'   => static fn (int $i): array => [$base . '/api/v1/messages', $payload($i)],
        'api-list'     => static fn (int $i): array => [$base . '/api/v1/messages?per_page=30', null],
        'api-health'   => static fn (int $i): array => [$base . '/api/v1/health', null],
        'ui-dashboard' => static fn (int $i): array => [$base . '/ui/', null],
        'ui-messages'  => static fn (int $i): array => [$base . '/ui/messages', null],
        'ui-search'    => static fn (int $i): array => [$base . '/ui/messages?search=' . rawurlencode('нагрузочное'), null],
    ];

    if (!isset($scenarios[$scenario])) {
        exit('нет такого сценария: ' . $scenario . "\n");
    }

    $make    = $scenarios[$scenario];
    $multi   = curl_multi_init();
    $handles = [];
    $times   = [];
    $codes   = [];
    $sent    = 0;
    $done    = 0;
    $running = 0;
    $started = microtime(true);

    $add = static function (int $index) use ($multi, $make, $key, $scenario, &$handles): void {
        [$url, $post] = $make($index);

        $headers = ['Expect:'];
        if (str_starts_with($scenario, 'api') && $key !== '') {
            $headers[] = 'Authorization: Bearer ' . $key;
        }

        $ch = curl_init($url);

        if ($post !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        // На Windows у PHP нет своего списка корневых сертификатов — берём список от curl (путь в CURL_CA_BUNDLE)
        $bundle = getenv('CURL_CA_BUNDLE') ?: '';
        if ($bundle !== '' && is_file($bundle)) {
            curl_setopt($ch, CURLOPT_CAINFO, $bundle);
        }

        $handles[(int) $ch] = microtime(true);
        curl_multi_add_handle($multi, $ch);
    };

    for ($i = 0; $i < min($concurrency, $requests); $i++) {
        $add($sent++);
    }

    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 0.05);

        while ($info = curl_multi_info_read($multi)) {
            $handle  = $info['handle'];
            $times[] = (microtime(true) - ($handles[(int) $handle] ?? microtime(true))) * 1000;
            $codes[] = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $done++;

            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);

            if ($sent < $requests) {
                $add($sent++);
                curl_multi_exec($multi, $running);
            }
        }
    } while ($done < $requests);

    curl_multi_close($multi);
    sort($times);

    $elapsed = microtime(true) - $started;
    $at      = static fn (float $p): float => round($times[(int) floor((count($times) - 1) * $p)], 1);

    printf(
        "%-13s параллельно %2d   rps %6.1f   p50 %7.1f мс   p95 %7.1f мс   макс %7.1f мс   ответы %s\n",
        $scenario,
        $concurrency,
        $requests / max($elapsed, 0.001),
        $at(0.5),
        $at(0.95),
        round(max($times), 1),
        json_encode(array_count_values($codes))
    );

    exit(0);
}

echo file_get_contents(__FILE__, false, null, 0, 1200), "\n";
