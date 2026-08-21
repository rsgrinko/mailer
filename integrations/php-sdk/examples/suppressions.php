<?php

declare(strict_types=1);

/**
 * Стоп-лист: кому проект больше не пишет.
 *
 * Пригодится, когда у приложения есть своя кнопка «отписаться» или обработка
 * жалоб: закрыли адрес — и следующее письмо ему не уйдёт, даже если код его
 * всё ещё передаёт.
 *
 * Запуск: php integrations/php-sdk/examples/suppressions.php
 */

require dirname(__DIR__) . '/MailerClient.php';

use Mailer\Sdk\Client;
use Mailer\Sdk\Mail;
use Mailer\Sdk\MailerException;

$mailer = new Client(
    getenv('MAILER_API') ?: 'http://127.0.0.1:8080',
    getenv('MAILER_KEY') ?: 'mlr_замените_на_свой_ключ'
);

$email = 'ivan@example.com';

try {
    // Причины: bounce, complaint, unsubscribe, manual
    $row = $mailer->suppress($email, 'unsubscribe', 'нажал «отписаться» в личном кабинете');

    echo 'Закрыт: ' . $row['email'] . ' (' . $row['reason'] . ')' . PHP_EOL;

    // Письмо закрытому адресу принимается, но не уходит: статус suppressed
    $result = $mailer->send(Mail::to($email)->subject('Новости')->text('Тело'));

    echo 'Письмо ' . $result['id'] . ': ' . $result['status'] . PHP_EOL;

    // Список — с фильтрами по причине и подстроке адреса
    $list = $mailer->suppressions(['search' => 'example.com', 'per_page' => 10]);

    echo 'Всего закрытых адресов: ' . $list['total'] . PHP_EOL;
    foreach ($list['items'] as $item) {
        echo '  ' . $item['email'] . ' — ' . $item['reason'] . ' от ' . $item['created_at'] . PHP_EOL;
    }

    // Передумали — открываем обратно
    $mailer->unsuppress($email);
    echo 'Открыт обратно: ' . $email . PHP_EOL;
} catch (MailerException $e) {
    // 403 — адрес закрыт для всех проектов сразу, такой снимают только в панели
    echo 'Ошибка ' . $e->getCode() . ': ' . $e->getMessage() . PHP_EOL;
}
