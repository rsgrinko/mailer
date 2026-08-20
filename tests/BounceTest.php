<?php

declare(strict_types=1);

/**
 * Разбор писем-отказов: что достаём из отчёта и какие адреса после этого закрываем.
 */

use Mailer\Bounce\Collector;
use Mailer\Bounce\DsnParser;
use Mailer\Bounce\Verp;
use Mailer\MailService;
use Mailer\Repository\EventRepository;
use Mailer\Repository\MessageRepository;
use Mailer\Repository\SuppressionRepository;
use Mailer\Support\Config;

/**
 * Письмо-отказ, как его присылает почтовый сервер.
 */
function bounceReport(string $recipient, string $status, string $action, string $diagnostic, string $to = ''): string
{
    $to = $to === '' ? 'bounce@example.com' : $to;

    return implode("\r\n", [
        'Return-Path: <>',
        'To: <' . $to . '>',
        'Delivered-To: ' . $to,
        'Subject: Undelivered Mail Returned to Sender',
        'Content-Type: multipart/report; report-type=delivery-status; boundary="ГРАНИЦА"',
        'MIME-Version: 1.0',
        '',
        '--ГРАНИЦА',
        'Content-Type: text/plain; charset=utf-8',
        '',
        'Ваше письмо не доставлено.',
        '',
        '--ГРАНИЦА',
        'Content-Type: message/delivery-status',
        '',
        'Reporting-MTA: dns; mail.example.com',
        '',
        'Final-Recipient: rfc822; ' . $recipient,
        'Action: ' . $action,
        'Status: ' . $status,
        'Diagnostic-Code: smtp; ' . $diagnostic,
        '',
        '--ГРАНИЦА--',
        '',
    ]);
}

test('из отчёта достаётся адрес, код и текст отказа', function (): void {
    $report = DsnParser::parse(bounceReport(
        'nobody@example.com',
        '5.1.1',
        'failed',
        '550 5.1.1 <nobody@example.com>: Recipient address rejected: User unknown'
    ));

    assertSame(1, count($report['recipients']), 'получатель должен найтись');

    $recipient = $report['recipients'][0];
    assertSame('nobody@example.com', $recipient['email']);
    assertSame('5.1.1', $recipient['status']);
    assertSame('failed', $recipient['action']);
    assertTrue($recipient['permanent'], 'отказ 5.x — окончательный');
    assertContains('User unknown', $recipient['diagnostic']);
});

test('временная задержка окончательным отказом не считается', function (): void {
    $report = DsnParser::parse(bounceReport(
        'busy@example.com',
        '4.2.2',
        'delayed',
        '452 4.2.2 Mailbox full'
    ));

    assertFalse($report['recipients'][0]['permanent'], 'задержка адрес не закрывает');
});

test('адрес отказа с VERP указывает на письмо', function (): void {
    Config::set('bounce.address', 'bounce@example.com');
    Config::set('bounce.verp', true);

    $uuid    = 'a2b1c0d9-1111-2222-3333-444455556666';
    $address = Verp::address($uuid);

    assertSame('bounce+' . $uuid . '@example.com', $address);
    assertSame($uuid, Verp::uuid($address));
    assertSame(null, Verp::uuid('someone@example.com'), 'чужой адрес идентификатора не даёт');

    $report = DsnParser::parse(bounceReport('nobody@example.com', '5.1.1', 'failed', '550 user unknown', $address));
    assertSame($uuid, $report['uuid'], 'отказ должен привязаться к письму');

    Config::set('bounce.verp', false);
    Config::set('bounce.address', '');
});

test('отказ закрывает адрес и попадает в историю письма', function (): void {
    Config::set('bounce.address', 'bounce@example.com');
    Config::set('bounce.verp', true);

    $transports = new Mailer\Repository\TransportRepository();
    if ($transports->findByName('отказы-null') === null) {
        $transports->create(['name' => 'отказы-null', 'type' => 'null', 'settings' => [], 'active' => 1]);
    }

    $accepted = (new MailService())->accept([
        'to'        => 'gone@example.com',
        'subject'   => 'Письмо, которое вернётся',
        'text'      => 'Текст',
        'transport' => 'отказы-null',
        'sync'      => true,
    ]);

    $raw = bounceReport(
        'gone@example.com',
        '5.1.1',
        'failed',
        '550 5.1.1 no such user',
        Verp::address((string) $accepted['uuid'])
    );

    $closed = (new Collector())->handle($raw);

    assertSame(1, $closed, 'адрес должен быть закрыт');
    assertTrue((new SuppressionRepository())->isBlocked('gone@example.com'), 'адрес в стоп-листе');

    $events = array_map(
        static fn (array $event): string => (string) $event['message'],
        (new EventRepository())->forMessage((int) $accepted['id'])
    );
    assertContains('закрыт стоп-листом', implode(' ', $events));

    (new SuppressionRepository())->unblock('gone@example.com');
    (new MessageRepository())->delete((int) $accepted['id']);

    Config::set('bounce.verp', false);
    Config::set('bounce.address', '');
});

test('без настроек ящика сборщик не запускается', function (): void {
    Config::set('bounce.enabled', false);
    assertFalse(Collector::enabled(), 'выключенный сборщик не должен считаться готовым');

    Config::set('bounce.enabled', true);
    Config::set('bounce.host', '');
    assertFalse(Collector::enabled(), 'без адреса сервера идти некуда');

    Config::set('bounce.enabled', false);
});
