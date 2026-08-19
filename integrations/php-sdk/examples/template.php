<?php

declare(strict_types=1);

/**
 * Письмо по шаблону, который лежит в сервисе.
 *
 * Шаблон создаётся в панели (раздел «Шаблоны»), в тексте используются
 * переменные вида {{ name }}. Приложению остаётся передать данные.
 */

require dirname(__DIR__) . '/MailerClient.php';

use Mailer\Sdk\Client;
use Mailer\Sdk\Mail;
use Mailer\Sdk\MailerException;

$mailer = new Client(
    getenv('MAILER_API') ?: 'http://127.0.0.1:8080',
    getenv('MAILER_KEY') ?: 'mlr_замените_на_свой_ключ'
);

// Какие шаблоны доступны и какие у них переменные
foreach ($mailer->templates()['items'] as $template) {
    echo $template['name'] . ': ' . implode(', ', $template['variables']) . PHP_EOL;
}

try {
    $result = $mailer->send(
        Mail::to('user@example.com')
            ->template('welcome', [
                'name' => 'Иван',
                'site' => 'example.com',
                'user' => ['email' => 'user@example.com'],
            ])
            ->tag('регистрация')
    );

    echo 'Отправлено по шаблону: ' . $result['id'] . PHP_EOL;
} catch (MailerException $e) {
    echo 'Не получилось: ' . $e->getMessage() . PHP_EOL;
}
