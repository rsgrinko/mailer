<?php

declare(strict_types=1);

namespace Mailer\Ui\Controllers;

use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Repository\UserRepository;
use Mailer\Support\Logger;
use Mailer\Ui\Auth;
use Mailer\Ui\View;
use Throwable;

/**
 * Вход в панель, выход и создание первого пользователя.
 */
final class AuthController
{
    private UserRepository $users;
    private Logger $logger;

    public function __construct()
    {
        $this->users  = new UserRepository();
        $this->logger = new Logger('ui');
    }

    public function loginForm(Request $request): Response
    {
        return Response::html(View::renderBare('login', [
            'login' => '',
            'error' => '',
            'next'  => self::next($request),
        ], 'Вход'));
    }

    public function login(Request $request): Response
    {
        $login = trim((string) $request->input('login', ''));
        $next  = self::safeNext((string) $request->input('next', ''));

        $result = Auth::attempt($login, (string) $request->input('password', ''), $request->ip());

        if ($result['user'] === null) {
            $this->logger->warning('Неудачный вход в панель', ['login' => $login, 'ip' => $request->ip()]);

            return Response::html(View::renderBare('login', [
                'login' => $login,
                'error' => $result['error'],
                'next'  => $next,
            ], 'Вход'), 401);
        }

        $user = $result['user'];
        Auth::login($user);
        $this->users->touchLogin((int) $user['id'], $request->ip());
        $this->logger->info('Вход в панель', ['login' => $user['login'], 'ip' => $request->ip()]);

        return Response::redirect($next !== '' ? $next : View::url('/'));
    }

    public function logout(Request $request): Response
    {
        $user = Auth::user();
        Auth::logout();

        if ($user !== null) {
            $this->logger->info('Выход из панели', ['login' => $user['login']]);
        }

        View::flash('Вы вышли из панели');

        return Response::redirect(View::url('/login'));
    }

    /**
     * Первый запуск: пользователей ещё нет, заводим себя сами.
     */
    public function setupForm(Request $request): Response
    {
        return Response::html(View::renderBare('setup', ['login' => '', 'error' => ''], 'Первый вход'));
    }

    public function setup(Request $request): Response
    {
        if ($this->users->count() > 0) {
            return Response::redirect(View::url('/login'));
        }

        $login    = trim((string) $request->input('login', ''));
        $password = (string) $request->input('password', '');
        $repeat   = (string) $request->input('password_repeat', '');

        if ($password !== $repeat) {
            return Response::html(View::renderBare('setup', [
                'login' => $login,
                'error' => 'Пароли не совпадают',
            ], 'Первый вход'), 422);
        }

        try {
            $user = $this->users->create([
                'login'    => $login,
                'password' => $password,
                'name'     => trim((string) $request->input('name', '')),
                'active'   => true,
            ]);
        } catch (Throwable $e) {
            return Response::html(View::renderBare('setup', [
                'login' => $login,
                'error' => $e->getMessage(),
            ], 'Первый вход'), 422);
        }

        Auth::login($user);
        $this->users->touchLogin((int) $user['id'], $request->ip());
        $this->logger->info('Создан первый пользователь панели', ['login' => $user['login']]);

        View::flash('Пользователь «' . $user['login'] . '» создан, вы вошли в панель');

        return Response::redirect(View::url('/'));
    }

    /**
     * Куда вернуть после входа: адрес, с которого человека развернули на форму.
     */
    private static function next(Request $request): string
    {
        return self::safeNext((string) $request->query('next', ''));
    }

    /**
     * Пускаем только внутренние адреса панели — иначе после входа можно увести на чужой сайт.
     */
    private static function safeNext(string $next): string
    {
        if ($next === '' || !str_starts_with($next, '/ui')) {
            return '';
        }

        // Ссылки вида //example.com тоже уводят наружу
        if (str_starts_with($next, '//') || str_contains($next, "\n") || str_contains($next, "\r")) {
            return '';
        }

        return $next;
    }
}
