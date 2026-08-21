<?php

declare(strict_types=1);

/**
 * Приёмник вебхуков: сервис сообщает сюда, что случилось с письмом.
 *
 * Адрес этого скрипта заводится в панели (Вебхуки → Вебхуки проектов), там же
 * задаётся секрет подписи и выбираются события, о которых сообщать.
 */

// Секрет вебхука — храните его рядом с остальными настройками приложения
$secret = getenv('MAILER_WEBHOOK_SECRET') ?: 'секрет-из-настроек-вебхука';

// Сколько секунд считаем запрос свежим: подпись включает время, и старый запрос,
// перехваченный по дороге, переиграть не выйдет
$maxAge = 300;

$body   = (string) file_get_contents('php://input');
$header = $_SERVER['HTTP_X_MAILER_SIGNATURE'] ?? '';

if (preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $header, $m) !== 1) {
    http_response_code(403);
    echo 'Нет подписи';

    return;
}

$timestamp = (int) $m[1];
$signature = $m[2];

if (abs(time() - $timestamp) > $maxAge) {
    http_response_code(403);
    echo 'Запрос слишком старый';

    return;
}

if (!hash_equals(hash_hmac('sha256', $timestamp . '.' . $body, $secret), $signature)) {
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

// Повтор приходит с тем же id — по нему и делается идемпотентность.
// Здесь для примера файл, у себя используйте базу
$delivery = (string) ($payload['id'] ?? '');
$seen     = sys_get_temp_dir() . '/mailer-webhook-' . preg_replace('/[^a-z0-9-]/i', '', $delivery);

if ($delivery !== '' && file_exists($seen)) {
    http_response_code(200);
    echo 'уже обработано';

    return;
}

$message = $payload['data']['message'] ?? [];

switch ($payload['event'] ?? '') {
    case 'message.sent':
        // отметить у себя, что письмо принято почтовым сервером
        error_log('Письмо отправлено: ' . ($message['id'] ?? ''));
        break;

    case 'message.failed':
        error_log('Письмо не ушло: ' . ($message['id'] ?? '') . ' — ' . ($payload['data']['error'] ?? ''));
        break;

    case 'message.bounced':
        // сервер получателя отказал по ящику — адрес больше не наш клиент
        error_log('Отказ по адресу ' . ($payload['data']['recipient'] ?? ''));
        break;

    case 'recipient.unsubscribed':
        // письма за этим событием нет: отписался адрес, а не конкретное письмо
        error_log('Отписался: ' . ($payload['data']['email'] ?? ''));
        break;

    case 'ping':
        // проверка связи из панели: отвечаем 200 и ничего не делаем
        break;
}

if ($delivery !== '') {
    file_put_contents($seen, (string) time());
}

// Любой ответ 2xx означает «принято». Иначе сервис повторит доставку.
http_response_code(200);
echo 'ok';
