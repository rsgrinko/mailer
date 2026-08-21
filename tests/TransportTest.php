<?php

declare(strict_types=1);

/**
 * Транспорты, до которых не доходили руки: чередование по кругу, sendmail,
 * запись в .eml и сборка транспорта из строки базы.
 *
 * Настоящая сеть не нужна: sendmail подменяется скриптом, который просто
 * записывает пришедшее письмо в файл.
 */

use Mailer\Message\Address;
use Mailer\Message\Message;
use Mailer\Repository\SettingRepository;
use Mailer\Repository\TransportRepository;
use Mailer\Transport\BaseTransport;
use Mailer\Transport\LogTransport;
use Mailer\Transport\NullTransport;
use Mailer\Transport\RoundRobinTransport;
use Mailer\Transport\SendmailTransport;
use Mailer\Transport\TransportException;
use Mailer\Transport\TransportFactory;

/**
 * Письмо-образец.
 */
function transportMessage(string $subject = 'Проверка транспорта'): Message
{
    $message = new Message();
    $message->from = new Address('from@example.com', 'Отправитель');
    $message->addTo('to@example.com');
    $message->subject = $subject;
    $message->text    = 'текст письма';

    return $message;
}

/**
 * Транспорт, который считает отправки и умеет ломаться.
 */
function countingTransport(string $name, bool $broken = false, bool $temporary = true): BaseTransport
{
    return new class ($name, ['broken' => $broken, 'temporary' => $temporary]) extends BaseTransport {
        public int $sent = 0;

        public function type(): string
        {
            return 'test';
        }

        public function send(Message $message): string
        {
            $this->sent++;

            if ((bool) $this->setting('broken', false)) {
                throw (bool) $this->setting('temporary', true)
                    ? TransportException::temporary('сервер недоступен')
                    : TransportException::permanent('ящик не существует');
            }

            return 'отправлено через ' . $this->name();
        }
    };
}

test('набор по кругу чередует транспорты и помнит место', function (): void {
    $first  = countingTransport('первый');
    $second = countingTransport('второй');

    $set = new RoundRobinTransport('круг-тест', [], [$first, $second]);

    assertContains('первый', $set->send(transportMessage()));
    assertContains('второй', $set->send(transportMessage()));
    assertContains('первый', $set->send(transportMessage()), 'после последнего снова первый');

    assertSame(2, $first->sent);
    assertSame(1, $second->sent);

    // Позиция лежит в settings, иначе после перезапуска воркера круг начинался бы заново
    assertSame('1', (new SettingRepository())->get('roundrobin:круг-тест', '0'));
});

test('набор по кругу пропускает упавший транспорт', function (): void {
    $broken  = countingTransport('битый', true);
    $working = countingTransport('живой');

    $set = new RoundRobinTransport('круг-с-битым', [], [$broken, $working]);

    assertContains('живой', $set->send(transportMessage()), 'письмо должно уйти вторым транспортом');
    assertSame(1, $broken->sent, 'битый должен быть опрошен');
});

test('пустой набор по кругу — постоянная ошибка', function (): void {
    $error = assertThrows(static fn () => (new RoundRobinTransport('круг-пустой', [], []))->send(transportMessage()));

    assertTrue($error instanceof TransportException);
    assertFalse($error->isTemporary(), 'без транспортов повторять нечего');
});

test('когда весь набор по кругу лёг, ошибка временная', function (): void {
    $set = new RoundRobinTransport('круг-мёртвый', [], [
        countingTransport('первый-битый', true),
        countingTransport('второй-битый', true),
    ]);

    $error = assertThrows(static fn () => $set->send(transportMessage()));

    assertTrue($error instanceof TransportException);
    assertTrue($error->isTemporary(), 'временные ошибки должны оставлять письмо в очереди');
});

test('постоянные ошибки всего набора не отправляют письмо в повтор', function (): void {
    $set = new RoundRobinTransport('круг-отказ', [], [
        countingTransport('отказ-1', true, false),
        countingTransport('отказ-2', true, false),
    ]);

    $error = assertThrows(static fn () => $set->send(transportMessage()));

    assertFalse($error->isTemporary(), 'отказ по ящику повторять бессмысленно');
});

test('sendmail получает письмо на вход и обратный адрес', function (): void {
    if (!function_exists('proc_open')) {
        skipTest('proc_open выключен');
    }

    $out = (string) tempnam(sys_get_temp_dir(), 'sendmail-');

    // Подменяем sendmail скриптом: он пишет stdin и свои аргументы в файл
    $transport = new SendmailTransport('sendmail-тест', [
        'path'         => PHP_BINARY,
        'extra_params' => escapeshellarg(MAILER_ROOT . '/tests/sendmail-stub.php') . ' ' . escapeshellarg($out),
    ]);

    $result = $transport->send(transportMessage('Письмо через sendmail'));

    assertContains('sendmail', $result);

    $written = (string) file_get_contents($out);

    assertContains('Subject:', $written, 'на вход sendmail должно прийти письмо целиком');
    assertContains('to@example.com', $written);
    assertContains('-ffrom@example.com', $written, 'обратный адрес передаётся ключом -f');

    @unlink($out);
});

test('нет sendmail — постоянная ошибка, а не повтор', function (): void {
    $transport = new SendmailTransport('sendmail-нет', ['path' => MAILER_ROOT . '/var/tmp/нет-такого-sendmail']);

    $error = assertThrows(static fn () => $transport->send(transportMessage()));

    assertTrue($error instanceof TransportException);
    assertFalse($error->isTemporary(), 'отсутствующий бинарник сам не появится');
});

test('log-транспорт складывает письмо в .eml', function (): void {
    $dir = MAILER_ROOT . '/var/tmp/log-transport-' . getmypid();

    $result = (new LogTransport('лог-тест', ['dir' => $dir]))->send(transportMessage('Письмо в файл'));

    $files = glob($dir . '/*.eml') ?: [];

    assertCount(1, $files, 'письмо должно лечь одним файлом');
    assertContains(basename($files[0]), $result, 'в ответе должен быть путь к файлу');

    $body = (string) file_get_contents($files[0]);

    assertContains('To: to@example.com', $body);
    assertContains('text/plain', $body);

    array_map('unlink', $files);
    @rmdir($dir);
});

test('транспорт собирается из строки базы по типу', function (): void {
    $factory = new TransportFactory();

    $types = [
        'null'     => NullTransport::class,
        'log'      => LogTransport::class,
        'sendmail' => SendmailTransport::class,
    ];

    foreach ($types as $type => $class) {
        $transport = $factory->fromRow([
            'id'       => 0,
            'name'     => 'сборка-' . $type,
            'type'     => $type,
            'settings' => [],
        ]);

        assertTrue($transport instanceof $class, 'для типа ' . $type . ' собрался не тот класс');
        assertSame($type, $transport->type());
    }
});

test('неизвестный тип транспорта не собирается', function (): void {
    $error = assertThrows(static fn () => (new TransportFactory())->fromRow([
        'id'       => 0,
        'name'     => 'марсианский',
        'type'     => 'марсианский',
        'settings' => [],
    ]));

    assertContains('марсианский', $error->getMessage());
});

test('набор по кругу с транспортами из базы ходит по обоим', function (): void {
    $transports = new TransportRepository();

    foreach (['круг-часть-1', 'круг-часть-2'] as $name) {
        if ($transports->findByName($name) === null) {
            $transports->create(['name' => $name, 'type' => 'null', 'settings' => []]);
        }
    }

    $set = (new TransportFactory())->fromRow([
        'id'       => 0,
        'name'     => 'круг-из-базы',
        'type'     => 'roundrobin',
        'settings' => ['transports' => ['круг-часть-1', 'круг-часть-2']],
    ]);

    assertContains('круг-часть-1', $set->send(transportMessage()));
    assertContains('круг-часть-2', $set->send(transportMessage()));

    afterTests(static function () use ($transports): void {
        foreach (['круг-часть-1', 'круг-часть-2'] as $name) {
            $row = $transports->findByName($name);

            if ($row !== null) {
                $transports->delete((int) $row['id']);
            }
        }
    });
});
