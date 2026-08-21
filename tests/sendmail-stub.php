<?php

declare(strict_types=1);

/**
 * Подставной sendmail для тестов: пишет свои аргументы и всё, что пришло на вход,
 * в файл, путь к которому передан первым аргументом.
 *
 * Настоящий sendmail на машине разработчика может отсутствовать, а на Windows его
 * нет вовсе — поэтому транспорт проверяется на этой заглушке.
 */

$out = $argv[1] ?? '';

if ($out === '') {
    fwrite(STDERR, 'не указан файл для записи' . PHP_EOL);

    exit(1);
}

$arguments = implode(' ', array_slice($argv, 2));
$input     = (string) file_get_contents('php://stdin');

file_put_contents($out, $arguments . PHP_EOL . $input);

exit(0);
