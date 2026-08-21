<?php

declare(strict_types=1);

/**
 * Удалённый sendmail-шим (integrations/sendmail): скрипт на чужом сервере
 * читает письмо со stdin и отдаёт его сервису по HTTP.
 *
 * Проверяем не «скрипт не падает», а всю цепочку: поднимаем настоящий сервис
 * отдельным процессом на своей базе-файле, скармливаем шиму письмо и смотрим,
 * что оно легло в очередь разобранным. Наружу ничего не уходит — транспорт null.
 */

use Mailer\Repository\ProjectRepository;
use Mailer\Repository\TransportRepository;
use Mailer\Storage\Database;
use Mailer\Storage\Migrator;

/**
 * Поднимает сервис на своей базе и заводит проект с ключом.
 *
 * @return array{url: string, key: string, db: Database, process: resource, pipes: array<int, resource>}
 */
function startMailerService(): array
{
    if (!function_exists('proc_open')) {
        skipTest('proc_open выключен');
    }

    $path = testDatabaseFile('shim');
    $db   = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $path]]);
    $port = freePort();

    // Проект с ключом и транспорт-заглушку заводим до старта сервера
    $previous = Database::instance();
    Database::setInstance($db);

    try {
        (new Migrator())->run();

        (new TransportRepository())->create([
            'name'       => 'шим-null',
            'type'       => 'null',
            'settings'   => [],
            'from_email' => 'noreply@example.com',
            'is_default' => true,
        ]);

        $key = (new ProjectRepository())->create(['name' => 'шим-проект'])['key'];
    } finally {
        Database::setInstance($previous);
    }

    $environment = [
        'DB_DRIVER'      => 'sqlite',
        'DB_SQLITE_PATH' => $path,
        'UI_AUTH'        => 'false',
        'LOG_LEVEL'      => 'error',
        'PATH'           => (string) getenv('PATH'),
        'SystemRoot'     => (string) getenv('SystemRoot'),
    ];

    $pipes   = [];
    $process = @proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', MAILER_ROOT . '/public'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        MAILER_ROOT,
        $environment
    );

    if (!is_resource($process)) {
        skipTest('не удалось запустить сервис');
    }

    // Ждём, пока встроенный сервер займёт порт
    $deadline = microtime(true) + 5;

    while (microtime(true) < $deadline) {
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 1);

        if (is_resource($socket)) {
            fclose($socket);

            return [
                'url'     => 'http://127.0.0.1:' . $port,
                'key'     => $key,
                'db'      => $db,
                'process' => $process,
                'pipes'   => $pipes,
            ];
        }

        usleep(100000);
    }

    @proc_terminate($process);
    skipTest('сервис не поднялся на порту ' . $port);
}

/**
 * @param array{process: resource, pipes: array<int, resource>} $service
 */
function stopMailerService(array $service): void
{
    foreach ($service['pipes'] as $pipe) {
        @fclose($pipe);
    }

    @proc_terminate($service['process']);
    @proc_close($service['process']);
}

/**
 * Запускает шим с письмом на входе.
 *
 * @param  array<int, string>    $arguments
 * @param  array<string, string> $config настройки шима (идут окружением)
 * @return array{code: int, stdout: string, stderr: string}
 */
function runShim(array $command, array $arguments, string $letter, array $config): array
{
    $environment = array_merge([
        'PATH'        => (string) getenv('PATH'),
        'SystemRoot'  => (string) getenv('SystemRoot'),
        'MAILER_CONF' => 'нет-такого-файла',
    ], $config);

    $pipes   = [];
    $process = @proc_open(
        array_merge($command, $arguments),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        MAILER_ROOT,
        $environment
    );

    if (!is_resource($process)) {
        skipTest('не удалось запустить шим');
    }

    fwrite($pipes[0], $letter);
    fclose($pipes[0]);

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

/**
 * Письмо, как его отдаёт приложение.
 */
function shimLetter(string $subject = 'Письмо через шим'): string
{
    return implode("\r\n", [
        'From: robot@example.ru',
        'To: user@example.com',
        'Subject: ' . $subject,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=utf-8',
        '',
        'Текст письма с "кавычками", обратным слэшем \\ и табуляцией' . "\t" . 'внутри.',
        '',
    ]);
}

/**
 * Команды запуска обоих шимов: PHP всегда, shell — если есть sh и curl.
 *
 * @return array<string, array<int, string>>
 */
function shimCommands(): array
{
    $commands = ['php' => [PHP_BINARY, MAILER_ROOT . '/integrations/sendmail/mailer-sendmail.php']];

    // Shell-вариант гоняем только там, где есть и sh, и curl: на Windows и в голом
    // php-образе их может не быть, и это не повод считать тест упавшим
    $null  = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    $found = trim((string) @shell_exec('sh -c "command -v curl" 2>' . $null));

    if ($found !== '') {
        $commands['shell'] = ['sh', MAILER_ROOT . '/integrations/sendmail/mailer-sendmail'];
    }

    return $commands;
}

test('шим доносит письмо до сервиса', function (): void {
    $service = startMailerService();

    try {
        foreach (shimCommands() as $kind => $command) {
            $result = runShim($command, ['-t', '-i', '-frobot@example.ru'], shimLetter('Письмо из ' . $kind), [
                'MAILER_URL' => $service['url'],
                'MAILER_KEY' => $service['key'],
                'MAILER_TAG' => 'shim-' . $kind,
            ]);

            assertSame(0, $result['code'], 'шим ' . $kind . ' должен отработать: ' . $result['stderr']);

            $row = $service['db']->selectOne(
                'SELECT * FROM messages WHERE subject = :subject',
                ['subject' => 'Письмо из ' . $kind]
            );

            $row = assertNotNull($row, 'письмо от шима ' . $kind . ' не дошло до сервиса');

            assertSame('queued', (string) $row['status'], 'письмо должно встать в очередь');
            assertSame('api', (string) $row['source'], 'шим ходит через API');
            assertContains('user@example.com', (string) $row['to_json'], 'получатель разобран из заголовков');
            assertSame('robot@example.ru', (string) $row['from_email']);
            assertContains('shim-' . $kind, (string) $row['tag'], 'метка из настроек должна проставиться');

            // Тело доехало без потерь: кавычки, слэш и табуляция переживают упаковку в JSON
            $text = (string) $row['text_body'];

            assertContains('кавычками', $text, 'текст письма должен дойти целиком');
            assertContains('\\', $text, 'обратный слэш не должен потеряться');
            assertContains("\t", $text, 'табуляция не должна потеряться');
        }
    } finally {
        stopMailerService($service);
    }
});

test('шим передаёт получателей из аргументов', function (): void {
    $service = startMailerService();

    try {
        foreach (shimCommands() as $kind => $command) {
            $letter = "Subject: Конверт из аргументов " . $kind . "\r\nFrom: robot@example.ru\r\n\r\nтело\r\n";

            $result = runShim($command, ['-i', '-frobot@example.ru', 'envelope@example.com'], $letter, [
                'MAILER_URL' => $service['url'],
                'MAILER_KEY' => $service['key'],
            ]);

            assertSame(0, $result['code'], 'шим ' . $kind . ': ' . $result['stderr']);

            $row = assertNotNull(
                $service['db']->selectOne(
                    'SELECT * FROM messages WHERE subject = :subject',
                    ['subject' => 'Конверт из аргументов ' . $kind]
                ),
                'письмо от шима ' . $kind . ' не дошло'
            );

            assertContains('envelope@example.com', (string) $row['envelope_to'], 'адрес из аргумента — в конверте');
        }
    } finally {
        stopMailerService($service);
    }
});

test('без настроек шим отвечает «не настроено»', function (): void {
    foreach (shimCommands() as $kind => $command) {
        $result = runShim($command, ['-t'], shimLetter(), ['MAILER_URL' => '', 'MAILER_KEY' => '']);

        assertSame(78, $result['code'], 'шим ' . $kind . ' должен отвечать кодом 78');
        assertContains('не настроено', $result['stderr']);
    }
});

test('пустой ввод шим не отправляет', function (): void {
    foreach (shimCommands() as $kind => $command) {
        $result = runShim($command, ['-t'], '', [
            'MAILER_URL' => 'http://127.0.0.1:9',
            'MAILER_KEY' => 'mlr_нет_ключа',
        ]);

        assertSame(64, $result['code'], 'шим ' . $kind . ' должен ругаться на пустое письмо');
    }
});

test('недоступный сервис — временная ошибка, а не потеря письма', function (): void {
    foreach (shimCommands() as $kind => $command) {
        // Порт 9 — discard, туда никто не отвечает
        $result = runShim($command, ['-t'], shimLetter(), [
            'MAILER_URL'     => 'http://127.0.0.1:9',
            'MAILER_KEY'     => 'mlr_ключ_ключ',
            'MAILER_TIMEOUT' => '1',
        ]);

        assertSame(75, $result['code'], 'шим ' . $kind . ' должен вернуть 75, чтобы письмо повторили');
    }
});

test('неверный ключ — отдельный код выхода', function (): void {
    $service = startMailerService();

    try {
        foreach (shimCommands() as $kind => $command) {
            $result = runShim($command, ['-t'], shimLetter(), [
                'MAILER_URL' => $service['url'],
                'MAILER_KEY' => 'mlr_chuzhoy_klyuch',
            ]);

            assertSame(77, $result['code'], 'шим ' . $kind . ' должен отличать чужой ключ: ' . $result['stderr']);
        }
    } finally {
        stopMailerService($service);
    }
});
