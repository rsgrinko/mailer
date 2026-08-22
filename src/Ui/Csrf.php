<?php

declare(strict_types=1);

namespace Mailer\Ui;

use Mailer\Http\Response;
use Mailer\Support\Config;

/**
 * Защита форм панели от подделки запросов с чужих сайтов.
 *
 * Токен живёт в сессии, в каждую форму подставляется скрытым полем, а прослойка
 * CsrfGuard сверяет его при любом изменяющем запросе. Кука панели и так стоит с
 * SameSite=Lax, но полагаться на одну лишь куку не стоит: браузер может быть старым,
 * а найденная в панели XSS без токена сразу превращается в захват аккаунта.
 *
 * Одной сессии мало: PHP выбрасывает её по своему session.gc_maxlifetime (по
 * умолчанию 24 минуты), а человек в это время остаётся в панели по долгой куке
 * «запомнить меня» — и получает «форма устарела» на каждой кнопке. Поэтому токен
 * дублируется в свою куку, подписанную APP_KEY: сессия поднялась пустой — токен
 * возвращается из куки, и открытая страница продолжает работать. Подпись здесь
 * обязательна: без неё подсунуть браузеру свой токен смог бы кто угодно, кто умеет
 * ставить куки на домен, и проверка перестала бы что-либо значить.
 */
final class Csrf
{
    /** Имя скрытого поля в формах и заголовка для fetch-запросов */
    public const FIELD  = '_token';
    public const HEADER = 'x-csrf-token';

    /** Кука с тем же токеном — переживает потерю сессии */
    public const COOKIE = 'mailer_form';

    private const SESSION_KEY = 'csrf_token';

    /** Токен для окружений без сессии (консоль, тесты) */
    private static string $fallback = '';

    /**
     * Кука, которую нужно отдать вместе с ответом: {value, expires}.
     *
     * @var array{value: string, expires: int}|null
     */
    private static ?array $pendingCookie = null;

    /**
     * Текущий токен: создаётся при первом обращении.
     */
    public static function token(): string
    {
        Auth::start();

        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (self::$fallback === '') {
                self::$fallback = self::issue();
            }

            return self::$fallback;
        }

        if (!isset($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = self::issue();
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
        // Мало забыть токен: в браузере осталась кука с ним, и следующий запрос
        // поднял бы прежний токен обратно. Поэтому сразу выдаём новый и отправляем
        // его в куку — она заменит старую
        $token = bin2hex(random_bytes(16));

        unset($_COOKIE[self::COOKIE]);
        self::rememberInCookie($token);

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::SESSION_KEY] = $token;

            return;
        }

        self::$fallback = $token;
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

        self::rememberInCookie($token);

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::SESSION_KEY] = $token;

            return;
        }

        self::$fallback = $token;
    }

    /**
     * Навешивает на ответ куку с токеном, если её нужно поставить. Зовётся одним
     * местом — ядром панели, там же, где кука «запомнить меня».
     */
    public static function applyCookie(Response $response): Response
    {
        if (self::$pendingCookie === null) {
            return $response;
        }

        $cookie              = self::$pendingCookie;
        self::$pendingCookie = null;

        $parts = [
            self::COOKIE . '=' . rawurlencode($cookie['value']),
            'Expires=' . gmdate('D, d M Y H:i:s', $cookie['expires']) . ' GMT',
            'Max-Age=' . max(0, $cookie['expires'] - time()),
            'Path=/',
            'HttpOnly',
            'SameSite=Lax',
        ];

        if (Auth::isHttps()) {
            $parts[] = 'Secure';
        }

        return $response->withCookie(implode('; ', $parts));
    }

    /**
     * Сбрасывает отложенную куку — нужно тестам и CLI.
     */
    public static function forget(): void
    {
        self::$pendingCookie = null;
        self::$fallback      = '';
    }

    /**
     * Скрытое поле для формы.
     */
    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . View::e(self::token()) . '">';
    }

    /**
     * Откуда берётся токен: из своей куки, если она есть и подпись сходится, иначе
     * новый — и тогда его нужно положить в куку.
     */
    private static function issue(): string
    {
        $token = self::fromCookie();

        if ($token !== null) {
            return $token;
        }

        $token = bin2hex(random_bytes(16));
        self::rememberInCookie($token);

        return $token;
    }

    /**
     * Токен из куки, если подпись сходится. Чужая или испорченная кука — как если
     * бы её не было: выдадим новый токен.
     */
    private static function fromCookie(): ?string
    {
        $raw = (string) ($_COOKIE[self::COOKIE] ?? '');
        $key = self::key();

        if ($raw === '' || $key === '' || !str_contains($raw, '.')) {
            return null;
        }

        [$token, $signature] = explode('.', $raw, 2);

        if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
            return null;
        }

        return hash_equals(hash_hmac('sha256', $token, $key), $signature) ? $token : null;
    }

    /**
     * Просит положить токен в куку вместе с ответом. Без APP_KEY подписывать нечем —
     * тогда куки не будет вовсе, а токен останется жить только в сессии.
     */
    private static function rememberInCookie(string $token): void
    {
        $key = self::key();

        if ($key === '') {
            return;
        }

        self::$pendingCookie = [
            'value'   => $token . '.' . hash_hmac('sha256', $token, $key),
            'expires' => time() + self::lifetime(),
        ];
    }

    /**
     * Кука должна жить не меньше, чем сам вход: сессию панели держит либо
     * UI_SESSION_LIFETIME, либо долгая кука «запомнить меня».
     */
    private static function lifetime(): int
    {
        return max(
            (int) Config::get('ui.session_lifetime', 43200),
            Auth::rememberDays() * 86400,
            3600
        );
    }

    private static function key(): string
    {
        return (string) Config::get('app.key', '');
    }
}
