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

    $petr = $users->create(['login' => 'test-petr', 'password' => 'parol123', 'role_id' => $ownerRole]);
    $ivan = $users->create(['login' => 'test-ivan', 'password' => 'parol123', 'role_id' => $ownerRole]);
    $nick = $users->create(['login' => 'test-nick', 'password' => 'parol123', 'role_id' => $emptyRole]);

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
        'roles'     => [$ownerRole, $emptyRole],
        'petr'      => (int) $petr['id'],
        'ivan'      => (int) $ivan['id'],
        'nick'      => (int) $nick['id'],
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

test('прибираем за собой данные проверок прав', function (): void {
    $ids = accessFixtures();

    (new MessageRepository())->delete($ids['message']);
    (new ProjectRepository())->delete($ids['project']);
    (new TemplateRepository())->delete($ids['template']);
    (new TransportRepository())->delete($ids['transport']);
    (new TransportRepository())->delete($ids['shared']);

    $users = new UserRepository();
    foreach (['petr', 'ivan', 'nick'] as $key) {
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
