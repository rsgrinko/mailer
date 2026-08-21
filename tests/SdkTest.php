<?php

declare(strict_types=1);

/**
 * Мини-SDK и сервис должны понимать друг друга. SDK живёт в integrations/ и
 * копируется в чужие проекты, поэтому здесь проверяется главное: поля, которые
 * собирает Mail, действительно доезжают до письма, а Client ходит по тем адресам,
 * которые у API есть.
 */

use Mailer\Http\ApiKernel;
use Mailer\Message\MessageFactory;
use Mailer\Repository\MessageRepository;
use Mailer\Sdk\Client;
use Mailer\Sdk\Mail;

test('поля Mail доезжают до письма', function (): void {
    $mail = Mail::to(['Иван <ivan@example.com>', 'petr@example.com'])
        ->from('noreply@example.com', 'Магазин')
        ->cc('manager@example.com')
        ->bcc('audit@example.com')
        ->replyTo('support@example.com')
        ->subject('Заказ №1024')
        ->text('Спасибо за заказ')
        ->html('<p>Спасибо за заказ</p>')
        ->attach('счёт.txt', 'содержимое', 'text/plain')
        ->header('X-Order-Id', '1024')
        ->tag('заказы')
        ->meta(['order_id' => 1024])
        ->transport('sdk-тест-null')
        ->priority(10)
        ->sendAt('+2 hours')
        ->idempotencyKey('заказ-1024');

    $built   = (new MessageFactory())->build($mail->toArray());
    $message = $built['message'];

    assertSame('noreply@example.com', $message->from->email);
    assertSame('Магазин', $message->from->name);
    assertSame(2, count($message->to));
    assertSame('Иван', $message->to[0]->name);
    assertSame('manager@example.com', $message->cc[0]->email);
    assertSame('audit@example.com', $message->bcc[0]->email);
    assertSame('support@example.com', $message->replyTo->email);
    assertSame('Заказ №1024', $message->subject);
    assertSame('Спасибо за заказ', $message->text);
    assertSame('заказы', $message->tag);
    assertSame(1024, $message->meta['order_id']);
    assertSame(10, $message->priority);
    assertSame('1024', $message->headers['X-Order-Id']);
    assertSame(1, count($message->attachments));
    assertSame('счёт.txt', $message->attachments[0]->name);
    assertSame('содержимое', $message->attachments[0]->content());

    // Остальное сервис забирает не в письмо, а в настройки приёма
    assertSame('sdk-тест-null', $built['options']['transport']);
    assertSame('заказ-1024', $built['options']['idempotency_key']);
    assertSame('+2 hours', $built['options']['send_at']);
});

test('картинка из SDK приезжает встроенной', function (): void {
    $file = MAILER_ROOT . '/var/sdk-тест.png';
    file_put_contents($file, 'PNG');

    try {
        $mail = Mail::to('user@example.com')
            ->subject('С картинкой')
            ->html('<img src="cid:логотип">')
            ->inlineImage('логотип', $file);

        $message = (new MessageFactory())->build($mail->toArray())['message'];

        assertTrue($message->hasInlineAttachments());
        assertSame('логотип', $message->attachments[0]->cid);
    } finally {
        @unlink($file);
    }
});

test('письмо из SDK принимает API', function (): void {
    $ids     = httpFixtures();
    $kernel  = new ApiKernel();
    $payload = Mail::to('user@example.com')
        ->subject('Письмо из SDK')
        ->text('Текст')
        ->tag('sdk')
        ->transport('http-тест-null')
        ->sync()
        ->toArray();

    $response = $kernel->handle(
        httpRequest('POST', '/api/v1/messages', ['authorization' => 'Bearer ' . $ids['key']], $payload)
    );

    assertSame(200, $response->status());

    $answer = (array) json_decode($response->body(), true);
    assertSame(MessageRepository::SENT, $answer['status']);
    assertTrue($answer['sync']);

    // За собой прибираем: чужие письма в очереди мешают соседним тестам
    $row = (new MessageRepository())->findAny((string) $answer['id']);
    if ($row !== null) {
        (new MessageRepository())->delete((int) $row['id']);
    }
});

test('клиент SDK ходит по адресам, которые у API есть', function (): void {
    $router = new Mailer\Http\Router();
    $router->load(MAILER_ROOT . '/routes/api.php');

    $patterns = [];
    foreach ($router->routes() as $route) {
        $patterns[] = $route->pattern;
    }

    // Что дёргает каждый метод клиента: метода request() снаружи не видно,
    // поэтому сверяем список руками — он и должен ломаться, когда адрес переехал
    $used = [
        '/api/v1/messages',
        '/api/v1/messages/{id}',
        '/api/v1/messages/{id}/retry',
        '/api/v1/templates',
        '/api/v1/suppressions',
        '/api/v1/suppressions/{email}',
        '/api/v1/health',
    ];

    foreach ($used as $pattern) {
        assertTrue(in_array($pattern, $patterns, true), 'SDK ходит на ' . $pattern . ', а такого маршрута нет');
    }

    // И сам клиент собирается: базовый адрес чистится от завершающего слэша
    $client = new Client('http://127.0.0.1:8080/', 'mlr_тест');
    assertTrue($client instanceof Client);
});
