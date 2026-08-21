<?php

declare(strict_types=1);

/**
 * Сессия SMTP живёт от письма к письму: проверяем на игрушечном сервере
 * (tests/smtp-stub.php), что транспорт не подключается заново на каждое письмо,
 * закрывает сессию по счётчику и поднимает её после обрыва.
 */

use Mailer\MailService;
use Mailer\Message\Address;
use Mailer\Message\Message;
use Mailer\Queue\Queue;
use Mailer\Queue\Sender;
use Mailer\Repository\MessageRepository;
use Mailer\Repository\TransportRepository;
use Mailer\Transport\SmtpTransport;

/**
 * Поднимает заглушку SMTP отдельным процессом.
 *
 * @param  string $auth пустая строка — сервер без авторизации, иначе LOGIN или PLAIN
 * @return array{port: int, log: string, process: resource, pipes: array<int, resource>}
 */
function startSmtpStub(int $dropAfter = 0, string $auth = ''): array
{
    if (!function_exists('proc_open')) {
        skipTest('proc_open выключен');
    }

    $log     = (string) tempnam(sys_get_temp_dir(), 'smtp-stub-');
    $command = [PHP_BINARY, MAILER_ROOT . '/tests/smtp-stub.php', $log];

    if ($dropAfter > 0) {
        $command[] = '--drop-after=' . $dropAfter;
    }

    if ($auth !== '') {
        $command[] = '--auth=' . $auth;
    }

    $pipes   = [];
    $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (!is_resource($process)) {
        @unlink($log);
        skipTest('не удалось запустить заглушку SMTP');
    }

    // Заглушка печатает занятый порт первой строкой — до неё подключаться некуда
    $port = (int) trim((string) fgets($pipes[1]));

    if ($port <= 0) {
        stopSmtpStub(['process' => $process, 'pipes' => $pipes, 'log' => $log, 'port' => 0]);
        skipTest('заглушка SMTP не заняла порт');
    }

    return ['port' => $port, 'log' => $log, 'process' => $process, 'pipes' => $pipes];
}

/**
 * @param array{port: int, log: string, process: resource, pipes: array<int, resource>} $stub
 */
function stopSmtpStub(array $stub): string
{
    $log = is_file($stub['log']) ? (string) file_get_contents($stub['log']) : '';

    foreach ($stub['pipes'] as $pipe) {
        @fclose($pipe);
    }

    @proc_terminate($stub['process']);
    @proc_close($stub['process']);
    @unlink($stub['log']);

    return $log;
}

/**
 * @param array<string, mixed> $settings
 */
function stubTransport(int $port, array $settings = []): SmtpTransport
{
    return new SmtpTransport('заглушка', array_merge([
        'host'        => '127.0.0.1',
        'port'        => $port,
        'encryption'  => 'none',
        'timeout'     => 5,
        'verify_peer' => false,
        'from_email'  => 'from@example.com',
    ], $settings));
}

/**
 * Сколько раз в журнале заглушки встретилась ровно такая строка.
 * Считаем построчно: «connect» иначе нашёлся бы и внутри «disconnect».
 */
function stubCount(string $log, string $line): int
{
    $found = 0;

    foreach (explode(PHP_EOL, $log) as $row) {
        if (str_starts_with(trim($row), $line)) {
            $found++;
        }
    }

    return $found;
}

function stubMessage(string $subject): Message
{
    $message = new Message();
    $message->from = new Address('from@example.com');
    $message->addTo('to@example.com');
    $message->subject = $subject;
    $message->text    = 'текст';

    return $message;
}

test('второе письмо уходит в том же соединении', function (): void {
    $stub      = startSmtpStub();
    $transport = stubTransport($stub['port']);

    try {
        $transport->send(stubMessage('первое'));
        $transport->send(stubMessage('второе'));
        $transport->close();
    } finally {
        $log = stopSmtpStub($stub);
    }

    assertSame(1, stubCount($log, 'connect'), 'подключаться должны один раз');
    assertSame(2, stubCount($log, 'DATA'), 'писем должно быть два');
    // Перед вторым письмом сессию сбрасывают: это же и проверка, что связь жива
    assertContains('RSET', $log);
    assertSame(1, stubCount($log, 'QUIT'), 'прощаемся один раз, в конце');
});

test('сессия закрывается, когда в ней ушло письмо по счётчику', function (): void {
    $stub      = startSmtpStub();
    $transport = stubTransport($stub['port'], ['session_limit' => 1]);

    try {
        $transport->send(stubMessage('первое'));
        $transport->send(stubMessage('второе'));
        $transport->close();
    } finally {
        $log = stopSmtpStub($stub);
    }

    assertSame(2, stubCount($log, 'connect'), 'на каждое письмо своё соединение');
    assertSame(2, stubCount($log, 'QUIT'), 'каждую сессию закрываем сами');
});

test('с keepalive=false соединение не переиспользуется', function (): void {
    $stub      = startSmtpStub();
    $transport = stubTransport($stub['port'], ['keepalive' => false]);

    try {
        $transport->send(stubMessage('первое'));
        $transport->send(stubMessage('второе'));
    } finally {
        $log = stopSmtpStub($stub);
    }

    assertSame(2, stubCount($log, 'connect'));
    assertNotContains('RSET', $log);
});

test('оборванная сервером сессия поднимается заново', function (): void {
    $stub      = startSmtpStub(1);
    $transport = stubTransport($stub['port']);

    try {
        $transport->send(stubMessage('первое'));
        // Сервер закрыл связь сразу после первого письма — второе должно уйти всё равно
        $result = $transport->send(stubMessage('второе'));
        $transport->close();
    } finally {
        $log = stopSmtpStub($stub);
    }

    assertContains('OK письмо', $result);
    assertSame(2, stubCount($log, 'connect'), 'после обрыва подключаемся заново');
});

test('воркер шлёт очередь через одно соединение', function (): void {
    $stub       = startSmtpStub();
    $transports = new TransportRepository();

    $transports->create([
        'name'       => 'заглушка-сессии',
        'type'       => 'smtp',
        'settings'   => [
            'host'        => '127.0.0.1',
            'port'        => $stub['port'],
            'encryption'  => 'none',
            'timeout'     => 5,
            'verify_peer' => false,
        ],
        'from_email' => 'noreply@example.com',
    ]);

    $transport = (array) $transports->findByName('заглушка-сессии');
    $service   = new MailService();
    $sender    = new Sender();
    $ids       = [];

    try {
        foreach (['первое', 'второе', 'третье'] as $subject) {
            $accepted = $service->accept([
                'to'        => 'user@example.com',
                'subject'   => $subject,
                'text'      => 'текст',
                'transport' => 'заглушка-сессии',
            ]);

            $ids[] = (int) $accepted['id'];
        }

        foreach ((new Queue())->claim(10, 'тест-сессии') as $row) {
            assertSame('sent', $sender->send($row)['status']);
        }

        $sender->closeTransports();
    } finally {
        $log = stopSmtpStub($stub);

        foreach ($ids as $id) {
            (new MessageRepository())->delete($id);
        }

        $transports->delete((int) $transport['id']);
    }

    assertSame(1, stubCount($log, 'connect'), 'на всю пачку одно соединение');
    assertSame(3, stubCount($log, 'DATA'), 'ушли все три письма');
});

test('транспорт входит по AUTH LOGIN, когда сервер его объявил', function (): void {
    $stub = startSmtpStub(0, 'LOGIN');

    try {
        $transport = stubTransport($stub['port'], [
            'username' => 'stub-user',
            'password' => 'stub-password',
        ]);

        $transport->send(stubMessage('Письмо с авторизацией'));
        $transport->close();

        $log = stopSmtpStub($stub);
        $stub = null;

        assertContains('AUTH LOGIN', $log, 'способ авторизации выбирается по ответу сервера');
        assertContains('login ' . base64_encode('stub-user'), $log, 'логин уходит в base64');
        assertNotContains('stub-password', $log, 'пароль не должен светиться открытым текстом');
        assertContains('MAIL FROM', $log, 'после входа письмо должно уйти');
    } finally {
        if ($stub !== null) {
            stopSmtpStub($stub);
        }
    }
});

test('транспорт умеет AUTH PLAIN', function (): void {
    $stub = startSmtpStub(0, 'PLAIN');

    try {
        $transport = stubTransport($stub['port'], [
            'username' => 'stub-user',
            'password' => 'stub-password',
        ]);

        $transport->send(stubMessage('Письмо через PLAIN'));
        $transport->close();

        $log = stopSmtpStub($stub);
        $stub = null;

        assertContains('AUTH PLAIN', $log);
        assertContains('MAIL FROM', $log, 'после входа письмо должно уйти');
    } finally {
        if ($stub !== null) {
            stopSmtpStub($stub);
        }
    }
});

test('неверный пароль SMTP — постоянная ошибка, письмо в повтор не идёт', function (): void {
    $stub = startSmtpStub(0, 'LOGIN');

    try {
        $transport = stubTransport($stub['port'], [
            'username' => 'stub-user',
            'password' => 'не-тот-пароль',
        ]);

        $error = assertThrows(static fn () => $transport->send(stubMessage('Не уйдёт')));

        assertTrue($error instanceof Mailer\Transport\TransportException);
        assertFalse($error->isTemporary(), 'пароль сам не исправится, повторять бессмысленно');
        assertContains('535', $error->getMessage(), 'в ошибке должен быть ответ сервера');

        $transport->close();
    } finally {
        stopSmtpStub($stub);
    }
});

test('без логина транспорт не авторизуется вовсе', function (): void {
    $stub = startSmtpStub();

    try {
        $transport = stubTransport($stub['port']);

        $transport->send(stubMessage('Письмо без авторизации'));
        $transport->close();

        $log = stopSmtpStub($stub);
        $stub = null;

        assertNotContains('AUTH', $log, 'серверу без авторизации команду AUTH слать незачем');
    } finally {
        if ($stub !== null) {
            stopSmtpStub($stub);
        }
    }
});
