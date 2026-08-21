<?php

declare(strict_types=1);

/**
 * Игрушечный SMTP-сервер для tests/SmtpSessionTest.php.
 *
 * Запускается отдельным процессом, печатает в stdout занятый порт и пишет в журнал
 * всё, что с ним делали: connect, сами команды, disconnect. По журналу тест и видит,
 * переиспользовал транспорт соединение или подключался заново.
 *
 * Запуск: php tests/smtp-stub.php <файл журнала> [--drop-after=N] [--auth=LOGIN|PLAIN]
 *         [--user=логин] [--password=пароль]
 * С --drop-after=N сервер молча рвёт связь после N-го письма — так проверяется,
 * что следующая отправка поднимет соединение сама. С --auth сервер объявляет
 * поддержку авторизации в ответе на EHLO и требует её до MAIL FROM.
 */

$log       = $argv[1] ?? '';
$dropAfter = 0;
$auth      = '';
$user      = 'stub-user';
$password  = 'stub-password';

foreach (array_slice($argv, 2) as $argument) {
    if (str_starts_with($argument, '--drop-after=')) {
        $dropAfter = (int) substr($argument, 13);
    }

    if (str_starts_with($argument, '--auth=')) {
        $auth = strtoupper(substr($argument, 7));
    }

    if (str_starts_with($argument, '--user=')) {
        $user = substr($argument, 7);
    }

    if (str_starts_with($argument, '--password=')) {
        $password = substr($argument, 11);
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
    $authorized = $auth === '';
    fwrite($client, "220 stub ESMTP\r\n");

    while (($line = fgets($client, 4096)) !== false) {
        $line    = trim($line);
        $command = strtoupper(strtok($line, ' ') ?: '');

        // В журнал пароль не пишем: у AUTH PLAIN он прямо в строке команды
        $note(str_starts_with(strtoupper($line), 'AUTH PLAIN') ? 'AUTH PLAIN ***' : $line);

        if ($command === 'EHLO' || $command === 'HELO') {
            fwrite($client, $auth === '' ? "250-stub\r\n250 OK\r\n" : "250-stub\r\n250-AUTH " . $auth . "\r\n250 OK\r\n");

            continue;
        }

        if ($command === 'AUTH') {
            $mode = strtoupper(strtok(substr($line, 5), ' ') ?: '');

            if ($mode === 'LOGIN') {
                fwrite($client, '334 ' . base64_encode('Username:') . "\r\n");
                $gotUser = trim((string) fgets($client, 4096));
                $note('login ' . $gotUser);

                fwrite($client, '334 ' . base64_encode('Password:') . "\r\n");
                $gotPassword = trim((string) fgets($client, 4096));
                $note('password ***');

                $authorized = base64_decode($gotUser, true) === $user
                    && base64_decode($gotPassword, true) === $password;
            } else {
                // AUTH PLAIN: логин и пароль приходят одной строкой через нулевой байт
                $token = explode("\0", (string) base64_decode(trim(substr($line, 10)), true));

                $authorized = ($token[1] ?? '') === $user && ($token[2] ?? '') === $password;
            }

            fwrite($client, $authorized ? "235 Добро пожаловать\r\n" : "535 Неверный логин или пароль\r\n");

            continue;
        }

        if ($command === 'MAIL' && $auth !== '' && !$authorized) {
            fwrite($client, "530 Сначала авторизуйтесь\r\n");

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
