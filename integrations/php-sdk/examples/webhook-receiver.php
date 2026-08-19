<?php

declare(strict_types=1);

/**
 * Приёмник вебхуков: сервис сообщает сюда, что случилось с письмом.
 *
 * Адрес этого скрипта указывается в настройках проекта (панель → Проекты → Адрес вебхука),
 * там же берётся секрет для проверки подписи.
 */

// Секрет проекта — храните его рядом с остальными настройками приложения
$secret = getenv('MAILER_WEBHOOK_SECRET') ?: 'секрет-из-настроек-проекта';

$body      = (string) file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_MAILER_SIGNATURE'] ?? '';

// Проверяем, что запрос действительно от нашего сервиса
$expected = 'sha256=' . hash_hmac('sha256', $body, $secret);

if (!hash_equals($expected, $signature)) {
    http_response_code(403);
    echo 'Подпись не совпала';

    return;
}

$payload = json_decode($body, true);

if (!is_array($payload)) {
    http_response_code(400);
    echo 'Ожидался JSON';

    return;
}

// $payload содержит: event, message_id, status, subject, to, tag, timestamp
// и всё, что вы передавали в meta при отправке
switch ($payload['event'] ?? '') {
    case 'sent':
        // отметить у себя, что письмо доставлено почтовому серверу
        error_log('Письмо отправлено: ' . $payload['message_id']);
        break;

    case 'failed':
        // например, пометить адрес как проблемный
        error_log('Письмо не ушло: ' . $payload['message_id'] . ' — ' . ($payload['error'] ?? ''));
        break;
}

// Любой ответ 2xx означает «принято». Иначе сервис повторит доставку.
http_response_code(200);
echo 'ok';
