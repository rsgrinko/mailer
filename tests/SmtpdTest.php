<?php

declare(strict_types=1);

/**
 * Локальный SMTP-релей: через него ходят приложения, которым нельзя править код,
 * а тестами он до сих пор не был закрыт вовсе.
 *
 * Релей поднимается отдельным процессом на своей базе-файле (SQLite): база
 * в памяти видна только своему подключению, а письмо должен увидеть и родитель.
 */

use Mailer\Storage\Database;

/**
 * Свободный порт на локальном адресе.
 */
function freePort(): int
{
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

    if ($socket === false) {
        skipTest('не удалось занять порт: ' . $error);
    }

    $name = (string) stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr($name, (int) strrpos($name, ':') + 1);
}

/**
 * Поднимает релей отдельным процессом на своей базе.
 *
 * @return array{port: int, db: Database, process: resource, pipes: array<int, resource>}
 */
function startSmtpd(string $login = '', string $password = ''): array
{
    if (!function_exists('proc_open')) {
        skipTest('proc_open выключен');
    }

    $path = testDatabaseFile('smtpd');
    $db   = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $path]]);
    $port = freePort();

    $command = [PHP_BINARY, MAILER_ROOT . '/tests/smtpd-run.php', $path, (string) $port, $login, $password];

    $pipes   = [];
    $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (!is_resource($process)) {
        skipTest('не удалось запустить релей');
    }

    // Первая строка печатается, когда порт уже занят
    $line = (string) fgets($pipes[1]);

    if (!str_contains($line, 'слушает')) {
        @proc_terminate($process);
        skipTest('релей не поднялся: ' . trim($line) . trim((string) stream_get_contents($pipes[2])));
    }

    return ['port' => $port, 'db' => $db, 'process' => $process, 'pipes' => $pipes];
}

/**
 * @param array{port: int, db: Database, process: resource, pipes: array<int, resource>} $smtpd
 */
function stopSmtpd(array $smtpd): void
{
    foreach ($smtpd['pipes'] as $pipe) {
        @fclose($pipe);
    }

    @proc_terminate($smtpd['process']);
    @proc_close($smtpd['process']);
}

/**
 * Разговор с релеем: отдаём команды по очереди и собираем ответы.
 *
 * @param  array<int, string> $commands
 * @return array<int, string>
 */
function smtpTalk(int $port, array $commands): array
{
    $client = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 5);

    if ($client === false) {
        throw new RuntimeException('не подключиться к релею: ' . $error);
    }

    stream_set_timeout($client, 5);

    $answers = ['greeting' => (string) fgets($client, 4096)];

    foreach ($commands as $command) {
        fwrite($client, $command . "\r\n");

        $answer = (string) fgets($client, 4096);

        // Многострочный ответ (EHLO) дочитываем до строки без дефиса
        while (strlen($answer) > 3 && $answer[3] === '-') {
            $answer = (string) fgets($client, 4096);
        }

        $answers[] = rtrim($answer, "\r\n");
    }

    fclose($client);

    return $answers;
}

test('письмо из релея попадает в очередь', function (): void {
    $smtpd = startSmtpd();

    try {
        $answers = smtpTalk($smtpd['port'], [
            'EHLO test',
            'MAIL FROM:<sender@example.com>',
            'RCPT TO:<user@example.com>',
            'DATA',
            "Subject: Письмо через релей\r\nFrom: sender@example.com\r\nTo: user@example.com\r\n\r\nТекст письма\r\n.",
            'QUIT',
        ]);

        assertContains('220', $answers['greeting'], 'релей должен здороваться');
        assertContains('250', $answers[0], 'EHLO не принят');
        assertContains('250 OK', $answers[1], 'MAIL FROM не принят');
        assertContains('250 OK', $answers[2], 'RCPT TO не принят');
        assertContains('354', $answers[3], 'релей должен ждать письмо');
        assertContains('250 OK: письмо принято', $answers[4], 'письмо не принято: ' . $answers[4]);
        assertContains('221', $answers[5]);

        $message = assertNotNull(
            $smtpd['db']->selectOne('SELECT * FROM messages ORDER BY id DESC LIMIT 1'),
            'письмо не легло в базу'
        );

        assertSame('Письмо через релей', (string) $message['subject']);
        assertSame('queued', (string) $message['status'], 'релей только ставит в очередь');
        assertSame('smtpd', (string) $message['source']);
        assertContains('user@example.com', (string) $message['to_json']);
        assertSame('sender@example.com', (string) $message['from_email']);
    } finally {
        stopSmtpd($smtpd);
    }
});

test('релей заводит свой проект для принятых писем', function (): void {
    $smtpd = startSmtpd();

    try {
        smtpTalk($smtpd['port'], [
            'HELO test',
            'MAIL FROM:<sender@example.com>',
            'RCPT TO:<user@example.com>',
            'DATA',
            "Subject: Проект релея\r\n\r\nтекст\r\n.",
            'QUIT',
        ]);

        $project = assertNotNull(
            $smtpd['db']->selectOne('SELECT * FROM projects WHERE name = :name', ['name' => 'smtpd-тест']),
            'релей должен завести проект под свои письма'
        );

        $message = (array) $smtpd['db']->selectOne('SELECT project_id FROM messages ORDER BY id DESC LIMIT 1');

        assertSame((int) $project['id'], (int) $message['project_id'], 'письмо должно принадлежать проекту релея');
    } finally {
        stopSmtpd($smtpd);
    }
});

test('релей ругается на неверный порядок команд', function (): void {
    $smtpd = startSmtpd();

    try {
        $answers = smtpTalk($smtpd['port'], [
            'EHLO test',
            'DATA',
            'ЧТО-ТО-НЕ-ТО',
            'RCPT TO:<>',
            'NOOP',
            'RSET',
            'VRFY user@example.com',
            'QUIT',
        ]);

        assertContains('503', $answers[1], 'DATA без получателей должен отбиваться');
        assertContains('500', $answers[2], 'неизвестная команда — 500');
        assertContains('501', $answers[3], 'пустой адрес получателя — 501');
        assertContains('250 OK', $answers[4]);
        assertContains('250 OK', $answers[5]);
        assertContains('252', $answers[6]);

        assertSame(
            0,
            (int) $smtpd['db']->selectOne('SELECT COUNT(*) AS c FROM messages')['c'],
            'ни одно письмо не должно было попасть в базу'
        );
    } finally {
        stopSmtpd($smtpd);
    }
});

test('слишком большое письмо релей не принимает', function (): void {
    $smtpd = startSmtpd();

    try {
        // В smtpd-run.php предел выставлен в 4 КБ
        $answers = smtpTalk($smtpd['port'], [
            'EHLO test',
            'MAIL FROM:<sender@example.com>',
            'RCPT TO:<user@example.com>',
            'DATA',
            "Subject: Большое\r\n\r\n" . str_repeat('строка письма ', 1000) . "\r\n.",
            'QUIT',
        ]);

        assertContains('552', $answers[4], 'письмо больше предела должно отбиваться');
        assertSame(
            0,
            (int) $smtpd['db']->selectOne('SELECT COUNT(*) AS c FROM messages')['c'],
            'большое письмо в базу попадать не должно'
        );
    } finally {
        stopSmtpd($smtpd);
    }
});

test('релей с логином не принимает письма без авторизации', function (): void {
    $smtpd = startSmtpd('relay-user', 'relay-pass');

    try {
        $answers = smtpTalk($smtpd['port'], [
            'EHLO test',
            'MAIL FROM:<sender@example.com>',
            'RCPT TO:<user@example.com>',
            'QUIT',
        ]);

        assertContains('530', $answers[1], 'без авторизации MAIL FROM не принимается');
        assertContains('530', $answers[2], 'без авторизации RCPT TO не принимается');
    } finally {
        stopSmtpd($smtpd);
    }
});

test('релей принимает письмо после AUTH LOGIN', function (): void {
    $smtpd = startSmtpd('relay-user', 'relay-pass');

    try {
        $answers = smtpTalk($smtpd['port'], [
            'EHLO test',
            'AUTH LOGIN',
            base64_encode('relay-user'),
            base64_encode('relay-pass'),
            'MAIL FROM:<sender@example.com>',
            'RCPT TO:<user@example.com>',
            'DATA',
            "Subject: После авторизации\r\n\r\nтекст\r\n.",
            'QUIT',
        ]);

        assertContains('235', $answers[3], 'авторизация не прошла: ' . $answers[3]);
        assertContains('250 OK', $answers[4], 'MAIL FROM после авторизации должен приниматься');
        assertContains('250 OK: письмо принято', $answers[7], 'письмо не принято: ' . $answers[7]);

        assertSame(
            'После авторизации',
            (string) ((array) $smtpd['db']->selectOne('SELECT subject FROM messages ORDER BY id DESC LIMIT 1'))['subject']
        );
    } finally {
        stopSmtpd($smtpd);
    }
});

test('неверный пароль релей не пропускает', function (): void {
    $smtpd = startSmtpd('relay-user', 'relay-pass');

    try {
        $answers = smtpTalk($smtpd['port'], [
            'EHLO test',
            'AUTH LOGIN',
            base64_encode('relay-user'),
            base64_encode('не-тот-пароль'),
            'MAIL FROM:<sender@example.com>',
            'QUIT',
        ]);

        assertContains('535', $answers[3], 'с неверным паролем должен быть отказ');
        assertContains('530', $answers[4], 'и письма после этого не принимаются');
    } finally {
        stopSmtpd($smtpd);
    }
});
