<?php

declare(strict_types=1);

/**
 * Механика доставки: подпись DKIM и расписание повторов.
 *
 * DKIM раньше проверялся по наличию заголовка — здесь подпись проверяется
 * настоящей криптографией, как это сделает почтовый сервер получателя.
 * Повторы — по шагам: сколько ждать после каждой неудачи и когда сдаваться.
 */

use Mailer\Dkim\Signer;
use Mailer\Message\Address;
use Mailer\Message\Message;
use Mailer\Message\MimeBuilder;
use Mailer\Queue\Queue;
use Mailer\Queue\Sender;
use Mailer\Repository\EventRepository;
use Mailer\Repository\MessageRepository;
use Mailer\Repository\TransportRepository;
use Mailer\Storage\Database;
use Mailer\Support\Config;

/**
 * Пара ключей для подписи. На Windows без openssl.cnf их не создать.
 *
 * @return array{private: string, public: string}
 */
function dkimKeys(): array
{
    $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    $pair    = @openssl_pkey_new($options);

    if ($pair === false) {
        // На Windows openssl не находит свой openssl.cnf — подсовываем минимальный,
        // иначе проверка подписи молча пропускалась бы на машине разработчика
        $config = (string) Config::get('paths.tmp', MAILER_ROOT . '/var/tmp') . '/openssl-dkim.cnf';

        if (!is_dir(dirname($config))) {
            mkdir(dirname($config), 0775, true);
        }

        file_put_contents($config, "[req]\ndistinguished_name = req_distinguished_name\n[req_distinguished_name]\n");

        $options['config'] = $config;
        $pair              = @openssl_pkey_new($options);

        afterTests(static function () use ($config): void {
            @unlink($config);
        });
    }

    if ($pair === false) {
        while (openssl_error_string() !== false) {
            // вычищаем очередь ошибок openssl, чтобы они не всплыли в соседнем тесте
        }

        skipTest('openssl не может создать ключ в этом окружении (нет openssl.cnf)');
    }

    $private = '';

    // Выгрузка ключа тоже смотрит в openssl.cnf, поэтому передаём те же настройки
    if (!@openssl_pkey_export($pair, $private, null, $options)) {
        skipTest('openssl не смог выгрузить ключ: ' . (string) openssl_error_string());
    }

    $details = (array) openssl_pkey_get_details($pair);

    return ['private' => $private, 'public' => (string) $details['key']];
}

/**
 * Собирает письмо для подписи.
 */
function dkimMessage(string $subject = 'Письмо с подписью'): string
{
    $message = new Message();
    $message->from = new Address('robot@example.com', 'Робот');
    $message->addTo('user@example.com');
    $message->subject = $subject;
    $message->text    = "Тело письма.\r\nВторая строка.\r\n";

    return (new MimeBuilder())->build($message);
}

/**
 * Достаёт из письма заголовок подписи целиком, вместе с продолжениями строк.
 */
function dkimHeader(string $signed): string
{
    $head = explode("\r\n\r\n", $signed, 2)[0];

    foreach (preg_split('/\r\n(?![ \t])/', $head) ?: [] as $line) {
        if (stripos($line, 'DKIM-Signature:') === 0) {
            return $line;
        }
    }

    return '';
}

/**
 * Разбирает заголовок DKIM-Signature на поля.
 *
 * @return array<string, string>
 */
function dkimFields(string $header): array
{
    $fields = [];

    foreach (explode(';', preg_replace('/\s+/', '', str_replace("\r\n", '', $header)) ?? '') as $part) {
        if (!str_contains($part, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $part, 2);

        $fields[trim($name)] = $value;
    }

    return $fields;
}

/**
 * Проверяет подпись так, как это сделает почтовый сервер получателя:
 * разворачивает заголовки, схлопывает пробелы, обнуляет b= и сверяет openssl.
 */
function dkimVerify(string $signed, string $publicKey): int
{
    [$head, $body] = explode("\r\n\r\n", $signed, 2);

    $header = dkimHeader($signed);
    $fields = dkimFields($header);

    $signature = base64_decode((string) ($fields['b'] ?? ''), true);

    if ($signature === false || $signature === '') {
        return -1;
    }

    // Сначала хеш тела: получатель считает его по тем же правилам relaxed —
    // хвостовые пробелы убраны, пробелы схлопнуты, пустые строки в конце срезаны
    $canonicalBody = preg_replace("/[ \t]+\r\n/", "\r\n", $body) ?? $body;
    $canonicalBody = preg_replace("/[ \t]+/", ' ', $canonicalBody) ?? $canonicalBody;
    $canonicalBody = rtrim($canonicalBody, "\r\n");
    $canonicalBody = $canonicalBody === '' ? '' : $canonicalBody . "\r\n";

    if (base64_encode(hash('sha256', $canonicalBody, true)) !== ($fields['bh'] ?? '')) {
        return 0;
    }

    $parts = [];

    foreach (explode(':', (string) ($fields['h'] ?? '')) as $name) {
        if (preg_match('/^' . preg_quote($name, '/') . ':(.*?)(?=\r\n[^ \t]|\z)/msi', $head, $found) !== 1) {
            continue;
        }

        $value = trim(preg_replace('/[ \t]+/', ' ', str_replace(["\r\n", "\r", "\n"], ' ', $found[1])) ?? '');

        $parts[] = strtolower($name) . ':' . $value;
    }

    // Сам заголовок подписи: развёрнутый, с пустым b=
    $canonical = preg_replace('/[ \t]+/', ' ', str_replace(["\r\n", "\r", "\n"], ' ', $header)) ?? '';
    $canonical = 'dkim-signature:' . trim(substr($canonical, strlen('DKIM-Signature:')));
    $canonical = preg_replace('/b=[^;]*$/', 'b=', $canonical) ?? $canonical;

    $parts[] = $canonical;

    return openssl_verify(implode("\r\n", $parts), $signature, $publicKey, OPENSSL_ALGO_SHA256);
}

test('подпись DKIM сходится у получателя', function (): void {
    $keys   = dkimKeys();
    $signed = (new Signer('example.com', 'mail', $keys['private']))->sign(dkimMessage());

    assertContains('DKIM-Signature:', $signed, 'заголовок подписи должен появиться');

    $fields = dkimFields(dkimHeader($signed));

    assertSame('rsa-sha256', $fields['a'] ?? '', 'алгоритм подписи');
    assertSame('relaxed/relaxed', $fields['c'] ?? '', 'канонизация');
    assertSame('example.com', $fields['d'] ?? '', 'домен');
    assertSame('mail', $fields['s'] ?? '', 'селектор');
    assertTrue(($fields['bh'] ?? '') !== '', 'хеш тела должен быть');

    // Главное: подпись проверяется так, как это делает почтовый сервер.
    // Раньше заголовок сворачивался посреди подписанных полей, и проверка падала
    assertSame(1, dkimVerify($signed, $keys['public']), 'подпись должна сойтись открытым ключом');
});

test('подписанное письмо нельзя подменить по дороге', function (): void {
    $keys   = dkimKeys();
    $signed = (new Signer('example.com', 'mail', $keys['private']))->sign(dkimMessage());

    [$head, $body] = explode("\r\n\r\n", $signed, 2);

    // Дописываем строку в тело — bh перестаёт сходиться
    $tamperedBody = $head . "\r\n\r\n" . $body . "Дописано по дороге.\r\n";

    assertSame(0, dkimVerify($tamperedBody, $keys['public']), 'подменённое тело подпись не пропускает');

    // Меняем тему — она в списке подписанных заголовков
    $tamperedHead = str_replace('Subject: =?', 'Subject: X=?', $signed);

    if ($tamperedHead !== $signed) {
        assertSame(0, dkimVerify($tamperedHead, $keys['public']), 'подменённую тему подпись не пропускает');
    }
});

test('строки заголовка подписи не длиннее допустимого', function (): void {
    $keys   = dkimKeys();
    $signed = (new Signer('example.com', 'mail', $keys['private']))->sign(dkimMessage());

    foreach (explode("\r\n", dkimHeader($signed)) as $line) {
        assertTrue(
            strlen($line) <= 100,
            'строка заголовка не должна быть длиннее 100 символов, а она ' . strlen($line)
        );
    }
});

test('после неудачи письмо ждёт по расписанию и сдаётся на последней попытке', function (): void {
    withOwnDatabase(static function (Database $db): void {
        // Транспорт с временной ошибкой: SMTP на порту, где никто не слушает.
        // Отказ в соединении — это «попробуйте позже», а не «такого ящика нет»
        (new TransportRepository())->create([
            'name'       => 'ошибочный',
            'type'       => 'smtp',
            'settings'   => [
                'host'        => '127.0.0.1',
                'port'        => freePort(),
                'encryption'  => 'none',
                'timeout'     => 2,
                'verify_peer' => false,
            ],
            'from_email' => 'noreply@example.com',
            'is_default' => true,
        ]);

        withConfig(['queue.backoff' => [60, 300, 900], 'queue.max_attempts' => 3], static function () use ($db): void {
            $messages = new MessageRepository();
            $queue    = new Queue();
            $sender   = new Sender();

            $id = (int) (new Mailer\MailService())->accept([
                'to'      => 'user@example.com',
                'subject' => 'Письмо, которое не уходит',
                'text'    => 'текст',
            ])['id'];

            $expected = [60, 300];

            foreach ($expected as $attempt => $delay) {
                $claimed = $queue->claim(10, 'тест-повторов');

                assertCount(1, $claimed, 'письмо должно быть в очереди перед попыткой ' . ($attempt + 1));

                $before = time();
                $result = $sender->send($claimed[0]);

                assertSame('queued', $result['status'], 'после временной ошибки письмо возвращается в очередь');

                $row = (array) $messages->find($id);

                assertSame($attempt + 1, (int) $row['attempts'], 'счётчик попыток должен расти');

                $wait = strtotime((string) $row['available_at']) - $before;

                assertTrue(
                    $wait >= $delay - 5 && $wait <= $delay + 5,
                    'после попытки ' . ($attempt + 1) . ' ждём около ' . $delay . ' с, получилось ' . $wait
                );

                // Отматываем время назад, чтобы не ждать по-настоящему
                $db->update('messages', ['available_at' => Database::now()], ['id' => $id]);
            }

            // Третья попытка — последняя: письмо признаётся неотправленным
            $claimed = $queue->claim(10, 'тест-повторов');
            $result  = $sender->send($claimed[0]);

            assertSame('failed', $result['status'], 'на последней попытке письмо помечается неудачным');

            $row = (array) $messages->find($id);

            assertSame(3, (int) $row['attempts']);
            assertTrue((string) $row['last_error'] !== '', 'причина должна сохраниться');

            $types = array_column((new EventRepository())->forMessage($id), 'type');

            assertTrue(in_array(EventRepository::RETRY, $types, true), 'в истории должны быть повторы');
            assertTrue(in_array(EventRepository::FAILED, $types, true), 'и окончательная неудача');
        });
    });
});

test('постоянная ошибка не даёт повторов', function (): void {
    withOwnDatabase(static function (): void {
        // Отсутствующий бинарник sendmail — ошибка, которая сама не исправится
        (new TransportRepository())->create([
            'name'       => 'отказной',
            'type'       => 'sendmail',
            'settings'   => ['path' => MAILER_ROOT . '/var/tmp/нет-такого-sendmail'],
            'from_email' => 'noreply@example.com',
            'is_default' => true,
        ]);

        withConfig(['queue.max_attempts' => 5], static function (): void {
            $messages = new MessageRepository();

            $id = (int) (new Mailer\MailService())->accept([
                'to'      => 'user@example.com',
                'subject' => 'Постоянный отказ',
                'text'    => 'текст',
            ])['id'];

            $claimed = (new Queue())->claim(10, 'тест-отказа');
            $result  = (new Sender())->send($claimed[0]);

            assertSame('failed', $result['status'], 'постоянная ошибка — сразу неудача, без повторов');

            $row = (array) $messages->find($id);

            assertSame(1, (int) $row['attempts'], 'попытка должна быть одна');
            assertTrue(
                (int) $row['attempts'] < (int) $row['max_attempts'],
                'попытки ещё оставались — письмо помечено неудачным не из-за их исчерпания'
            );
        });
    });
});
