<?php

declare(strict_types=1);

/**
 * Токен форм панели.
 *
 * Проверяем то, из-за чего панель однажды перестала принимать любые формы: токен
 * жил только в сессии, а сессию выбрасывал сборщик мусора PHP (по умолчанию через
 * 24 минуты) — человек оставался в панели по долгой куке и на каждой кнопке получал
 * «форма устарела». Теперь токен продублирован в подписанную куку, и вместе с ней
 * проверяется, что подделать её нельзя, что вход и выход её меняют и что кука сессии
 * при этом не теряется.
 *
 * Ключ подписи задаём свой: в CI никакого .env нет, а без ключа куки не будет.
 */

use Mailer\Http\Response;
use Mailer\Support\Config;
use Mailer\Ui\Csrf;

/** Ключ, которым подписываем куку в этих проверках */
const CSRF_TEST_KEY = 'klyuch-dlya-proverki-tokena-form';

/**
 * Собирает куку токена так, как её собрал бы браузер из ответа.
 */
function csrfCookie(string $token): string
{
    return $token . '.' . hash_hmac('sha256', $token, (string) Config::get('app.key', ''));
}

/**
 * Забываем всё, что помнит Csrf между запросами.
 */
function csrfReset(): void
{
    $_SESSION = [];
    unset($_COOKIE[Csrf::COOKIE]);
    Csrf::forget();
}

/**
 * Проверка с нашим ключом подписи и с чистого листа.
 */
function withCsrf(callable $check): void
{
    withConfig(['app.key' => CSRF_TEST_KEY], static function () use ($check): void {
        csrfReset();

        try {
            $check();
        } finally {
            csrfReset();
        }
    });
}

test('токен уезжает в подписанную куку', function (): void {
    withCsrf(static function (): void {
        $token  = Csrf::token();
        $cookie = Csrf::applyCookie(Response::html(''))->cookies();

        assertCount(1, $cookie, 'новый токен должен уехать в куку');
        assertContains(Csrf::COOKIE . '=' . $token . '.', $cookie[0], 'в куке — сам токен и подпись');
        assertContains('HttpOnly', $cookie[0], 'куку не должен читать скрипт на странице');
        assertContains('SameSite=Lax', $cookie[0]);
    });
});

test('токен переживает потерю сессии', function (): void {
    withCsrf(static function (): void {
        $token = Csrf::token();

        // Сессию унёс сборщик мусора PHP, кука осталась
        csrfReset();
        $_COOKIE[Csrf::COOKIE] = csrfCookie($token);

        assertSame($token, Csrf::token(), 'токен должен вернуться из куки');
        assertTrue(Csrf::check($token), 'форма, открытая до этого, должна отправляться');
    });
});

test('кука с чужой подписью не принимается', function (): void {
    withCsrf(static function (): void {
        $token = str_repeat('a', 32);

        foreach ([
            $token . '.' . str_repeat('b', 64),
            $token . '.' . hash_hmac('sha256', $token, 'чужой ключ'),
            $token,
            'мусор',
        ] as $fake) {
            csrfReset();
            $_COOKIE[Csrf::COOKIE] = $fake;

            assertFalse(Csrf::check($token), 'кука «' . mb_substr($fake, 0, 20) . '» не должна задавать токен');
        }
    });
});

test('смена токена меняет и куку', function (): void {
    withCsrf(static function (): void {
        $first = Csrf::token();

        // Так делают вход и выход
        Csrf::rotate();

        $second = Csrf::token();
        $cookie = Csrf::applyCookie(Response::html(''))->cookies();

        assertTrue($first !== $second, 'после смены токен должен стать другим');
        assertFalse(Csrf::check($first), 'старый токен больше не годится');
        assertCount(1, $cookie, 'браузеру надо отдать новую куку, иначе он вернёт старый токен');
        assertContains($second, $cookie[0]);
    });
});

test('без APP_KEY токен живёт по-старому, только в сессии', function (): void {
    withConfig(['app.key' => ''], static function (): void {
        csrfReset();

        $token = Csrf::token();

        assertCount(0, Csrf::applyCookie(Response::html(''))->cookies(), 'подписывать куку нечем — и ставить её нечего');
        assertTrue(Csrf::check($token), 'сама проверка при этом работать не перестаёт');

        csrfReset();
    });
});

test('куки в ответе не затирают друг друга', function (): void {
    $response = Response::html('')
        ->withCookie('mailer_remember=1; Path=/')
        ->withCookie('mailer_form=2; Path=/');

    assertCount(2, $response->cookies(), 'кук в одном ответе бывает несколько');

    // Куку сессии ставит сам PHP, и отдавать наши нужно добавлением, а не заменой:
    // с header() по умолчанию сессия панели терялась, а с ней и токен форм
    $source = (string) file_get_contents(MAILER_ROOT . '/src/Http/Response.php');

    assertContains("header('Set-Cookie: ' . \$cookie, false)", $source, 'куку сессии затирать нельзя');
});
