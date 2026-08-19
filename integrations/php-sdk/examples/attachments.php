<?php

declare(strict_types=1);

/**
 * Вложения и картинка внутри HTML.
 */

require dirname(__DIR__) . '/MailerClient.php';

use Mailer\Sdk\Client;
use Mailer\Sdk\Mail;
use Mailer\Sdk\MailerException;

$mailer = new Client(
    getenv('MAILER_API') ?: 'http://127.0.0.1:8080',
    getenv('MAILER_KEY') ?: 'mlr_замените_на_свой_ключ'
);

// Готовим файл прямо здесь, чтобы пример можно было запустить как есть
$csv = "товар;количество;цена\nТетрадь;10;45\nРучка;5;30\n";

$mail = Mail::to('user@example.com')
    ->subject('Отчёт за январь')
    ->html('<p>Здравствуйте!</p><p>Отчёт во вложении.</p>')
    // вложение из данных в памяти
    ->attach('отчёт.csv', $csv, 'text/csv')
    ->tag('отчёты');

// вложение из файла на диске
if (is_file('/tmp/invoice.pdf')) {
    $mail->attachFile('/tmp/invoice.pdf', 'счёт.pdf');
}

// картинка внутри HTML: ссылаемся на неё как cid:логотип
if (is_file(__DIR__ . '/logo.png')) {
    $mail
        ->inlineImage('логотип', __DIR__ . '/logo.png')
        ->html('<p>Здравствуйте!</p><img src="cid:логотип" alt="логотип"><p>Отчёт во вложении.</p>');
}

try {
    $result = $mailer->send($mail);
    echo 'Письмо с вложениями принято: ' . $result['id'] . PHP_EOL;
} catch (MailerException $e) {
    echo 'Не получилось: ' . $e->getMessage() . PHP_EOL;
}
