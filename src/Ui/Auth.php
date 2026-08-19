<?php

declare(strict_types=1);

namespace Mailer\Ui;

use Mailer\RateLimit\RateLimiter;
use Mailer\Repository\UserRepository;
use Mailer\Support\Config;

/**
 * Авторизация панели: сессия, текущий пользователь и защита от подбора пароля.
 *
 * Сессия обычная, на куке. Кука ставится с SameSite=Lax, то есть чужие сайты не смогут
 * отправить форму в панель от имени вошедшего пользователя.
 */
final class Auth
{
    /** Ключ пользователя в сессии */
    private const SESSION_KEY = 'ui_user';

    /** Сколько неудачных попыток входа разрешаем с одного адреса */
    private const MAX_ATTEMPTS = 10;

    /** За какое время они считаются, секунды */
    private const ATTEMPTS_WINDOW = 900;

    /** @var array<string, mixed>|null|false false — ещё не смотрели */
    private static array|null|false $cached = false;

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
            return self::$cached = null;
        }

        // Слишком долго не заходил — просим войти заново
        $lifetime = (int) Config::get('ui.session_lifetime', 43200);
        $seen     = (int) ($_SESSION[self::SESSION_KEY]['seen'] ?? 0);

        if ($lifetime > 0 && $seen > 0 && time() - $seen > $lifetime) {
            self::logout();

            return self::$cached = null;
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
     * Записывает пользователя в сессию.
     *
     * @param array<string, mixed> $user
     */
    public static function login(array $user): void
    {
        self::start();

        // Новый идентификатор сессии — чтобы чужой заранее подсунутый не подошёл
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION[self::SESSION_KEY] = [
            'id'    => (int) $user['id'],
            'login' => (string) $user['login'],
            'seen'  => time(),
        ];

        self::$cached = $user;
    }

    public static function logout(): void
    {
        self::start();

        unset($_SESSION[self::SESSION_KEY]);
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
        self::$cached = false;
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
