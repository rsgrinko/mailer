<?php

declare(strict_types=1);

namespace Mailer\Ui;

/**
 * Защита форм панели от подделки запросов с чужих сайтов.
 *
 * Токен живёт в сессии, в каждую форму подставляется скрытым полем, а прослойка
 * CsrfGuard сверяет его при любом изменяющем запросе. Кука панели и так стоит с
 * SameSite=Lax, но полагаться на одну лишь куку не стоит: браузер может быть старым,
 * а найденная в панели XSS без токена сразу превращается в захват аккаунта.
 */
final class Csrf
{
    /** Имя скрытого поля в формах и заголовка для fetch-запросов */
    public const FIELD  = '_token';
    public const HEADER = 'x-csrf-token';

    private const SESSION_KEY = 'csrf_token';

    /** Токен для окружений без сессии (консоль, тесты) */
    private static string $fallback = '';

    /**
     * Текущий токен: создаётся при первом обращении.
     */
    public static function token(): string
    {
        Auth::start();

        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (self::$fallback === '') {
                self::$fallback = bin2hex(random_bytes(16));
            }

            return self::$fallback;
        }

        if (!isset($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(16));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Совпадает ли присланный токен с тем, что лежит в сессии.
     */
    public static function check(string $sent): bool
    {
        return $sent !== '' && hash_equals(self::token(), $sent);
    }

    /**
     * Выдать новый токен — делается при входе и выходе, чтобы старый не пригодился.
     */
    public static function rotate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION[self::SESSION_KEY]);

            return;
        }

        self::$fallback = '';
    }

    /**
     * Вернуть в сессию прежний токен.
     *
     * Нужно ровно в одном месте: при тихом входе по куке «запомнить меня». Сессия
     * там заводится заново, а страница у человека в браузере открыта со старым
     * токеном — и её отправка должна пройти, а не упереться в «форма устарела».
     */
    public static function restore(string $token): void
    {
        if ($token === '') {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::SESSION_KEY] = $token;

            return;
        }

        self::$fallback = $token;
    }

    /**
     * Скрытое поле для формы.
     */
    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . View::e(self::token()) . '">';
    }
}
