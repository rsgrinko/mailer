<?php

declare(strict_types=1);

/**
 * Игрушечный POP3-сервер для тестов сборщика отказов.
 *
 * Запуск: php tests/pop3-stub.php <файл-журнала> [--messages=путь] [--bad-password]
 * Первой строкой печатает занятый порт, дальше пишет в журнал каждую команду —
 * по нему тест и проверяет, что клиент сходил куда надо и прибрал за собой.
 *
 * Письма берутся из файла: письма разделены строкой «%%».
 */

$log      = $argv[1] ?? '';
$options  = [];

foreach (array_slice($argv, 2) as $argument) {
    if (str_starts_with($argument, '--')) {
        $argument = substr($argument, 2);
        [$name, $value] = str_contains($argument, '=') ? explode('=', $argument, 2) : [$argument, '1'];
        $options[$name] = $value;
    }
}

if ($log === '') {
    fwrite(STDERR, 'нужен файл журнала' . PHP_EOL);

    exit(1);
}

$messages = [];

if (isset($options['messages']) && is_file($options['messages'])) {
    $messages = array_values(array_filter(array_map(
        'trim',
        explode('%%', (string) file_get_contents($options['messages']))
    )));
}

$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

if ($server === false) {
    fwrite(STDERR, 'не занять порт: ' . $error . PHP_EOL);

    exit(1);
}

$name = (string) stream_socket_get_name($server, false);

echo substr($name, (int) strrpos($name, ':') + 1) . PHP_EOL;
flush();

$write = static function (string $line) use ($log): void {
    file_put_contents($log, $line . PHP_EOL, FILE_APPEND);
};

$deleted  = [];
$deadline = time() + 30;

while (time() < $deadline) {
    $client = @stream_socket_accept($server, 5);

    if ($client === false) {
        continue;
    }

    stream_set_timeout($client, 10);
    fwrite($client, '+OK игрушечный POP3 готов' . "\r\n");

    while (($line = fgets($client, 4096)) !== false) {
        $line    = rtrim($line, "\r\n");
        $command = strtoupper(strtok($line, ' ') ?: '');

        // Пароль в журнал не пишем — он и в настоящем клиенте помечен как секрет
        $write($command === 'PASS' ? 'PASS ***' : $line);

        switch ($command) {
            case 'USER':
                fwrite($client, '+OK пользователь принят' . "\r\n");
                break;

            case 'PASS':
                if (isset($options['bad-password'])) {
                    fwrite($client, '-ERR неверный пароль' . "\r\n");

                    break;
                }

                fwrite($client, '+OK вход выполнен' . "\r\n");
                break;

            case 'LIST':
                fwrite($client, '+OK писем в ящике' . "\r\n");

                foreach ($messages as $index => $message) {
                    if (in_array($index + 1, $deleted, true)) {
                        continue;
                    }

                    fwrite($client, ($index + 1) . ' ' . strlen($message) . "\r\n");
                }

                fwrite($client, '.' . "\r\n");
                break;

            case 'RETR':
                $number = (int) trim(substr($line, 4));

                if (!isset($messages[$number - 1])) {
                    fwrite($client, '-ERR нет такого письма' . "\r\n");

                    break;
                }

                fwrite($client, '+OK письмо' . "\r\n");

                foreach (explode("\n", str_replace("\r\n", "\n", $messages[$number - 1])) as $row) {
                    // Точка в начале строки в POP3 удваивается — как и в SMTP
                    fwrite($client, (str_starts_with($row, '.') ? '.' . $row : $row) . "\r\n");
                }

                fwrite($client, '.' . "\r\n");
                break;

            case 'DELE':
                $deleted[] = (int) trim(substr($line, 4));
                fwrite($client, '+OK письмо помечено' . "\r\n");
                break;

            case 'NOOP':
                fwrite($client, '+OK' . "\r\n");
                break;

            case 'QUIT':
                fwrite($client, '+OK до связи' . "\r\n");
                break 2;

            default:
                fwrite($client, '-ERR не понял команду' . "\r\n");
        }
    }

    fclose($client);
}

fclose($server);
