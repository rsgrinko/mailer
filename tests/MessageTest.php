<?php

declare(strict_types=1);

/**
 * Тесты сборки и разбора писем.
 */

use Mailer\Message\Address;
use Mailer\Message\Attachment;
use Mailer\Message\Encoder;
use Mailer\Message\Message;
use Mailer\Message\MimeBuilder;
use Mailer\Message\MimeParser;

test('адрес разбирается из строки с именем', function (): void {
    $address = Address::parse('Иван Петров <ivan@example.com>');

    assertSame('ivan@example.com', $address->email);
    assertSame('Иван Петров', $address->name);
});

test('список адресов разбирается по запятым', function (): void {
    $list = Address::parseList('a@example.com, "Петров, Иван" <b@example.com>');

    assertSame(2, count($list));
    assertSame('a@example.com', $list[0]->email);
    assertSame('b@example.com', $list[1]->email);
    assertSame('Петров, Иван', $list[1]->name);
});

test('кривой адрес не проходит', function (): void {
    assertThrows(static fn () => new Address('не-адрес'));
});

test('русский заголовок кодируется', function (): void {
    $encoded = Encoder::header('Проверка связи');

    assertContains('=?UTF-8?B?', $encoded);
    assertFalse(str_contains($encoded, 'Проверка'));
});

test('латинский заголовок остаётся как есть', function (): void {
    assertSame('Simple subject', Encoder::header('Simple subject'));
});

test('точка в начале строки экранируется', function (): void {
    $data = Encoder::dotStuff("строка\r\n.точка\r\n");

    assertContains("\r\n..точка", $data);
});

test('письмо с текстом и HTML собирается как multipart/alternative', function (): void {
    $message = new Message();
    $message->from = new Address('from@example.com', 'Сервис');
    $message->addTo('to@example.com');
    $message->subject = 'Тема письма';
    $message->text    = 'Текстовая версия';
    $message->html    = '<p>HTML-версия</p>';

    $mime = (new MimeBuilder())->build($message);

    assertContains('multipart/alternative', $mime);
    assertContains('text/plain; charset=UTF-8', $mime);
    assertContains('text/html; charset=UTF-8', $mime);
    assertContains('Message-ID:', $mime);
});

test('вложение попадает в письмо', function (): void {
    $message = new Message();
    $message->from = new Address('from@example.com');
    $message->addTo('to@example.com');
    $message->subject = 'С вложением';
    $message->text    = 'Текст';
    $message->attach(new Attachment('отчёт.txt', 'содержимое файла', 'text/plain'));

    $mime = (new MimeBuilder())->build($message);

    assertContains('multipart/mixed', $mime);
    assertContains('Content-Disposition: attachment', $mime);
    assertContains(base64_encode('содержимое файла'), $mime);
});

test('картинка в HTML заворачивается в multipart/related', function (): void {
    $message = new Message();
    $message->from = new Address('from@example.com');
    $message->addTo('to@example.com');
    $message->subject = 'С картинкой';
    $message->html    = '<img src="cid:logo">';
    $message->attach(new Attachment('logo.png', 'png-данные', 'image/png', true, 'logo'));

    $mime = (new MimeBuilder())->build($message);

    assertContains('multipart/related', $mime);
    assertContains('Content-ID: <logo>', $mime);
});

test('если прислали только HTML, текстовая версия создаётся сама', function (): void {
    $message = new Message();
    $message->from = new Address('from@example.com');
    $message->addTo('to@example.com');
    $message->subject = 'Только HTML';
    $message->html    = '<p>Привет,<br>мир</p>';

    $mime = (new MimeBuilder())->build($message);

    assertContains('multipart/alternative', $mime);
});

test('клиент не может подсунуть свой Bcc или Content-Type', function (): void {
    $message = new Message();
    $message->from = new Address('from@example.com');
    $message->addTo('to@example.com');
    $message->subject           = 'Заголовки';
    $message->text              = 'текст';
    $message->headers['Bcc']    = 'secret@example.com';
    $message->headers['X-Тест'] = 'значение';

    $mime = (new MimeBuilder())->build($message);

    assertNotContains('secret@example.com', $mime);
    assertContains('X-Тест:', $mime);
});

test('разбор письма достаёт тему, адреса и тело', function (): void {
    $raw = "From: Иван <ivan@example.com>\r\n"
        . "To: user@example.com, second@example.com\r\n"
        . "Subject: =?UTF-8?B?" . base64_encode('Тестовая тема') . "?=\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "\r\n"
        . "Тело письма";

    $parsed = MimeParser::parse($raw);

    assertSame('Тестовая тема', $parsed['subject']);
    assertSame('ivan@example.com', $parsed['from'][0]->email);
    assertSame(2, count($parsed['to']));
    assertContains('Тело письма', $parsed['text']);
});

test('заголовок Bcc вырезается из письма', function (): void {
    $raw = "From: a@example.com\r\nBcc: hidden@example.com\r\nSubject: тема\r\n\r\nтело";

    $result = MimeParser::removeHeader($raw, 'Bcc');

    assertNotContains('hidden@example.com', $result);
    assertContains('Subject: тема', $result);
    assertContains('тело', $result);
});

test('разбор multipart-письма достаёт обе части и вложение', function (): void {
    $boundary = 'граница123';
    $raw = "Subject: тест\r\n"
        . "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n\r\n"
        . "--$boundary\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
        . "текстовая часть\r\n"
        . "--$boundary\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
        . "<b>html</b>\r\n"
        . "--$boundary\r\n"
        . "Content-Type: application/pdf; name=\"doc.pdf\"\r\n"
        . "Content-Transfer-Encoding: base64\r\n"
        . "Content-Disposition: attachment; filename=\"doc.pdf\"\r\n\r\n"
        . base64_encode('pdf') . "\r\n"
        . "--$boundary--\r\n";

    $parsed = MimeParser::parse($raw);

    assertContains('текстовая часть', $parsed['text']);
    assertContains('<b>html</b>', $parsed['html']);
    assertSame(1, count($parsed['attachments']));
    assertSame('doc.pdf', $parsed['attachments'][0]['name']);
});
