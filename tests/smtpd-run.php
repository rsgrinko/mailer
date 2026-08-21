<?php

declare(strict_types=1);

/**
 * SMTP-релей отдельным процессом — для тестов.
 *
 * Запуск: php tests/smtpd-run.php <путь-к-sqlite> <порт> [логин] [пароль]
 *
 * База задаётся явно и только SQLite: настройки DB_* из .env здесь не годятся,
 * релей пишет принятые письма в базу, а тестам нужна своя.
 */

require dirname(__DIR__) . '/bootstrap.php';

use Mailer\Smtpd\SmtpServer;
use Mailer\Storage\Database;
use Mailer\Support\Config;

$path = $argv[1] ?? '';
$port = (int) ($argv[2] ?? 0);

if ($path === '' || $port <= 0) {
    fwrite(STDERR, 'нужны путь к базе и порт' . PHP_EOL);

    exit(1);
}

Config::set('db.driver', 'sqlite');
Config::set('db.sqlite.path', $path);
Config::set('log.level', 'error');
Config::set('smtpd.project', 'smtpd-тест');
Config::set('smtpd.max_size', 4096);
Config::set('smtpd.auth_user', $argv[3] ?? '');
Config::set('smtpd.auth_password', $argv[4] ?? '');

Database::setInstance(new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $path]]));

$server = new SmtpServer('127.0.0.1', $port, static function (string $line): void {
    echo $line . PHP_EOL;
    flush();
});

$server->run();
