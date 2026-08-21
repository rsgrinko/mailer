#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Подмена системного sendmail на чужом сервере — вариант на PHP.
 *
 * Делает то же, что соседний shell-скрипт: читает готовое письмо из stdin и
 * отдаёт его почтовому сервису по HTTP API. Отличий два: не нужен curl в системе
 * (хватит потоков) и удобнее ставить туда, где PHP и так есть — хостинги с
 * WordPress, Bitrix, самописными CMS.
 *
 * Файл самодостаточный: ни автозагрузчика, ни зависимостей проекта. Работает на
 * PHP 7.0 и новее, поэтому здесь нет match, str_contains и прочего из PHP 8.
 *
 * Установка:
 *   1. Скопировать, например в /usr/local/bin/mailer-sendmail.php, chmod +x
 *   2. Заполнить /etc/mailer-sendmail.conf (см. mailer-sendmail.conf.example)
 *   3. В php.ini:  sendmail_path = /usr/local/bin/mailer-sendmail.php -t -i
 *
 * Коды выхода: 0 — принято, 64 — ошибка в аргументах, 65 — письмо не прошло
 * проверку, 75 — временная ошибка (повторить позже), 77 — ключ не подошёл,
 * 78 — не настроено.
 */

if (PHP_SAPI !== 'cli') {
    exit(64);
}

/**
 * Пишет причину в stderr (и в лог, если он задан) и завершает работу.
 *
 * @param array<string, string> $config
 */
function shimFail(int $code, string $message, array $config = [])
{
    shimLog($message, $config);

    fwrite(STDERR, 'mailer-sendmail: ' . $message . PHP_EOL);

    exit($code);
}

/**
 * @param array<string, string> $config
 */
function shimLog(string $message, array $config)
{
    $file = isset($config['MAILER_LOG']) ? $config['MAILER_LOG'] : '';

    if ($file === '') {
        return;
    }

    @file_put_contents($file, date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL, FILE_APPEND);
}

/**
 * Настройки: сначала окружение процесса, затем файл.
 *
 * @return array<string, string>
 */
function shimConfig()
{
    $config = [];
    $file   = getenv('MAILER_CONF');

    if ($file === false || $file === '') {
        $file = '/etc/mailer-sendmail.conf';
    }

    if (is_readable($file)) {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines === false ? [] : $lines as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }

            list($key, $value) = explode('=', $line, 2);

            $key   = trim($key);
            $value = trim($value);
            $length = strlen($value);

            // Снимаем кавычки, если значение в них завёрнуто
            if ($length >= 2) {
                $first = $value[0];
                $last  = $value[$length - 1];

                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            $config[$key] = $value;
        }
    }

    foreach (['MAILER_URL', 'MAILER_KEY', 'MAILER_TIMEOUT', 'MAILER_TAG', 'MAILER_LOG'] as $key) {
        $fromEnv = getenv($key);

        if ($fromEnv !== false && $fromEnv !== '') {
            $config[$key] = $fromEnv;
        }
    }

    return $config;
}

/**
 * Разбирает аргументы sendmail.
 *
 * @param  array<int, string> $argv
 * @return array{from: string, name: string, recipients: array<int, string>}
 */
function shimArguments(array $argv)
{
    $from       = '';
    $name       = '';
    $recipients = [];
    $count      = count($argv);

    for ($i = 1; $i < $count; $i++) {
        $argument = $argv[$i];

        if ($argument === '-f' || $argument === '-F') {
            $next = isset($argv[$i + 1]) ? $argv[$i + 1] : '';

            if ($next === '') {
                shimFail(64, 'ключ ' . $argument . ' без значения');
            }

            if ($argument === '-f') {
                $from = $next;
            } else {
                $name = $next;
            }

            $i++;

            continue;
        }

        if (strpos($argument, '-f') === 0 && strlen($argument) > 2) {
            $from = substr($argument, 2);

            continue;
        }

        if (strpos($argument, '-F') === 0 && strlen($argument) > 2) {
            $name = substr($argument, 2);

            continue;
        }

        // Прочие ключи принимаем молча — так делает и настоящий sendmail
        if ($argument !== '' && $argument[0] === '-') {
            continue;
        }

        $recipients[] = $argument;
    }

    return ['from' => $from, 'name' => $name, 'recipients' => $recipients];
}

/**
 * Отправляет тело запроса сервису. Возвращает код ответа и текст.
 *
 * @param  array<string, string> $config
 * @return array{code: int, body: string}
 */
function shimSend(array $config, string $payload)
{
    $url     = rtrim($config['MAILER_URL'], '/') . '/api/v1/messages';
    $timeout = isset($config['MAILER_TIMEOUT']) ? (int) $config['MAILER_TIMEOUT'] : 15;
    $timeout = $timeout > 0 ? $timeout : 15;

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $config['MAILER_KEY'],
        'Content-Length: ' . strlen($payload),
    ];

    if (function_exists('curl_init')) {
        $curl = curl_init($url);

        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, $timeout);

        $body = curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);

        curl_close($curl);

        if ($body === false) {
            shimFail(75, 'сервис недоступен: ' . $error, $config);
        }

        return ['code' => $code, 'body' => (string) $body];
    }

    // Без расширения curl обходимся потоками — они есть всегда
    $context = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers),
            'content'       => $payload,
            'timeout'       => $timeout,
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);

    if ($body === false) {
        shimFail(75, 'сервис недоступен: ' . $url, $config);
    }

    $code = 0;

    foreach (isset($http_response_header) ? $http_response_header : [] as $header) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $match) === 1) {
            $code = (int) $match[1];
        }
    }

    return ['code' => $code, 'body' => (string) $body];
}

$config = shimConfig();

if (!isset($config['MAILER_URL']) || $config['MAILER_URL'] === '' || !isset($config['MAILER_KEY']) || $config['MAILER_KEY'] === '') {
    shimFail(78, 'не настроено: нужны MAILER_URL и MAILER_KEY', $config);
}

$arguments = shimArguments($argv);
$raw       = (string) file_get_contents('php://stdin');

if (trim($raw) === '') {
    shimFail(64, 'на вход не пришло письмо', $config);
}

$payload = ['raw' => $raw];

if ($arguments['from'] !== '') {
    $payload['envelope_from'] = $arguments['from'];
}

if ($arguments['recipients'] !== []) {
    $payload['envelope_to'] = $arguments['recipients'];
}

if (isset($config['MAILER_TAG']) && $config['MAILER_TAG'] !== '') {
    $payload['tag'] = $config['MAILER_TAG'];
}

$payload['meta'] = [
    'host' => gethostname() === false ? 'unknown' : gethostname(),
    'shim' => 'php',
];

if ($arguments['name'] !== '') {
    $payload['meta']['sender_name'] = $arguments['name'];
}

$json = json_encode($payload, JSON_UNESCAPED_UNICODE);

if ($json === false) {
    shimFail(65, 'письмо не удалось упаковать в JSON: ' . json_last_error_msg(), $config);
}

$answer = shimSend($config, $json);
$code   = $answer['code'];

if ($code >= 200 && $code < 300) {
    shimLog('принято: ' . $answer['body'], $config);

    exit(0);
}

if ($code === 401 || $code === 403) {
    shimFail(77, 'ключ не подошёл (код ' . $code . '): ' . $answer['body'], $config);
}

if ($code === 400 || $code === 404 || $code === 422) {
    shimFail(65, 'сервис не принял письмо (код ' . $code . '): ' . $answer['body'], $config);
}

shimFail(75, 'временная ошибка (код ' . $code . '): ' . $answer['body'], $config);
