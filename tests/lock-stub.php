<?php

declare(strict_types=1);

/**
 * Второй процесс для проверки блокировки: берёт её и держит, пока жив файл-метка.
 *
 * Запуск: php tests/lock-stub.php <имя-блокировки> <файл-метка>
 * Печатает «held», когда блокировка взята, и «released» — когда отпустил.
 */

require dirname(__DIR__) . '/bootstrap.php';

use Mailer\Storage\Database;
use Mailer\Storage\Lock;

$name   = $argv[1] ?? 'mailer_test_lock';
$marker = $argv[2] ?? '';

// Своя база в памяти: процессу нужна только SQLite-ветка блокировки, а не данные
$lock = new Lock(new Database(['driver' => 'sqlite', 'sqlite' => ['path' => ':memory:']]), $name);

if (!$lock->acquire(5)) {
    echo 'busy' . PHP_EOL;

    exit(1);
}

echo 'held' . PHP_EOL;
flush();

// Держим, пока метка на месте, но не дольше минуты — иначе процесс останется висеть
$deadline = time() + 60;

while ($marker !== '' && time() < $deadline) {
    // Без сброса кэша PHP помнит старый ответ stat и метку «файла больше нет» не увидит
    clearstatcache(true, $marker);

    if (!is_file($marker)) {
        break;
    }

    usleep(50000);
}

$lock->release();

echo 'released' . PHP_EOL;
flush();
