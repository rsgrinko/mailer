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

/**
 * Поднимает игрушечный POP3 с готовыми письмами в ящике.
 *
 * @param  array<int, string> $messages
 * @return array{port: int, log: string, box: string, process: resource, pipes: array<int, resource>}
 */
function startPop3Stub(array $messages, bool $badPassword = false): array
{
    if (!function_exists('proc_open')) {
        skipTest('proc_open выключен');
    }

    $log = (string) tempnam(sys_get_temp_dir(), 'pop3-log-');
    $box = (string) tempnam(sys_get_temp_dir(), 'pop3-box-');

    file_put_contents($box, implode("\n%%\n", $messages));

    $command = [PHP_BINARY, MAILER_ROOT . '/tests/pop3-stub.php', $log, '--messages=' . $box];

    if ($badPassword) {
        $command[] = '--bad-password';
    }

    $pipes   = [];
    $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (!is_resource($process)) {
        @unlink($log);
        @unlink($box);
        skipTest('не удалось запустить заглушку POP3');
    }

    $port = (int) trim((string) fgets($pipes[1]));

    if ($port <= 0) {
        @proc_terminate($process);
        skipTest('заглушка POP3 не заняла порт');
    }

    return ['port' => $port, 'log' => $log, 'box' => $box, 'process' => $process, 'pipes' => $pipes];
}

/**
 * @param array{port: int, log: string, box: string, process: resource, pipes: array<int, resource>} $stub
 */
function stopPop3Stub(array $stub): string
{
    $log = is_file($stub['log']) ? (string) file_get_contents($stub['log']) : '';

    foreach ($stub['pipes'] as $pipe) {
        @fclose($pipe);
    }

    @proc_terminate($stub['process']);
    @proc_close($stub['process']);
    @unlink($stub['log']);
    @unlink($stub['box']);

    return $log;
}

/**
 * Настройки ящика отказов, указывающие на заглушку.
 *
 * @return array<string, mixed>
 */
function bounceMailbox(int $port): array
{
    return [
        'bounce.enabled'    => true,
        'bounce.host'       => '127.0.0.1',
        'bounce.port'       => $port,
        'bounce.encryption' => 'none',
        'bounce.username'   => 'bounce@example.com',
        'bounce.password'   => 'секрет',
        'bounce.delete'     => true,
    ];
}

test('сборщик забирает отказы из ящика и закрывает адреса', function (): void {
    $stub = startPop3Stub([
        bounceReport('pop3-otkaz@example.com', '5.1.1', 'failed', '550 5.1.1 User unknown'),
        bounceReport('pop3-zaderzhka@example.com', '4.2.2', 'delayed', '452 Mailbox full'),
    ]);

    try {
        $result = withConfig(bounceMailbox($stub['port']), static function (): array {
            assertTrue(Collector::enabled(), 'с настройками ящика сборщик должен включаться');

            return (new Collector())->run();
        });

        assertSame(2, $result['fetched'], 'из ящика должны прийти оба письма');
        assertSame(1, $result['suppressed'], 'закрыть надо только окончательный отказ');
        assertSame(1, $result['skipped'], 'временная задержка адрес не закрывает');

        $list = new SuppressionRepository();

        assertTrue($list->isBlocked('pop3-otkaz@example.com'), 'адрес окончательного отказа должен закрыться');
        assertFalse($list->isBlocked('pop3-zaderzhka@example.com'), 'задержка адрес не закрывает');

        $log = stopPop3Stub($stub);

        assertContains('USER bounce@example.com', $log, 'клиент должен представиться');
        assertContains('PASS ***', $log, 'пароль должен уйти отдельной командой');
        assertContains('RETR 1', $log, 'письмо должно быть скачано');
        assertContains('DELE 1', $log, 'разобранное письмо удаляется из ящика');
        assertContains('QUIT', $log, 'сессия должна закрываться по-человечески');

        $stub = null;

        $row = (array) Mailer\Storage\Database::instance()->selectOne(
            'SELECT id FROM suppressions WHERE email = :email',
            ['email' => 'pop3-otkaz@example.com']
        );
        $list->delete((int) $row['id']);
    } finally {
        if ($stub !== null) {
            stopPop3Stub($stub);
        }
    }
});

test('с bounce.delete=false письма в ящике остаются', function (): void {
    $stub = startPop3Stub([
        bounceReport('pop3-ostanetsya@example.com', '5.1.1', 'failed', '550 5.1.1 User unknown'),
    ]);

    try {
        $settings = bounceMailbox($stub['port']);
        $settings['bounce.delete'] = false;

        withConfig($settings, static function (): void {
            (new Collector())->run();
        });

        $log = stopPop3Stub($stub);
        $stub = null;

        assertContains('RETR 1', $log);
        assertNotContains('DELE', $log, 'с выключенным удалением письма трогать нельзя');

        $list = new SuppressionRepository();
        $row  = (array) Mailer\Storage\Database::instance()->selectOne(
            'SELECT id FROM suppressions WHERE email = :email',
            ['email' => 'pop3-ostanetsya@example.com']
        );
        $list->delete((int) $row['id']);
    } finally {
        if ($stub !== null) {
            stopPop3Stub($stub);
        }
    }
});

test('неверный пароль от ящика — понятная ошибка, а не молчание', function (): void {
    $stub = startPop3Stub([bounceReport('pop3-nikto@example.com', '5.1.1', 'failed', '550 User unknown')], true);

    try {
        $error = withConfig(bounceMailbox($stub['port']), static function () {
            return assertThrows(static fn () => (new Collector())->run(), 'отказ во входе должен быть виден');
        });

        assertContains('Ящик отказов ответил отказом', $error->getMessage());
        assertContains('неверный пароль', $error->getMessage(), 'ответ сервера должен быть виден целиком');
        assertFalse(
            (new SuppressionRepository())->isBlocked('pop3-nikto@example.com'),
            'без входа в ящик адреса закрывать нечем'
        );
    } finally {
        stopPop3Stub($stub);
    }
});
