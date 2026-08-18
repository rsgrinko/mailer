<?php

declare(strict_types=1);

/**
 * Простое письмо: как это выглядит в обычном проекте.
 *
 * Запуск: php sdk/examples/basic.php
 */

require dirname(__DIR__) . '/MailerClient.php';

use Mailer\Sdk\Client;
use Mailer\Sdk\Mail;
use Mailer\Sdk\MailerException;

$mailer = new Client(
    getenv('MAILER_API') ?: 'http://127.0.0.1:8080',
    getenv('MAILER_KEY') ?: 'mlr_замените_на_свой_ключ'
);

try {
    // Письмо уходит в очередь, ответ приходит сразу
    $result = $mailer->send(
        Mail::to('user@example.com')
            ->subject('Заказ №1024 оформлен')
            ->html('<p>Здравствуйте!</p><p>Мы приняли ваш заказ.</p>')
            ->tag('заказы')
    );

    echo 'Письмо принято: ' . $result['id'] . ', статус ' . $result['status'] . PHP_EOL;

    // Если нужно точно знать результат — отправляем и ждём
    $now = $mailer->sendNow(
        Mail::to('admin@example.com')
            ->subject('Важное уведомление')
            ->text('Это письмо отправлено без очереди.')
    );

    echo 'Отправлено сразу: ' . $now['status'] . PHP_EOL;

    // Состояние письма и его история
    $status = $mailer->status($result['id']);
    echo 'Текущий статус: ' . $status['message']['status'] . PHP_EOL;
} catch (MailerException $e) {
    echo 'Не получилось: ' . $e->getMessage() . ' (код ' . $e->getCode() . ')' . PHP_EOL;

    foreach ($e->errors as $error) {
        echo '  - ' . $error . PHP_EOL;
    }
}
