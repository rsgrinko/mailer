<?php

declare(strict_types=1);

/**
 * Права и области видимости: кого куда пускают и чьи записи он там видит.
 *
 * Проверки идут через настоящее ядро панели — так ловится и забытое `can:` на новом
 * маршруте, и незакрытый фильтр в репозитории.
 */

use Mailer\Domain\Permission;
use Mailer\Domain\Scope;
use Mailer\Domain\Viewer;
use Mailer\Http\Router;
use Mailer\MailService;
use Mailer\Repository\EventRepository;
use Mailer\Repository\MessageRepository;
use Mailer\Repository\ProjectRepository;
use Mailer\Repository\RoleRepository;
use Mailer\Repository\TemplateRepository;
use Mailer\Repository\TransportRepository;
use Mailer\Repository\UserRepository;
use Mailer\Support\Config;
use Mailer\Ui\Auth;
use Mailer\Ui\UiKernel;

/**
 * Входим в панель от лица пользователя. Сессии в консоли нет, поэтому кладём
 * пользователя туда же, куда его кладёт Auth::login().
 */
function accessLogin(int $userId): void
{
    $_SESSION['ui_user'] = ['id' => $userId, 'login' => 'тест', 'seen' => time()];

    Auth::forget();
}

function accessLogout(): void
{
    unset($_SESSION['ui_user']);

    Auth::forget();
}

/**
 * Двое пользователей с ролью «свои данные», один без прав вовсе и записи первого.
 *
 * @return array<string, mixed>
 */
function accessFixtures(): array
{
    static $fixtures = null;

    if ($fixtures !== null) {
        return $fixtures;
    }

    $roles = new RoleRepository();
    $users = new UserRepository();

    $ownerRole = $roles->create(['name' => 'тест-владелец', 'permissions' => Permission::user()]);
    $emptyRole = $roles->create(['name' => 'тест-без-прав', 'permissions' => []]);

    // Роль «только смотреть»: все разделы видны, но менять в них нечего.
    // data.all — чтобы видеть чужие записи и не заводить ей своих
    $watchRole = $roles->create(['name' => 'тест-смотрящий', 'permissions' => [
        Permission::MESSAGES_VIEW,
        Permission::PROJECTS_VIEW,
        Permission::TRANSPORTS_VIEW,
        Permission::TEMPLATES_VIEW,
        Permission::SUPPRESSIONS_VIEW,
        Permission::WEBHOOKS_VIEW,
        Permission::SYSTEM_VIEW,
        Permission::DATA_ALL,
    ]]);

    $petr = $users->create(['login' => 'test-petr', 'password' => 'parol123', 'role_id' => $ownerRole]);
    $ivan = $users->create(['login' => 'test-ivan', 'password' => 'parol123', 'role_id' => $ownerRole]);
    $nick = $users->create(['login' => 'test-nick', 'password' => 'parol123', 'role_id' => $emptyRole]);
    $olga = $users->create(['login' => 'test-olga', 'password' => 'parol123', 'role_id' => $watchRole]);

    $created = (new ProjectRepository())->create(['name' => 'проект-петра', 'owner_id' => (int) $petr['id']]);

    $transport = (new TransportRepository())->create([
        'name'     => 'транспорт-петра',
        'type'     => 'null',
        'settings' => [],
        'owner_id' => (int) $petr['id'],
    ]);

    $shared = (new TransportRepository())->create([
        'name'     => 'транспорт-общий',
        'type'     => 'null',
        'settings' => [],
        'shared'   => true,
    ]);

    $template = (new TemplateRepository())->create([
        'name'     => 'шаблон-петра',
        'text'     => 'Тело',
        'owner_id' => (int) $petr['id'],
    ]);

    $accepted = (new MailService())->accept([
        'to'        => 'user@example.com',
        'subject'   => 'Письмо Петра',
        'text'      => 'Текст',
        'transport' => 'транспорт-петра',
        'sync'      => true,
    ], $created['project']);

    $fixtures = [
        'roles'     => [$ownerRole, $emptyRole, $watchRole],
        'petr'      => (int) $petr['id'],
        'ivan'      => (int) $ivan['id'],
        'nick'      => (int) $nick['id'],
        'olga'      => (int) $olga['id'],
        'project'   => (int) $created['project']['id'],
        'transport' => $transport,
        'shared'    => $shared,
        'template'  => $template,
        'message'   => (int) $accepted['id'],
    ];

    return $fixtures;
}

test('без прав по разделам панели не пройти', function (): void {
    Config::set('ui.auth', true);

    $ids    = accessFixtures();
    $kernel = new UiKernel();

    accessLogin($ids['nick']);

    // Обзор и свой профиль доступны всем вошедшим: иначе человек не сменит себе пароль
    assertSame(200, $kernel->handle(httpRequest('GET', '/ui/'))->status());
    assertSame(200, $kernel->handle(httpRequest('GET', '/ui/profile'))->status());

    $router = new Router();
    $router->load(MAILER_ROOT . '/routes/ui.php');

    $closed = 0;

    foreach ($router->routes() as $route) {
        // Заглушка 404, обзор и профиль прав не требуют
        if (!$route->allows('GET') || str_contains($route->pattern, '{path')) {
            continue;
        }

        if (in_array($route->pattern, ['/ui/', '/ui', '/ui/profile', '/ui/login', '/ui/setup'], true)) {
            continue;
        }

        $path = str_replace(['{id:\d+}', '{action}'], [(string) $ids['project'], 'send'], $route->pattern);

        $status = $kernel->handle(httpRequest('GET', $path))->status();
        $closed++;

        assertSame(403, $status, 'страница ' . $path . ' не должна открываться без прав');
    }

    assertTrue($closed >= 10, 'проверено закрытых страниц: ' . $closed);

    accessLogout();
});

test('пользователь видит только свои записи', function (): void {
    Config::set('ui.auth', true);

    $ids    = accessFixtures();
    $kernel = new UiKernel();

    accessLogin($ids['ivan']);

    // В списках чужого нет
    foreach ([
        '/ui/projects'   => 'проект-петра',
        '/ui/transports' => 'транспорт-петра',
        '/ui/templates'  => 'шаблон-петра',
        '/ui/messages'   => 'Письмо Петра',
    ] as $path => $needle) {
        $response = $kernel->handle(httpRequest('GET', $path));

        assertSame(200, $response->status(), 'страница ' . $path . ' должна открываться');
        assertTrue(
            !str_contains($response->body(), $needle),
            'в ' . $path . ' не должно быть чужого: ' . $needle
        );
    }

    // Общий транспорт администратора виден: без него отправлять не через что
    assertContains('транспорт-общий', $kernel->handle(httpRequest('GET', '/ui/transports'))->body());

    // Чужая карточка для пользователя просто не существует — уводим в список
    assertSame(302, $kernel->handle(httpRequest('GET', '/ui/projects/' . $ids['project']))->status());
    assertSame(302, $kernel->handle(httpRequest('GET', '/ui/transports/' . $ids['transport']))->status());
    assertSame(302, $kernel->handle(httpRequest('GET', '/ui/templates/' . $ids['template']))->status());
    assertSame(302, $kernel->handle(httpRequest('GET', '/ui/messages/' . $ids['message']))->status());

    // А владельцу — открывается
    accessLogin($ids['petr']);

    assertSame(200, $kernel->handle(httpRequest('GET', '/ui/projects/' . $ids['project']))->status());
    assertContains('Письмо Петра', $kernel->handle(httpRequest('GET', '/ui/messages'))->body());

    accessLogout();
});

test('область видимости у репозиториев одна на всех', function (): void {
    $ids   = accessFixtures();
    $scope = Scope::owner($ids['ivan']);

    assertSame(null, (new ProjectRepository())->find($ids['project'], $scope));
    assertSame(null, (new TemplateRepository())->find($ids['template'], $scope));
    assertSame(null, (new MessageRepository())->find($ids['message'], $scope));
    assertSame(null, (new TransportRepository())->find($ids['transport'], $scope));

    // Общий транспорт — исключение: его видят все
    assertTrue((new TransportRepository())->find($ids['shared'], $scope) !== null);

    // Администратору видно всё
    assertTrue((new ProjectRepository())->find($ids['project'], Scope::all()) !== null);
});

test('право открывает раздел, а доступ к чужим данным — область видимости', function (): void {
    $viewer = Viewer::fromUser(['id' => 7, 'login' => 'x', 'permissions' => [Permission::PROJECTS_VIEW]]);

    assertTrue($viewer->can(Permission::PROJECTS_VIEW));
    assertFalse($viewer->can(Permission::PROJECTS_MANAGE));
    assertFalse($viewer->isAdmin());
    assertSame(7, $viewer->scope()->ownerId());

    $admin = Viewer::fromUser(['id' => 8, 'login' => 'a', 'permissions' => [Permission::DATA_ALL]]);

    assertTrue($admin->isAdmin());
    assertTrue($admin->scope()->isAll());

    // Выключенная авторизация панели — это полный доступ, как было до разделения прав
    assertTrue(Viewer::full()->can(Permission::SYSTEM_MANAGE));
});

test('лента событий не теряет свои записи за чужими', function (): void {
    $ids    = accessFixtures();
    $events = new EventRepository();

    // Ничьё письмо: его события лягут поверх событий Петра и вытеснят их из окна
    $noise = (new MailService())->accept([
        'to'        => 'noise@example.com',
        'subject'   => 'Шум в ленте событий',
        'text'      => 'Текст',
        'transport' => 'транспорт-петра',
        'sync'      => true,
    ]);

    for ($i = 0; $i < 60; $i++) {
        $events->add((int) $noise['id'], EventRepository::ATTEMPT, 'шум №' . $i);
    }

    // Окно первого шага — limit * 20, то есть 40 событий: своё в него уже не попадает
    $own = $events->latest(2, Scope::owner($ids['petr']));

    assertSame(2, count($own), 'лента владельца должна набрать запрошенное число событий');

    foreach ($own as $event) {
        assertSame('Письмо Петра', (string) $event['subject'], 'в ленту попало чужое событие');
    }

    // Администратору видно всё, и свежее — это шум
    $all = $events->latest(2, Scope::all());

    assertSame('Шум в ленте событий', (string) $all[0]['subject']);

    (new MessageRepository())->delete((int) $noise['id']);
});

test('роль только на просмотр не видит кнопок правки', function (): void {
    Config::set('ui.auth', true);

    $ids    = accessFixtures();
    $kernel = new UiKernel();

    accessLogin($ids['olga']);

    // Страница => чего на ней быть не должно
    $forbidden = [
        '/ui/projects'                        => ['Создать проект'],
        '/ui/projects/' . $ids['project']     => ['name="name"', 'Сохранить', 'Выдать новый ключ', 'Удалить проект'],
        '/ui/templates'                       => ['Создать шаблон'],
        '/ui/templates/' . $ids['template']   => ['<textarea', 'Сохранить', 'Удалить шаблон'],
        '/ui/transports'                      => ['Добавить транспорт'],
        '/ui/transports/' . $ids['transport'] => ['name="host"', 'Сохранить', 'Проверить подключение', 'Удалить транспорт'],
        '/ui/messages'                        => ['Массовые действия'],
        '/ui/messages/' . $ids['message']     => ['Отправить сейчас', 'Вернуть в очередь', 'Написать похожее'],
        '/ui/webhooks'                        => ['Разослать сейчас'],
        '/ui/system'                          => ['Обслуживание', 'Перезапустить воркер'],
        '/ui/suppressions'                    => ['Закрыть адрес'],
    ];

    foreach ($forbidden as $path => $needles) {
        $response = $kernel->handle(httpRequest('GET', $path));

        assertSame(200, $response->status(), 'страница ' . $path . ' должна открываться на просмотр');

        foreach ($needles as $needle) {
            assertTrue(
                !str_contains($response->body(), $needle),
                'на ' . $path . ' не должно быть «' . $needle . '»: править эта роль не может'
            );
        }
    }

    // Данные при этом видны: просмотр остаётся просмотром
    assertContains('проект-петра', $kernel->handle(httpRequest('GET', '/ui/projects'))->body());
    assertContains('шаблон-петра', $kernel->handle(httpRequest('GET', '/ui/templates/' . $ids['template']))->body());

    accessLogout();
});

test('владелец кнопки правки видит', function (): void {
    Config::set('ui.auth', true);

    $ids    = accessFixtures();
    $kernel = new UiKernel();

    accessLogin($ids['petr']);

    // Обратная проверка к предыдущему тесту: с правом кнопки на месте
    assertContains('Создать проект', $kernel->handle(httpRequest('GET', '/ui/projects'))->body());
    assertContains('Сохранить', $kernel->handle(httpRequest('GET', '/ui/projects/' . $ids['project']))->body());
    assertContains('Массовые действия', $kernel->handle(httpRequest('GET', '/ui/messages'))->body());
    assertContains('Проверить подключение', $kernel->handle(httpRequest('GET', '/ui/transports/' . $ids['transport']))->body());

    accessLogout();
});

test('обзор показывает только разрешённые разделы', function (): void {
    Config::set('ui.auth', true);

    $ids    = accessFixtures();
    $kernel = new UiKernel();

    // У Николая прав нет вовсе: цифры и таблицы ему показывать нечего
    accessLogin($ids['nick']);
    $body = $kernel->handle(httpRequest('GET', '/ui/'))->body();

    assertSame(200, $kernel->handle(httpRequest('GET', '/ui/'))->status());
    assertTrue(!str_contains($body, 'Последние письма'), 'без права на письма их на обзоре быть не должно');
    assertTrue(!str_contains($body, 'Транспорты</h2>'), 'без права на транспорты их таблицы быть не должно');
    assertContains('нет прав ни на один раздел', $body);

    // А Ольга смотрит всё
    accessLogin($ids['olga']);
    $body = $kernel->handle(httpRequest('GET', '/ui/'))->body();

    assertContains('Последние письма', $body);
    assertContains('Транспорты</h2>', $body);

    accessLogout();
});

test('с UI_ALLOW_ACTIONS=false кнопок над письмами нет, а запрос отклоняется', function (): void {
    Config::set('ui.auth', true);
    Config::set('ui.allow_actions', false);

    $ids    = accessFixtures();
    $kernel = new UiKernel();

    accessLogin($ids['petr']);

    $list = $kernel->handle(httpRequest('GET', '/ui/messages'))->body();
    $card = $kernel->handle(httpRequest('GET', '/ui/messages/' . $ids['message']))->body();

    assertTrue(!str_contains($list, 'Массовые действия'), 'массовых действий быть не должно');
    assertTrue(!str_contains($card, 'Вернуть в очередь'), 'кнопок над письмом быть не должно');

    // И даже если запрос отправить руками, он ничего не сделает
    $response = $kernel->handle(httpRequest(
        'POST',
        '/ui/messages/bulk',
        ['x-csrf-token' => Mailer\Ui\Csrf::token()],
        ['status' => 'sent', 'action' => 'delete']
    ));

    assertSame(302, $response->status());
    assertTrue((new MessageRepository())->find($ids['message']) !== null, 'письмо должно остаться на месте');

    Config::set('ui.allow_actions', true);
    accessLogout();
});

test('прибираем за собой данные проверок прав', function (): void {
    $ids = accessFixtures();

    (new MessageRepository())->delete($ids['message']);
    (new ProjectRepository())->delete($ids['project']);
    (new TemplateRepository())->delete($ids['template']);
    (new TransportRepository())->delete($ids['transport']);
    (new TransportRepository())->delete($ids['shared']);

    $users = new UserRepository();
    foreach (['petr', 'ivan', 'nick', 'olga'] as $key) {
        $users->delete($ids[$key], true);
    }

    $roles = new RoleRepository();
    foreach ($ids['roles'] as $roleId) {
        $roles->delete($roleId);
    }

    Config::set('ui.auth', false);
    accessLogout();

    assertSame(null, (new MessageRepository())->find($ids['message']));
});
