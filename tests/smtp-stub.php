<?php

declare(strict_types=1);

/**
 * Игрушечный SMTP-сервер для tests/SmtpSessionTest.php.
 *
 * Запускается отдельным процессом, печатает в stdout занятый порт и пишет в журнал
 * всё, что с ним делали: connect, сами команды, disconnect. По журналу тест и видит,
 * переиспользовал транспорт соединение или подключался заново.
 *
 * Запуск: php tests/smtp-stub.php <файл журнала> [--drop-after=N]
 * С --drop-after=N сервер молча рвёт связь после N-го письма — так проверяется,
 * что следующая отправка поднимет соединение сама.
 */

$log       = $argv[1] ?? '';
$dropAfter = 0;

foreach (array_slice($argv, 2) as $argument) {
    if (str_starts_with($argument, '--drop-after=')) {
        $dropAfter = (int) substr($argument, 13);
    }
}

if ($log === '') {
    fwrite(STDERR, 'Не указан файл журнала' . PHP_EOL);

    exit(1);
}

$errorNo = 0;
$error   = '';
$server  = @stream_socket_server('tcp://127.0.0.1:0', $errorNo, $error);

if ($server === false) {
    fwrite(STDERR, $error !== '' ? $error : 'не удалось занять порт');

    exit(1);
}

$address = (string) stream_socket_get_name($server, false);

echo substr($address, (int) strrpos($address, ':') + 1) . PHP_EOL;

$note = static function (string $line) use ($log): void {
    file_put_contents($log, $line . PHP_EOL, FILE_APPEND);
};

$delivered = 0;
// Тест давно закончился, а мы всё слушаем — так висеть незачем
$deadline = time() + 30;

while (time() < $deadline) {
    $client = @stream_socket_accept($server, 5);

    if ($client === false) {
        continue;
    }

    $note('connect');
    fwrite($client, "220 stub ESMTP\r\n");

    while (($line = fgets($client, 4096)) !== false) {
        $line    = trim($line);
        $command = strtoupper(strtok($line, ' ') ?: '');

        $note($line);

        if ($command === 'EHLO' || $command === 'HELO') {
            fwrite($client, "250-stub\r\n250 OK\r\n");

            continue;
        }

        if ($command === 'MAIL' || $command === 'RCPT' || $command === 'RSET' || $command === 'NOOP') {
            fwrite($client, "250 OK\r\n");

            continue;
        }

        if ($command === 'DATA') {
            fwrite($client, "354 Давайте письмо\r\n");

            while (($chunk = fgets($client, 8192)) !== false) {
                if (rtrim($chunk, "\r\n") === '.') {
                    break;
                }
            }

            $delivered++;
            fwrite($client, '250 OK письмо ' . $delivered . "\r\n");

            // Обрыв со стороны сервера: клиент узнает о нём только на следующей команде
            if ($dropAfter > 0 && $delivered >= $dropAfter) {
                $note('drop');

                break;
            }

            continue;
        }

        if ($command === 'QUIT') {
            fwrite($client, "221 Пока\r\n");

            break;
        }

        fwrite($client, "500 Не понял\r\n");
    }

    $note('disconnect');
    fclose($client);
}

fclose($server);
