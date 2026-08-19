<?php

declare(strict_types=1);

/**
 * Рассылка по списку получателей.
 *
 * Каждому уходит своё письмо — так у каждого получателя своя история, свой статус
 * и никто не видит чужие адреса. Сервис сам растянет отправку по очереди.
 */

require dirname(__DIR__) . '/MailerClient.php';

use Mailer\Sdk\Client;
use Mailer\Sdk\Mail;
use Mailer\Sdk\MailerException;

$mailer = new Client(
    getenv('MAILER_API') ?: 'http://127.0.0.1:8080',
    getenv('MAILER_KEY') ?: 'mlr_замените_на_свой_ключ'
);

$recipients = [
    ['email' => 'ivan@example.com', 'name' => 'Иван', 'id' => 1],
    ['email' => 'maria@example.com', 'name' => 'Мария', 'id' => 2],
];

$campaign = 'новости-' . date('Y-m');

foreach ($recipients as $recipient) {
    try {
        $result = $mailer->send(
            Mail::to($recipient['email'])
                ->subject('Новости за месяц')
                ->html('<p>Здравствуйте, ' . htmlspecialchars($recipient['name']) . '!</p><p>Рассказываем, что нового.</p>')
                ->tag($campaign)
                // рассылка не срочная — пропустим вперёд обычные письма
                ->priority(200)
                // при повторном запуске скрипта дубли не создадутся
                ->idempotencyKey($campaign . ':' . $recipient['id'])
                ->meta(['user_id' => $recipient['id']])
        );

        echo $recipient['email'] . ' — ' . $result['status']
            . ($result['duplicate'] ? ' (уже отправляли)' : '') . PHP_EOL;
    } catch (MailerException $e) {
        // 429 означает, что упёрлись в лимит проекта — подождём и продолжим позже
        echo $recipient['email'] . ' — ошибка: ' . $e->getMessage() . PHP_EOL;

        if ($e->getCode() === 429) {
            break;
        }
    }
}

// Сколько писем этой рассылки уже отправлено
$sent = $mailer->messages(['tag' => $campaign, 'status' => 'sent']);
echo 'Отправлено писем рассылки: ' . $sent['total'] . PHP_EOL;
