<?php

declare(strict_types=1);

/**
 * Тесты вспомогательных частей: шаблоны, роутер, ключи, шифрование, DKIM, транспорты.
 */

use Mailer\Dkim\Signer;
use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Http\Router;
use Mailer\Message\Address;
use Mailer\Message\Message;
use Mailer\Security\ApiKey;
use Mailer\Security\Crypto;
use Mailer\Repository\ProjectRepository;
use Mailer\Repository\TemplateRepository;
use Mailer\Support\Config;
use Mailer\Template\Renderer;
use Mailer\Transport\BaseTransport;
use Mailer\Transport\FailoverTransport;
use Mailer\Transport\NullTransport;
use Mailer\Transport\TransportException;
use Mailer\Transport\TransportInterface;

test('шаблон подставляет переменные и экранирует HTML', function (): void {
    $renderer = new Renderer();

    $result = $renderer->render('Привет, {{ name }}!', ['name' => '<b>Иван</b>']);
    assertSame('Привет, &lt;b&gt;Иван&lt;/b&gt;!', $result);

    $raw = $renderer->render('{{{ name }}}', ['name' => '<b>Иван</b>']);
    assertSame('<b>Иван</b>', $raw);
});

test('шаблон умеет вложенные значения', function (): void {
    $result = (new Renderer())->render('{{ user.email }}', ['user' => ['email' => 'a@example.com']], false);

    assertSame('a@example.com', $result);
});

test('переменные шаблона собираются списком', function (): void {
    $variables = (new Renderer())->variables('{{ name }}', '<p>{{ site }}</p>', '{{{ footer }}}');

    assertTrue(in_array('name', $variables, true));
    assertTrue(in_array('site', $variables, true));
    assertTrue(in_array('footer', $variables, true));
});

test('письмо по шаблону берёт тему и тело из базы', function (): void {
    $templates = new TemplateRepository();

    if ($templates->findByName('тест-шаблон') === null) {
        $templates->create([
            'name'    => 'тест-шаблон',
            'subject' => 'Здравствуйте, {{ name }}',
            'html'    => '<p>Ваш код: {{ code }}</p>',
            'text'    => 'Ваш код: {{ code }}',
        ]);
    }

    $built = (new Mailer\Message\MessageFactory())->build([
        'to'            => 'user@example.com',
        'template'      => 'тест-шаблон',
        'template_data' => ['name' => 'Иван', 'code' => '1234'],
    ]);

    assertSame('Здравствуйте, Иван', $built['message']->subject);
    assertContains('1234', $built['message']->html);
});

test('роутер разбирает параметры пути', function (): void {
    $router = new Router();
    $router->get('/api/v1/messages/{id}', static fn (Request $r, array $p): Response => Response::json(['id' => $p['id']]));

    $request         = new Request();
    $request->method = 'GET';
    $request->path   = '/api/v1/messages/abc-123';
    $request->query  = [];
    $request->headers = [];
    $request->rawBody = '';
    $request->body    = [];

    $response = $router->dispatch($request);

    assertSame(200, $response->status());
    assertContains('abc-123', $response->body());
});

test('роутер отвечает 404 и 405', function (): void {
    $router = new Router();
    $router->get('/api/v1/health', static fn (Request $r, array $p): Response => Response::json(['ok' => true]));

    $request          = new Request();
    $request->method  = 'GET';
    $request->path    = '/нет-такого';
    $request->query   = [];
    $request->headers = [];
    $request->rawBody = '';
    $request->body    = [];

    assertSame(404, $router->dispatch($request)->status());

    $request->method = 'POST';
    $request->path   = '/api/v1/health';

    assertSame(405, $router->dispatch($request)->status());
});

test('API-ключ проверяется по хешу', function (): void {
    $key = ApiKey::generate();

    assertTrue(ApiKey::matches($key['key'], $key['hash']));
    assertFalse(ApiKey::matches('mlr_чужой_ключ', $key['hash']));
    assertSame($key['prefix'], ApiKey::prefix($key['key']));
});

test('проект находится по своему ключу', function (): void {
    $projects = new ProjectRepository();
    $created  = $projects->create(['name' => 'проект-для-ключа']);

    $found = $projects->findByApiKey($created['key']);

    assertTrue($found !== null);
    assertSame('проект-для-ключа', (string) $found['name']);
    assertTrue($projects->findByApiKey('mlr_abcdefgh_нетакого') === null);
});

test('шифрование настроек возвращает исходное значение', function (): void {
    Config::set('app.key', Crypto::generateKey());

    $encrypted = Crypto::encrypt('секретный-пароль');

    assertNotContains('секретный-пароль', $encrypted);
    assertSame('секретный-пароль', Crypto::decrypt($encrypted));
});

test('DKIM-подпись добавляется в письмо', function (): void {
    // На Windows без openssl.cnf ключ не создать — там тест пропускаем
    $pair = @openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if ($pair === false) {
        while (openssl_error_string() !== false) {
            // вычищаем очередь ошибок openssl, чтобы они не всплыли в следующем тесте
        }

        skipTest('openssl не может создать ключ в этом окружении (нет openssl.cnf)');
    }

    openssl_pkey_export($pair, $privateKey);

    $mime = "From: sender@example.com\r\nTo: user@example.com\r\nSubject: тема\r\n"
        . "Date: " . date('r') . "\r\nMIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n\r\nтело письма\r\n";

    $signed = (new Signer('example.com', 'mail', $privateKey))->sign($mime);

    assertContains('DKIM-Signature:', $signed);
    assertContains('d=example.com', $signed);
    assertContains('s=mail', $signed);
    assertContains('bh=', $signed);
});

test('заглушка отправки не падает', function (): void {
    $message = new Message();
    $message->from = new Address('from@example.com');
    $message->addTo('to@example.com');
    $message->subject = 'Тест';
    $message->text    = 'текст';

    $result = (new NullTransport('заглушка', []))->send($message);

    assertContains('отброшено', $result);
});

test('цепочка транспортов переходит к следующему после ошибки', function (): void {
    $broken = new class ('сломанный', []) extends BaseTransport {
        public function type(): string
        {
            return 'test';
        }

        public function send(Message $message): string
        {
            throw TransportException::temporary('сервер недоступен');
        }
    };

    $chain = new FailoverTransport('цепочка', [], [$broken, new NullTransport('рабочий', [])]);

    $message = new Message();
    $message->from = new Address('from@example.com');
    $message->addTo('to@example.com');
    $message->subject = 'Через цепочку';
    $message->text    = 'текст';

    $result = $chain->send($message);

    assertContains('рабочий', $result);
});

test('если все транспорты цепочки упали, ошибка остаётся временной', function (): void {
    $broken = new class ('сломанный', []) extends BaseTransport {
        public function type(): string
        {
            return 'test';
        }

        public function send(Message $message): string
        {
            throw TransportException::temporary('сервер недоступен');
        }
    };

    $chain = new FailoverTransport('цепочка-2', [], [$broken]);

    $message = new Message();
    $message->from = new Address('from@example.com');
    $message->addTo('to@example.com');
    $message->subject = 'Ошибка';
    $message->text    = 'текст';

    $error = assertThrows(static fn () => $chain->send($message));

    assertTrue($error instanceof TransportException);
    assertTrue($error->isTemporary());
});

test('старые логи удаляются, сегодняшний остаётся', function (): void {
    $dir = MAILER_ROOT . '/var/tmp/logs-test-' . getmypid();
    @mkdir($dir, 0775, true);

    $today = $dir . '/mailer-' . date('Y-m-d') . '.log';
    $old   = $dir . '/mailer-' . date('Y-m-d', strtotime('-40 days')) . '.log';
    $fresh = $dir . '/mailer-' . date('Y-m-d', strtotime('-3 days')) . '.log';

    foreach ([$today, $old, $fresh] as $file) {
        file_put_contents($file, 'строка лога');
    }

    // Возраст определяется по времени изменения файла
    touch($old, strtotime('-40 days'));
    touch($fresh, strtotime('-3 days'));

    $logger  = new Mailer\Support\Logger('test', $dir);
    $removed = $logger->purge(30);

    assertSame([basename($old)], $removed);
    assertTrue(is_file($today), 'сегодняшний лог трогать нельзя');
    assertTrue(is_file($fresh), 'свежий лог остаётся');

    assertSame([], $logger->purge(0), 'ноль дней — чистка выключена');

    array_map('unlink', (array) glob($dir . '/*.log'));
    @rmdir($dir);
});

test('транспорт с force_from подменяет отправителя, прежний уходит в Reply-To', function (): void {
    $transport = new class ('яндекс', [
        'from_email' => 'robot@yandex.ru',
        'from_name'  => 'Робот',
        'force_from' => true,
    ]) extends BaseTransport {
        public function type(): string
        {
            return 'test';
        }

        public function send(Message $message): string
        {
            return $this->render($message);
        }
    };

    $message = new Message();
    $message->from = new Address('noreply@ahtori.local', 'Сайт');
    $message->addTo('to@example.com');
    $message->subject = 'Проверка подмены';
    $message->text    = 'текст';

    $mime = $transport->send($message);

    assertSame('robot@yandex.ru', $message->from->email);
    assertSame('robot@yandex.ru', $message->sender());
    assertSame('noreply@ahtori.local', $message->replyTo?->email);
    assertContains('robot@yandex.ru', $mime);
});

test('без force_from свой отправитель письма остаётся', function (): void {
    $transport = new class ('обычный', ['from_email' => 'robot@yandex.ru']) extends BaseTransport {
        public function type(): string
        {
            return 'test';
        }

        public function send(Message $message): string
        {
            return $this->render($message);
        }
    };

    $message = new Message();
    $message->from = new Address('shop@example.com');
    $message->addTo('to@example.com');
    $message->subject = 'Свой отправитель';
    $message->text    = 'текст';

    $transport->send($message);

    assertSame('shop@example.com', $message->from->email);
    assertSame(null, $message->replyTo);
});

test('force_from правит отправителя и в готовом письме', function (): void {
    $transport = new class ('яндекс', [
        'from_email' => 'robot@yandex.ru',
        'force_from' => true,
    ]) extends BaseTransport {
        public function type(): string
        {
            return 'test';
        }

        public function send(Message $message): string
        {
            return $this->render($message);
        }
    };

    $raw = "From: Сайт <noreply@ahtori.local>\r\n"
        . "To: to@example.com\r\n"
        . "Subject: Готовое письмо\r\n"
        . "\r\n"
        . "тело";

    $message = new Message();
    $message->raw          = $raw;
    $message->from         = new Address('noreply@ahtori.local', 'Сайт');
    $message->envelopeFrom = 'noreply@ahtori.local';
    $message->addTo('to@example.com');
    $message->subject = 'Готовое письмо';

    $mime = $transport->send($message);

    assertSame('robot@yandex.ru', $message->sender());
    assertContains('From: robot@yandex.ru', $mime);
    assertContains('Reply-To:', $mime);
    assertTrue(!str_contains($mime, 'From: Сайт'), 'старый From должен исчезнуть');
    assertContains('тело', $mime);
});
