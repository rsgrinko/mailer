<?php

declare(strict_types=1);

namespace Mailer\Ui;

use Mailer\Domain\Viewer;
use Mailer\Http\Response;
use Mailer\RateLimit\RateLimiter;
use Mailer\Repository\RememberTokenRepository;
use Mailer\Repository\UserRepository;
use Mailer\Support\Config;

/**
 * Авторизация панели: сессия, текущий пользователь и защита от подбора пароля.
 *
 * Сессия обычная, на куке. Кука ставится с SameSite=Lax, то есть чужие сайты не смогут
 * отправить форму в панель от имени вошедшего пользователя.
 *
 * С галкой «запомнить меня» рядом с сессией живёт отдельная долгая кука. Пароля в ней
 * нет — только пара «selector:validator» из таблицы remember_tokens, и при каждом входе
 * по ней токен меняется (см. RememberTokenRepository). Кука ставится не сразу, а
 * складывается в $pendingCookie: заголовки отдаёт ответ, а Auth его не видит — поэтому
 * готовую куку навешивает UiKernel через applyCookies().
 */
final class Auth
{
    /** Ключ пользователя в сессии */
    private const SESSION_KEY = 'ui_user';

    /** Имя долгой куки «запомнить меня» */
    public const REMEMBER_COOKIE = 'mailer_remember';

    /** Сколько неудачных попыток входа разрешаем с одного адреса */
    private const MAX_ATTEMPTS = 10;

    /** За какое время они считаются, секунды */
    private const ATTEMPTS_WINDOW = 900;

    /** @var array<string, mixed>|null|false false — ещё не смотрели */
    private static array|null|false $cached = false;

    /**
     * Кука, которую нужно отдать вместе с ответом.
     *
     * @var array{value: string, expires: int}|null
     */
    private static ?array $pendingCookie = null;

    /**
     * Включена ли авторизация. Выключают её, когда панель уже закрыта basic auth на nginx.
     */
    public static function enabled(): bool
    {
        return (bool) Config::get('ui.auth', true);
    }

    /**
     * Стартует сессию панели. Вызывать до любого вывода.
     */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') {
            return;
        }

        session_name('mailer_panel');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => self::isHttps(),
        ]);

        @session_start();
    }

    /**
     * Текущий пользователь или null. Данные перечитываются из базы,
     * поэтому отключённый пользователь теряет доступ сразу, а не после выхода.
     *
     * @return array<string, mixed>|null
     */
    public static function user(): ?array
    {
        if (self::$cached !== false) {
            return self::$cached;
        }

        self::start();

        $id = (int) ($_SESSION[self::SESSION_KEY]['id'] ?? 0);
        if ($id === 0) {
            // Сессии нет — может, человек просил себя запомнить
            return self::$cached = self::fromRememberCookie();
        }

        // Слишком долго не заходил — просим войти заново
        $lifetime = (int) Config::get('ui.session_lifetime', 43200);
        $seen     = (int) ($_SESSION[self::SESSION_KEY]['seen'] ?? 0);

        if ($lifetime > 0 && $seen > 0 && time() - $seen > $lifetime) {
            // Сессию закрываем, но долгую куку не трогаем: её гасит только явный
            // выход. Иначе «запомнить меня» работало бы лишь до конца сессии
            self::clearSession();

            return self::$cached = self::fromRememberCookie();
        }

        $user = (new UserRepository())->find($id);

        if ($user === null || (int) $user['active'] !== 1) {
            self::logout();

            return self::$cached = null;
        }

        $_SESSION[self::SESSION_KEY]['seen'] = time();

        return self::$cached = $user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /**
     * Кто смотрит панель: права и область видимости. Авторизация выключена —
     * значит спрашивать некого и доступно всё, как было до разделения прав.
     */
    public static function viewer(): Viewer
    {
        if (!self::enabled()) {
            return Viewer::full();
        }

        return Viewer::fromUser(self::user() ?? []);
    }

    /**
     * Сколько дней жить долгой куке. Ноль — галку «запомнить меня» не показываем вовсе.
     */
    public static function rememberDays(): int
    {
        return max(0, (int) Config::get('ui.remember_days', 30));
    }

    /**
     * Записывает пользователя в сессию.
     *
     * @param array<string, mixed> $user
     * @param bool $remember просили запомнить — заводим долгую куку
     */
    public static function login(array $user, bool $remember = false, bool $rotateCsrf = true): void
    {
        self::start();

        // Новый идентификатор сессии — чтобы чужой заранее подсунутый не подошёл
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        // Токен форм тоже меняем: старый мог быть подсмотрен до входа.
        // Не меняем только при тихом входе по долгой куке — см. fromRememberCookie()
        if ($rotateCsrf) {
            Csrf::rotate();
        }

        $_SESSION[self::SESSION_KEY] = [
            'id'    => (int) $user['id'],
            'login' => (string) $user['login'],
            'seen'  => time(),
        ];

        self::$cached = $user;

        $days = self::rememberDays();

        if ($remember && $days > 0) {
            self::$pendingCookie = [
                'value'   => (new RememberTokenRepository())->issue((int) $user['id'], $days, self::ip()),
                'expires' => time() + $days * 86400,
            ];
        }
    }

    public static function logout(): void
    {
        self::start();

        // Токен этого браузера гасим, чужие устройства того же пользователя не трогаем
        $cookie = self::rememberCookie();

        if ($cookie !== '') {
            $tokens = new RememberTokenRepository();
            $token  = $tokens->match($cookie);

            if ($token !== null) {
                $tokens->delete((string) $token['selector']);
            }
        }

        self::forgetRememberCookie();
        self::clearSession();
    }

    /**
     * Закрывает сессию, не трогая долгую куку: этим отличается «сессия протухла»
     * от «человек нажал выйти».
     */
    private static function clearSession(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
        Csrf::rotate();
        self::$cached = null;

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * Попытка входа. Возвращает пользователя либо текст ошибки.
     *
     * @return array{user: array<string, mixed>|null, error: string}
     */
    public static function attempt(string $login, string $password, string $ip): array
    {
        $limiter = new RateLimiter();
        $key     = self::attemptsKey($ip);

        if ($limiter->count($key) >= self::MAX_ATTEMPTS) {
            return ['user' => null, 'error' => 'Слишком много попыток входа. Попробуйте через 15 минут'];
        }

        if ($login === '' || $password === '') {
            return ['user' => null, 'error' => 'Введите логин и пароль'];
        }

        $user = (new UserRepository())->verify($login, $password);

        if ($user === null) {
            $limiter->hit($key, time() + self::ATTEMPTS_WINDOW);

            return ['user' => null, 'error' => 'Неверный логин или пароль'];
        }

        $limiter->reset($key);

        return ['user' => $user, 'error' => ''];
    }

    /**
     * Сбрасывает запомненного пользователя — нужно тестам и CLI.
     */
    public static function forget(): void
    {
        self::$cached        = false;
        self::$pendingCookie = null;
    }

    /**
     * Навешивает на ответ куку «запомнить меня», если её нужно поставить или убрать.
     * Зовётся одним местом — ядром панели, чтобы про неё нельзя было забыть.
     */
    public static function applyCookies(Response $response): Response
    {
        if (self::$pendingCookie === null) {
            return $response;
        }

        $cookie              = self::$pendingCookie;
        self::$pendingCookie = null;

        $parts = [
            self::REMEMBER_COOKIE . '=' . rawurlencode($cookie['value']),
            'Expires=' . gmdate('D, d M Y H:i:s', $cookie['expires']) . ' GMT',
            'Max-Age=' . max(0, $cookie['expires'] - time()),
            'Path=/',
            'HttpOnly',
            'SameSite=Lax',
        ];

        if (self::isHttps()) {
            $parts[] = 'Secure';
        }

        return $response->withHeader('Set-Cookie', implode('; ', $parts));
    }

    /**
     * Вход по долгой куке: сессия кончилась, но пользователь просил себя запомнить.
     *
     * @return array<string, mixed>|null
     */
    private static function fromRememberCookie(): ?array
    {
        $cookie = self::rememberCookie();

        if ($cookie === '' || self::rememberDays() === 0) {
            return null;
        }

        $tokens = new RememberTokenRepository();
        $token  = $tokens->match($cookie);

        if ($token === null) {
            // Куку с несуществующим или подделанным токеном убираем, чтобы она
            // не ходила с каждым запросом
            self::forgetRememberCookie();

            return null;
        }

        $user = (new UserRepository())->find((int) $token['user_id']);

        if ($user === null || (int) $user['active'] !== 1) {
            $tokens->forgetUser((int) $token['user_id']);
            self::forgetRememberCookie();

            return null;
        }

        // Сессию поднимаем заново, но токен форм сохраняем: страница в браузере
        // открыта с прежним токеном, и после тихого входа её отправка должна
        // проходить — иначе человек получает «форма устарела» на ровном месте
        $formToken = Csrf::token();

        self::login($user, false, false);

        Csrf::restore($formToken);

        self::$pendingCookie = [
            'value'   => $tokens->rotate((int) $token['id'], self::ip()),
            'expires' => strtotime((string) $token['expires_at']) ?: time() + self::rememberDays() * 86400,
        ];

        return $user;
    }

    private static function rememberCookie(): string
    {
        return trim((string) ($_COOKIE[self::REMEMBER_COOKIE] ?? ''));
    }

    /**
     * Просит браузер забыть долгую куку.
     */
    private static function forgetRememberCookie(): void
    {
        unset($_COOKIE[self::REMEMBER_COOKIE]);

        self::$pendingCookie = ['value' => '', 'expires' => time() - 86400];
    }

    private static function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    private static function attemptsKey(string $ip): string
    {
        return 'login:' . $ip;
    }

    private static function isHttps(): bool
    {
        if (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }
}
