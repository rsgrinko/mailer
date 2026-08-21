<?php

declare(strict_types=1);

/**
 * Сквозные проверки HTTP: поднимаем настоящие ядра и ходим по всем маршрутам,
 * как это делает браузер. Ловят ровно то, что ломается при рефакторинге, —
 * забытое имя маршрута, отвалившуюся прослойку, ошибку во вьюхе.
 */

use Mailer\Http\ApiKernel;
use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Http\Router;
use Mailer\Repository\ProjectRepository;
use Mailer\Repository\RoleRepository;
use Mailer\Repository\TemplateRepository;
use Mailer\Repository\TransportRepository;
use Mailer\Repository\UserRepository;
use Mailer\MailService;
use Mailer\Support\Config;
use Mailer\Ui\UiKernel;

/**
 * Запрос к сервису.
 *
 * @param array<string, string> $headers
 * @param array<string, mixed>  $body
 * @param array<string, mixed>  $query
 */
function httpRequest(string $method, string $path, array $headers = [], array $body = [], array $query = []): Request
{
    $request           = new Request();
    $request->method   = strtoupper($method);
    $request->path     = rtrim($path, '/') === '' ? '/' : rtrim($path, '/');
    $request->query    = $query;
    $request->headers  = $headers;
    $request->rawBody  = $body === [] ? '' : (string) json_encode($body);
    $request->body     = $body;

    return $request;
}

/**
 * Данные, на которых гоняем страницы: проект с ключом, транспорт, шаблон, пользователь, письмо.
 *
 * @return array<string, mixed>
 */
function httpFixtures(): array
{
    static $fixtures = null;

    if ($fixtures !== null) {
        return $fixtures;
    }

    $created = (new ProjectRepository())->create(['name' => 'http-тест']);
    $project = $created['project'];
    $key     = $created['key'];

    $transport = (new TransportRepository())->create([
        'name'     => 'http-тест-null',
        'type'     => 'null',
        'settings' => [],
        'active'   => 1,
    ]);

    $template = (new TemplateRepository())->create([
        'name'    => 'http-тест-шаблон',
        'subject' => 'Привет, {{ name }}',
        'text'    => 'Тело письма',
    ]);

    $user = (new UserRepository())->create([
        'login'    => 'http-test',
        'password' => 'секрет123',
        'name'     => 'Проверка',
    ]);

    $accepted = (new MailService())->accept([
        'to'        => 'user@example.com',
        'subject'   => 'Письмо для проверки страниц',
        'text'      => 'Текст',
        'transport' => 'http-тест-null',
        'sync'      => true,
    ], $project);

    $message = (array) (new Mailer\Repository\MessageRepository())->find((int) $accepted['id']);

    // Роль берём существующую: страница /ui/roles/{id} должна открываться на живой записи
    $role = (array) (new RoleRepository())->admin();

    // Вебхук проекта и доставка по нему: у страниц раздела должны быть живые записи
    $subscription = (new Mailer\Repository\WebhookSubscriptionRepository())->create([
        'project_id' => (int) $project['id'],
        'name'       => 'http-тест-вебхук',
        'url'        => 'http://127.0.0.1:9/hook',
    ]);

    $delivery = (new Mailer\Repository\WebhookRepository())->enqueue([
        'uuid'            => Mailer\Support\Str::uuid(),
        'project_id'      => (int) $project['id'],
        'subscription_id' => $subscription,
        'message_id'      => (int) $message['id'],
        'url'             => 'http://127.0.0.1:9/hook',
        'event'           => Mailer\Webhook\Event::MESSAGE_SENT,
        'payload'         => ['data' => ['message' => ['id' => (string) $message['uuid']]]],
    ]);

    $fixtures = [
        'key'          => $key,
        'role'         => (int) ($role['id'] ?? 0),
        'project'      => (int) $project['id'],
        'transport'    => $transport,
        'template'     => $template,
        'user'         => (int) $user['id'],
        'message'      => (int) $message['id'],
        'uuid'         => (string) $message['uuid'],
        'subscription' => $subscription,
        'webhook'      => $delivery,
    ];

    return $fixtures;
}

test('все GET-страницы панели открываются', function (): void {
    Config::set('ui.auth', false);

    $ids    = httpFixtures();
    $kernel = new UiKernel();

    $router = new Router();
    $router->load(MAILER_ROOT . '/routes/ui.php');

    $checked = 0;

    foreach ($router->routes() as $route) {
        // Заглушку 404 и скачивание вложения проверяем отдельно: у образца вложений нет
        if (!$route->allows('GET') || str_contains($route->pattern, '{path') || str_ends_with($route->pattern, '/attachment')) {
            continue;
        }

        $path = str_replace(
            ['{id:\d+}', '{action}'],
            [(string) $ids['message'], 'send'],
            $route->pattern
        );

        // Идентификаторы у каждого раздела свои
        $sections = [
            'transports'    => 'transport',
            'projects'      => 'project',
            'templates'     => 'template',
            'users'         => 'user',
            'roles'         => 'role',
            'webhooks'      => 'webhook',
            'subscriptions' => 'subscription',
        ];

        foreach ($sections as $section => $key) {
            if (str_starts_with($route->pattern, '/ui/' . $section)) {
                $path = str_replace((string) $ids['message'], (string) $ids[$key], $path);
            }
        }

        $response = $kernel->handle(httpRequest('GET', $path));
        $checked++;

        assertSame(200, $response->status(), 'страница ' . $path . ' должна открываться');
        assertTrue(
            !str_contains($response->body(), 'Что-то пошло не так'),
            'страница ' . $path . ' отдала страницу ошибки'
        );
    }

    assertTrue($checked >= 15, 'проверено страниц: ' . $checked);

    // У письма без вложений скачивать нечего
    assertSame(404, $kernel->handle(httpRequest('GET', '/ui/messages/' . $ids['message'] . '/attachment', [], [], ['index' => 0]))->status());

    // Сырое письмо отдаётся файлом
    $raw = $kernel->handle(httpRequest('GET', '/ui/messages/' . $ids['message'] . '/raw'));
    assertSame(200, $raw->status());
    assertContains('Subject:', $raw->body());
});

test('в списке писем виден проект', function (): void {
    Config::set('ui.auth', false);

    $ids  = httpFixtures();
    $body = (new UiKernel())->handle(httpRequest('GET', '/ui/messages'))->body();

    assertContains('>Проект</th>', $body);
    assertContains('/ui/projects/' . $ids['project'] . '">http-тест</a>', $body);
});

test('панель не пускает неизвестный адрес и чужой метод', function (): void {
    Config::set('ui.auth', false);

    $kernel = new UiKernel();

    assertSame(404, $kernel->handle(httpRequest('GET', '/ui/такой-страницы-нет'))->status());
    assertSame(405, $kernel->handle(httpRequest('DELETE', '/ui/messages'))->status());
});

test('API отвечает на все свои маршруты', function (): void {
    $ids    = httpFixtures();
    $kernel = new ApiKernel();
    $auth   = ['authorization' => 'Bearer ' . $ids['key']];

    // Мониторинг ходит без ключа
    assertSame(200, $kernel->handle(httpRequest('GET', '/api/v1/health'))->status());

    // Без ключа и с неверным ключом — 401
    assertSame(401, $kernel->handle(httpRequest('GET', '/api/v1/messages'))->status());
    assertSame(401, $kernel->handle(httpRequest('GET', '/api/v1/messages', ['authorization' => 'Bearer нет']))->status());

    assertSame(200, $kernel->handle(httpRequest('GET', '/api/v1/messages', $auth))->status());
    assertSame(200, $kernel->handle(httpRequest('GET', '/api/v1/templates', $auth))->status());
    assertSame(200, $kernel->handle(httpRequest('GET', '/api/v1/messages/' . $ids['uuid'], $auth))->status());

    $created = $kernel->handle(httpRequest('POST', '/api/v1/messages', $auth, [
        'to'        => 'user@example.com',
        'subject'   => 'Письмо через API',
        'text'      => 'Текст',
        'transport' => 'http-тест-null',
    ]));

    assertSame(202, $created->status());

    $id = (string) (json_decode($created->body(), true)['id'] ?? '');
    assertTrue($id !== '', 'API должен вернуть идентификатор письма');

    // Письмо ещё в очереди: отменяем, возвращаем и отменяем снова — очередь после теста пустая
    assertSame(200, $kernel->handle(httpRequest('DELETE', '/api/v1/messages/' . $id, $auth))->status());
    assertSame(200, $kernel->handle(httpRequest('POST', '/api/v1/messages/' . $id . '/retry', $auth))->status());
    assertSame(200, $kernel->handle(httpRequest('DELETE', '/api/v1/messages/' . $id, $auth))->status());
});

test('данные запроса не прошли проверку — 422, а не 500', function (): void {
    $ids    = httpFixtures();
    $kernel = new ApiKernel();

    $response = $kernel->handle(httpRequest('POST', '/api/v1/messages', ['authorization' => 'Bearer ' . $ids['key']], [
        'subject' => 'Без получателя',
    ]));

    assertSame(422, $response->status());
    assertContains('to', $response->body());
});

test('форма без токена не проходит', function (): void {
    Config::set('ui.auth', false);

    $kernel = new UiKernel();
    $token  = Mailer\Ui\Csrf::token();

    // Ничего не прислали
    $denied = $kernel->handle(httpRequest('POST', '/ui/logout'));
    assertSame(403, $denied->status());
    assertContains('Форма устарела', $denied->body());

    // Прислали чужой токен
    assertSame(403, $kernel->handle(httpRequest('POST', '/ui/logout', [], ['_token' => 'чужой']))->status());

    // Свой токен — запрос проходит дальше
    $allowed = $kernel->handle(httpRequest('POST', '/ui/logout', [], ['_token' => $token]));
    assertSame(302, $allowed->status());

    // Токен можно передать и заголовком — так удобно из fetch
    $byHeader = $kernel->handle(httpRequest('POST', '/ui/logout', ['x-csrf-token' => Mailer\Ui\Csrf::token()]));
    assertSame(302, $byHeader->status());
});

test('на страницах панели есть скрытое поле с токеном', function (): void {
    Config::set('ui.auth', false);

    $body = (new UiKernel())->handle(httpRequest('GET', '/ui/transports'))->body();

    assertContains('name="_token"', $body);
    assertContains(Mailer\Ui\Csrf::token(), $body);
});

test('несуществующая запись возвращает в список раздела', function (): void {
    Config::set('ui.auth', false);

    $kernel = new UiKernel();

    foreach ([
        '/ui/projects/999999'   => '/ui/projects',
        '/ui/templates/999999'  => '/ui/templates',
        '/ui/transports/999999' => '/ui/transports',
        '/ui/users/999999'      => '/ui/users',
    ] as $path => $back) {
        $response = $kernel->handle(httpRequest('GET', $path));

        assertSame(302, $response->status(), $path . ' должен уводить в список');
        assertSame($back, $response->headers()['Location'] ?? '', 'вернуть должно на ' . $back);
    }
});

test('предпросмотр шаблона заполняется примером сам', function (): void {
    Config::set('ui.auth', false);

    $ids  = httpFixtures();
    $body = (new UiKernel())->handle(
        httpRequest('GET', '/ui/templates/' . $ids['template'], [], [], ['sample' => 'auto'])
    )->body();

    // По переменной {{ name }} подставилось имя, а не пустая заглушка
    assertContains('{&quot;name&quot;:&quot;Иван&quot;}', $body);
    assertContains('Привет, Иван', $body);
});

test('пробное письмо по шаблону уходит из карточки шаблона', function (): void {
    Config::set('ui.auth', false);

    $ids        = httpFixtures();
    $transports = new TransportRepository();

    // Пробному письму транспорт не указывают — берётся тот, что по умолчанию
    $transports->setDefault($ids['transport']);

    $response = (new UiKernel())->handle(httpRequest(
        'POST',
        '/ui/templates/' . $ids['template'] . '/send',
        ['x-csrf-token' => Mailer\Ui\Csrf::token()],
        ['to' => 'preview@example.com', 'sample' => '{"name":"Иван"}']
    ));

    assertSame(302, $response->status());

    $sent = (new Mailer\Repository\MessageRepository())->paginate(['search' => 'preview@example.com'], 1, 1);
    assertSame(1, $sent['total'], 'письмо по шаблону должно попасть в базу');
    assertSame('Привет, Иван', (string) $sent['items'][0]['subject'], 'тема берётся из шаблона');

    (new Mailer\Repository\MessageRepository())->delete((int) $sent['items'][0]['id']);
    $transports->update($ids['transport'], ['is_default' => false]);
});

test('стоп-лист работает через API', function (): void {
    $ids    = httpFixtures();
    $kernel = new ApiKernel();
    $auth   = ['authorization' => 'Bearer ' . $ids['key']];

    // Закрываем адрес и видим его в списке
    $created = $kernel->handle(httpRequest('POST', '/api/v1/suppressions', $auth, [
        'email'  => 'API@Example.com',
        'reason' => 'unsubscribe',
        'note'   => 'отписался сам',
    ]));

    assertSame(201, $created->status());
    assertSame('api@example.com', json_decode($created->body(), true)['email'] ?? '');

    $list = json_decode($kernel->handle(httpRequest('GET', '/api/v1/suppressions', $auth, [], ['search' => 'api@']))->body(), true);
    assertSame(1, $list['total'] ?? 0, 'закрытый адрес должен попасть в список проекта');

    // Кривой адрес не принимаем
    assertSame(422, $kernel->handle(httpRequest('POST', '/api/v1/suppressions', $auth, ['email' => 'не адрес']))->status());

    // Письмо такому адресу дальше приёма не идёт
    $message = $kernel->handle(httpRequest('POST', '/api/v1/messages', $auth, [
        'to'        => 'api@example.com',
        'subject'   => 'Письмо закрытому адресу',
        'text'      => 'Текст',
        'transport' => 'http-тест-null',
    ]));

    assertSame('suppressed', json_decode($message->body(), true)['status'] ?? '');

    // И открываем обратно
    assertSame(200, $kernel->handle(httpRequest('DELETE', '/api/v1/suppressions/api@example.com', $auth))->status());
    assertSame(404, $kernel->handle(httpRequest('DELETE', '/api/v1/suppressions/api@example.com', $auth))->status());

    $stored = (new Mailer\Repository\MessageRepository())->findAny((string) (json_decode($message->body(), true)['id'] ?? ''));
    if ($stored !== null) {
        (new Mailer\Repository\MessageRepository())->delete((int) $stored['id']);
    }
});
