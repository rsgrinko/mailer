<?php

declare(strict_types=1);

/**
 * Галка «запомнить меня»: долгая кука вместо повторного ввода пароля.
 *
 * Проверяем и то, что она пускает вернувшегося, и то, что не пускает никого другого:
 * подделанную куку, просроченный токен, токен отключённого пользователя и токен,
 * который уже использован (после входа он меняется).
 */

use Mailer\Repository\RememberTokenRepository;
use Mailer\Repository\UserRepository;
use Mailer\Storage\Database;
use Mailer\Ui\Auth;
use Mailer\Ui\Csrf;
use Mailer\Ui\UiKernel;

/**
 * Пользователь, которого будем запоминать.
 *
 * @return array<string, mixed>
 */
function rememberUser(): array
{
    $users = new UserRepository();
    $user  = $users->findByLogin('remember-me');

    if ($user === null) {
        $users->create([
            'login'    => 'remember-me',
            'password' => 'parol123',
            'name'     => 'Возвращенец',
            'role_id'  => (int) ((array) (new Mailer\Repository\RoleRepository())->admin())['id'],
        ]);

        $user = (array) $users->findByLogin('remember-me');

        afterTests(static function (): void {
            $users = new UserRepository();
            $row   = $users->findByLogin('remember-me');

            if ($row !== null) {
                $users->delete((int) $row['id'], true);
            }
        });
    }

    return (array) $user;
}

/**
 * Забываем всё, что осталось от прошлого теста: сессию, куку и кэш Auth.
 */
function rememberReset(): void
{
    $_SESSION = [];
    unset($_COOKIE[Auth::REMEMBER_COOKIE]);
    Auth::forget();
}

/**
 * Вход через настоящее ядро панели. Возвращает ответ — из него читаем Set-Cookie.
 */
function rememberLogin(bool $remember): Mailer\Http\Response
{
    rememberReset();

    // Токены от прошлых проверок мешают считать: каждый тест начинает с нуля
    (new RememberTokenRepository())->forgetUser((int) rememberUser()['id']);

    return (new UiKernel())->handle(httpRequest('POST', '/ui/login', ['x-csrf-token' => Csrf::token()], [
        'login'    => 'remember-me',
        'password' => 'parol123',
        'remember' => $remember ? 'on' : null,
    ]));
}

/**
 * Достаёт значение нашей куки из заголовка ответа.
 */
function rememberCookieValue(Mailer\Http\Response $response): string
{
    $header = (string) ($response->headers()['Set-Cookie'] ?? '');

    if (!str_starts_with($header, Auth::REMEMBER_COOKIE . '=')) {
        return '';
    }

    $value = substr($header, strlen(Auth::REMEMBER_COOKIE) + 1);
    $value = substr($value, 0, (int) strpos($value . ';', ';'));

    return rawurldecode($value);
}

test('без галки долгая кука не ставится', function (): void {
    withConfig(['ui.auth' => true], static function (): void {
        $user     = rememberUser();
        $response = rememberLogin(false);

        assertSame(302, $response->status(), 'вход должен пройти');
        assertSame('', rememberCookieValue($response), 'куку без галки ставить нечего');
        assertSame(0, (new RememberTokenRepository())->countForUser((int) $user['id']), 'токен не заводится');

        rememberReset();
    });
});

test('с галкой ставится кука и заводится токен', function (): void {
    withConfig(['ui.auth' => true], static function (): void {
        $user     = rememberUser();
        $response = rememberLogin(true);

        $cookie = rememberCookieValue($response);

        assertTrue($cookie !== '', 'кука должна прийти в ответе');
        assertMatches('/^[0-9a-f]{16}:[0-9a-f]{64}$/', $cookie, 'кука — это selector:validator');

        $header = (string) $response->headers()['Set-Cookie'];
        assertContains('HttpOnly', $header, 'куку не должен читать скрипт на странице');
        assertContains('SameSite=Lax', $header);
        assertContains('Max-Age=', $header, 'кука должна пережить закрытие браузера');

        assertSame(1, (new RememberTokenRepository())->countForUser((int) $user['id']));

        // Пароля и логина в куке быть не должно
        assertNotContains('parol123', $header);
        assertNotContains('remember-me', $header);

        rememberReset();
    });
});

test('по куке пускают в панель без пароля', function (): void {
    withConfig(['ui.auth' => true], static function (): void {
        $user   = rememberUser();
        $cookie = rememberCookieValue(rememberLogin(true));

        // Так выглядит следующий приход: сессии нет, кука есть
        rememberReset();
        $_COOKIE[Auth::REMEMBER_COOKIE] = $cookie;

        $response = (new UiKernel())->handle(httpRequest('GET', '/ui/'));

        assertStatus(200, $response, 'по куке должно пускать без входа');

        $current = assertNotNull(Auth::user(), 'пользователь должен определиться');
        assertSame((int) $user['id'], (int) $current['id']);

        rememberReset();
    });
});

test('использованная кука меняется на новую', function (): void {
    withConfig(['ui.auth' => true], static function (): void {
        $user  = rememberUser();
        $first = rememberCookieValue(rememberLogin(true));

        rememberReset();
        $_COOKIE[Auth::REMEMBER_COOKIE] = $first;

        $response = (new UiKernel())->handle(httpRequest('GET', '/ui/'));
        $second   = rememberCookieValue($response);

        assertTrue($second !== '', 'в ответ должна прийти новая кука');
        assertTrue($second !== $first, 'кука должна смениться');
        assertSame(1, (new RememberTokenRepository())->countForUser((int) $user['id']), 'токен остаётся один');

        // Половинка-адрес та же, секрет другой: по старой куке запись найдётся,
        // и по несошедшемуся секрету станет видно, что кукой пользуется кто-то ещё
        assertSame(explode(':', $first)[0], explode(':', $second)[0], 'selector должен остаться прежним');

        // Сразу после смены старая кука ещё принимается — это соседние запросы той же
        // страницы. А вот когда окно закроется, она гасит токен целиком: проверка на
        // это отдельным тестом («старая кука после окна снисхождения…»)
        rememberReset();
        $_COOKIE[Auth::REMEMBER_COOKIE] = $first;

        (new UiKernel())->handle(httpRequest('GET', '/ui/'));

        assertNotNull(Auth::user(), 'свои же параллельные запросы выкидывать нельзя');
        assertSame(1, (new RememberTokenRepository())->countForUser((int) $user['id']), 'токен остаётся');

        rememberReset();
    });
});

test('подделанная кука не работает', function (): void {
    withConfig(['ui.auth' => true], static function (): void {
        $user   = rememberUser();
        $cookie = rememberCookieValue(rememberLogin(true));

        [$selector] = explode(':', $cookie, 2);

        foreach ([
            $selector . ':' . str_repeat('a', 64),
            'нетакой:' . str_repeat('b', 64),
            $selector,
            'мусор',
        ] as $fake) {
            rememberReset();
            $_COOKIE[Auth::REMEMBER_COOKIE] = $fake;

            (new UiKernel())->handle(httpRequest('GET', '/ui/'));

            assertNull(Auth::user(), 'кука «' . mb_substr($fake, 0, 20) . '» не должна пускать');
        }

        rememberReset();
        (new RememberTokenRepository())->forgetUser((int) $user['id']);
    });
});

test('просроченный токен не пускает и удаляется', function (): void {
    withConfig(['ui.auth' => true], static function (): void {
        $user   = rememberUser();
        $cookie = rememberCookieValue(rememberLogin(true));

        [$selector] = explode(':', $cookie, 2);

        Database::instance()->update(
            'remember_tokens',
            ['expires_at' => date('Y-m-d H:i:s', time() - 60)],
            ['selector' => $selector]
        );

        rememberReset();
        $_COOKIE[Auth::REMEMBER_COOKIE] = $cookie;

        (new UiKernel())->handle(httpRequest('GET', '/ui/'));

        assertNull(Auth::user(), 'просроченный токен не пускает');
        assertSame(0, (new RememberTokenRepository())->countForUser((int) $user['id']), 'и в базе не остаётся');

        rememberReset();
    });
});

test('выход гасит токен и просит браузер забыть куку', function (): void {
    withConfig(['ui.auth' => true], static function (): void {
        $user   = rememberUser();
        $cookie = rememberCookieValue(rememberLogin(true));

        rememberReset();
        $_COOKIE[Auth::REMEMBER_COOKIE] = $cookie;

        // Заходим по куке и сразу выходим. Новую куку запоминаем, как это сделал бы
        // браузер, — иначе выход придёт со старой
        $entered = (new UiKernel())->handle(httpRequest('GET', '/ui/'));

        $_COOKIE[Auth::REMEMBER_COOKIE] = rememberCookieValue($entered);

        $response = (new UiKernel())->handle(
            httpRequest('POST', '/ui/logout', ['x-csrf-token' => Csrf::token()])
        );

        assertSame(302, $response->status());
        assertContains('Max-Age=0', (string) $response->headers()['Set-Cookie'], 'куку надо погасить');
        assertSame(0, (new RememberTokenRepository())->countForUser((int) $user['id']), 'токен должен исчезнуть');

        rememberReset();
    });
});

test('смена пароля отменяет все «запомнить меня»', function (): void {
    withConfig(['ui.auth' => true], static function (): void {
        $user   = rememberUser();
        $tokens = new RememberTokenRepository();
        $cookie = rememberCookieValue(rememberLogin(true));

        assertSame(1, $tokens->countForUser((int) $user['id']));

        (new UserRepository())->setPassword((int) $user['id'], 'parol123');

        assertSame(0, $tokens->countForUser((int) $user['id']), 'после смены пароля куки не работают');

        rememberReset();
        $_COOKIE[Auth::REMEMBER_COOKIE] = $cookie;

        (new UiKernel())->handle(httpRequest('GET', '/ui/'));

        assertNull(Auth::user(), 'старая кука после смены пароля не пускает');

        rememberReset();
    });
});

test('токен отключённого пользователя не пускает', function (): void {
    withOwnDatabase(static function (): void {
        $users = new UserRepository();

        $users->create(['login' => 'ostanetsya', 'password' => 'parol123']);
        $created = $users->create(['login' => 'otklyuchennyy', 'password' => 'parol123']);

        $tokens = new RememberTokenRepository();
        $cookie = $tokens->issue((int) $created['id'], 30);

        $users->update((int) $created['id'], ['active' => false]);

        // Отключение само по себе гасит токены — заводим ещё один, чтобы проверить
        // и вторую защиту: Auth сверяет активность пользователя
        $cookie = $tokens->issue((int) $created['id'], 30);

        rememberReset();
        $_COOKIE[Auth::REMEMBER_COOKIE] = $cookie;

        withConfig(['ui.auth' => true], static function (): void {
            (new UiKernel())->handle(httpRequest('GET', '/ui/'));
        });

        assertNull(Auth::user(), 'отключённого пускать нельзя');
        assertSame(0, $tokens->countForUser((int) $created['id']), 'его токены гасятся');

        rememberReset();
    });
});

test('с UI_REMEMBER_DAYS=0 галки нет и кука не ставится', function (): void {
    withConfig(['ui.auth' => true, 'ui.remember_days' => 0], static function (): void {
        rememberUser();

        $form = (new UiKernel())->handle(httpRequest('GET', '/ui/login'))->body();

        assertNotContains('name="remember"', $form, 'выключенную галку показывать незачем');

        $response = rememberLogin(true);

        assertSame('', rememberCookieValue($response), 'даже с присланной галкой куку ставить нельзя');

        rememberReset();
    });
});

test('просроченные токены чистит воркер', function (): void {
    withOwnDatabase(static function (Database $db): void {
        $users  = new UserRepository();
        $tokens = new RememberTokenRepository($db);

        $user = $users->create(['login' => 'chistka', 'password' => 'parol123']);

        $tokens->issue((int) $user['id'], 30);
        $tokens->issue((int) $user['id'], 30);

        // Один из токенов просрочим
        $db->execute(
            'UPDATE remember_tokens SET expires_at = :expires WHERE id = (SELECT MIN(id) FROM remember_tokens)',
            ['expires' => date('Y-m-d H:i:s', time() - 86400)]
        );

        assertSame(1, $tokens->purgeExpired(), 'чистится только просроченный');
        assertSame(1, $tokens->countForUser((int) $user['id']), 'живой остаётся');
    });
});

test('протухшая сессия не выбрасывает того, кого просили запомнить', function (): void {
    withConfig(['ui.auth' => true, 'ui.session_lifetime' => 60], static function (): void {
        $user   = rememberUser();
        $cookie = rememberCookieValue(rememberLogin(true));

        // Сессия жива, но последняя активность была давно — как после ночи
        $_SESSION['ui_user']['seen'] = time() - 3600;
        $_COOKIE[Auth::REMEMBER_COOKIE] = $cookie;
        Auth::forget();

        $response = (new UiKernel())->handle(httpRequest('GET', '/ui/'));

        assertStatus(200, $response, 'по долгой куке должно пускать и после протухшей сессии');
        assertSame((int) $user['id'], (int) assertNotNull(Auth::user())['id']);

        rememberReset();
    });
});

test('форма, открытая до тихого входа, всё равно отправляется', function (): void {
    withConfig(['ui.auth' => true], static function (): void {
        rememberUser();
        $cookie = rememberCookieValue(rememberLogin(true));

        // Страница открыта, токен формы получен
        $token = Csrf::token();

        // Пока страница висела, сессия кончилась, а кука осталась
        rememberReset();
        $_COOKIE[Auth::REMEMBER_COOKIE] = $cookie;

        // Человек жмёт кнопку на той самой странице: приходит старый токен
        $response = (new UiKernel())->handle(
            httpRequest('POST', '/ui/suppressions', ['x-csrf-token' => $token], [
                'email'  => 'posle-vhoda@example.com',
                'reason' => 'manual',
            ])
        );

        assertStatus(302, $response, 'тихий вход не должен ронять уже открытые формы');
        assertNotNull(suppressionByEmail('posle-vhoda@example.com'), 'действие должно выполниться');

        $row = (array) suppressionByEmail('posle-vhoda@example.com');
        (new Mailer\Repository\SuppressionRepository())->delete((int) $row['id']);

        rememberReset();
    });
});

test('после входа по куке панель работает так же, как после пароля', function (): void {
    withConfig(['ui.auth' => true], static function (): void {
        $user   = rememberUser();
        $cookie = rememberCookieValue(rememberLogin(true));

        // Страницы, открытые сразу после входа паролем
        $kernel   = new UiKernel();
        $expected = [
            '/ui/profile'                    => ['name="password"', 'name="password_repeat"', 'name="name"'],
            '/ui/users/' . (int) $user['id'] => ['name="role_id"', 'name="login"', 'name="active"'],
            '/ui/roles'                      => ['Администратор'],
        ];

        foreach ($expected as $path => $needles) {
            $body = $kernel->handle(httpRequest('GET', $path))->body();

            foreach ($needles as $needle) {
                assertContains($needle, $body, 'после входа паролем на ' . $path . ' нет ' . $needle);
            }
        }

        // Те же страницы после тихого входа по куке
        rememberReset();
        $_COOKIE[Auth::REMEMBER_COOKIE] = $cookie;

        foreach ($expected as $path => $needles) {
            $response = $kernel->handle(httpRequest('GET', $path));

            assertStatus(200, $response, 'после входа по куке ' . $path . ' должен открываться');

            foreach ($needles as $needle) {
                assertContains($needle, $response->body(), 'после входа по куке на ' . $path . ' нет ' . $needle);
            }

            // Кука рабочая: следующий запрос идёт с обновлённой
            $fresh = rememberCookieValue($response);

            if ($fresh !== '') {
                $_COOKIE[Auth::REMEMBER_COOKIE] = $fresh;
            }
        }

        // И права те же самые
        assertTrue(Mailer\Ui\Auth::viewer()->isAdmin(), 'по куке должен входить тот же администратор');

        rememberReset();
    });
});

test('параллельные запросы со старой кукой не выкидывают из панели', function (): void {
    withConfig(['ui.auth' => true], static function (): void {
        $user   = rememberUser();
        $cookie = rememberCookieValue(rememberLogin(true));
        $kernel = new UiKernel();

        // Первый запрос страницы: он и сменит validator
        rememberReset();
        $_COOKIE[Auth::REMEMBER_COOKIE] = $cookie;

        $first = $kernel->handle(httpRequest('GET', '/ui/'));

        assertStatus(200, $first);
        assertTrue(rememberCookieValue($first) !== '', 'первый запрос получает новую куку');

        // Соседние запросы той же страницы браузер отправил одновременно, у них
        // ещё старая кука — они должны пройти, а не гасить токен
        foreach (['/ui/messages', '/ui/users', '/ui/roles'] as $path) {
            rememberReset();
            $_COOKIE[Auth::REMEMBER_COOKIE] = $cookie;

            $response = $kernel->handle(httpRequest('GET', $path));

            assertStatus(200, $response, 'параллельный запрос ' . $path . ' должен пройти');
            assertNotNull(Auth::user(), 'и пользователь должен остаться вошедшим');
            assertSame('', rememberCookieValue($response), 'второй раз куку менять незачем');
        }

        assertSame(1, (new RememberTokenRepository())->countForUser((int) $user['id']), 'токен должен остаться');

        rememberReset();
    });
});

test('старая кука после окна снисхождения по-прежнему гасит токен', function (): void {
    withConfig(['ui.auth' => true], static function (): void {
        $user   = rememberUser();
        $cookie = rememberCookieValue(rememberLogin(true));

        rememberReset();
        $_COOKIE[Auth::REMEMBER_COOKIE] = $cookie;
        (new UiKernel())->handle(httpRequest('GET', '/ui/'));

        // Отматываем смену на час назад — окно давно закрылось
        Database::instance()->execute(
            'UPDATE remember_tokens SET rotated_at = :old WHERE user_id = :user',
            ['old' => date('Y-m-d H:i:s', time() - 3600), 'user' => (int) $user['id']]
        );

        rememberReset();
        $_COOKIE[Auth::REMEMBER_COOKIE] = $cookie;

        (new UiKernel())->handle(httpRequest('GET', '/ui/'));

        assertNull(Auth::user(), 'старой кукой пользоваться нельзя');
        assertSame(0, (new RememberTokenRepository())->countForUser((int) $user['id']), 'токен гасится');

        rememberReset();
    });
});
