<?php

declare(strict_types=1);

/**
 * Игрушечный приёмник вебхуков для tests/WebhookTest.php.
 *
 * Запускается отдельным процессом, печатает в stdout занятый порт и складывает
 * каждый пришедший запрос в журнал: сначала заголовки, потом тело, потом строка
 * «--конец--». По журналу тест и проверяет заголовки с подписью.
 *
 * Запуск: php tests/webhook-stub.php <файл журнала> [--status=500] [--requests=N]
 */

$log      = $argv[1] ?? '';
$status   = 200;
$requests = 1;

foreach (array_slice($argv, 2) as $argument) {
    if (str_starts_with($argument, '--status=')) {
        $status = (int) substr($argument, 9);
    }
    if (str_starts_with($argument, '--requests=')) {
        $requests = (int) substr($argument, 11);
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

$handled  = 0;
$deadline = time() + 30;

while ($handled < $requests && time() < $deadline) {
    $client = @stream_socket_accept($server, 5);

    if ($client === false) {
        continue;
    }

    $headers = '';
    while (($line = fgets($client, 8192)) !== false) {
        $headers .= $line;

        if (rtrim($line, "\r\n") === '') {
            break;
        }
    }

    $length = 0;
    if (preg_match('/^Content-Length:\s*(\d+)/mi', $headers, $m) === 1) {
        $length = (int) $m[1];
    }

    $body = $length > 0 ? (string) stream_get_contents($client, $length) : '';

    $note(rtrim($headers));
    $note($body);
    $note('--конец--');

    $answer = $status >= 200 && $status < 300 ? '{"ok":true}' : '{"ok":false,"why":"так вышло"}';

    fwrite($client, "HTTP/1.1 {$status} Ответ\r\n"
        . "Content-Type: application/json\r\n"
        . 'Content-Length: ' . strlen($answer) . "\r\n"
        . "Connection: close\r\n\r\n"
        . $answer);

    fclose($client);
    $handled++;
}

fclose($server);
