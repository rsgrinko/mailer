<?php

declare(strict_types=1);

namespace Mailer\Ui\Controllers;

use Mailer\Domain\Permission;
use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Repository\RoleRepository;
use Mailer\Repository\UserRepository;
use Mailer\Support\Logger;
use Mailer\Support\MailerException;
use Mailer\Ui\Auth;
use Mailer\Ui\Audit;
use Mailer\Ui\View;
use Throwable;

/**
 * Вход в панель, выход и создание первого пользователя.
 */
final class AuthController
{
    private UserRepository $users;
    private RoleRepository $roles;
    private Logger $logger;

    public function __construct(UserRepository $users, RoleRepository $roles)
    {
        $this->users  = $users;
        $this->roles  = $roles;
        $this->logger = new Logger('ui');
    }

    public function loginForm(Request $request): Response
    {
        return Response::html(View::renderBare('login', [
            'login'    => '',
            'error'    => '',
            'next'     => self::next($request),
            'remember' => false,
            'days'     => Auth::rememberDays(),
        ], 'Вход'));
    }

    public function login(Request $request): Response
    {
        $login    = trim((string) $request->input('login', ''));
        $next     = self::safeNext((string) $request->input('next', ''));
        $remember = $request->input('remember') !== null;

        $result = Auth::attempt($login, (string) $request->input('password', ''), $request->ip());

        if ($result['user'] === null) {
            $this->logger->warning('Неудачный вход в панель', ['login' => $login, 'ip' => $request->ip()]);
            Audit::login(0, $login, false);

            return Response::html(View::renderBare('login', [
                'login'    => $login,
                'error'    => $result['error'],
                'next'     => $next,
                // Галку возвращаем как была: перенабирать её после опечатки в пароле незачем
                'remember' => $remember,
                'days'     => Auth::rememberDays(),
            ], 'Вход'), 401);
        }

        $user = $result['user'];
        Auth::login($user, $remember);
        $this->users->touchLogin((int) $user['id'], $request->ip());
        $this->logger->info('Вход в панель', ['login' => $user['login'], 'ip' => $request->ip()]);
        Audit::login((int) $user['id'], (string) $user['login'], true);

        return Response::redirect($next !== '' ? $next : View::route('ui.dashboard'));
    }

    public function logout(Request $request): Response
    {
        $user = Auth::user();
        Auth::logout();

        if ($user !== null) {
            $this->logger->info('Выход из панели', ['login' => $user['login']]);
            Audit::logout((int) $user['id'], (string) $user['login']);
        }

        View::flash('Вы вышли из панели');

        return Response::redirect(View::route('ui.login'));
    }

    /**
     * Свой профиль: имя и пароль. Доступен без прав — иначе пользователь без
     * users.manage не сменил бы себе даже пароль.
     */
    public function profileForm(Request $request): Response
    {
        // С выключенной авторизацией пользователя нет вовсе — показываем это прямо,
        // а не редиректом: страница есть, менять на ней нечего
        $user = Auth::user() ?? [
            'id'          => 0,
            'login'       => '—',
            'name'        => '',
            'role_name'   => 'авторизация панели выключена',
            'permissions' => Permission::all(),
        ];

        return Response::html(View::render('profile', [
            'active' => '',
            'person' => $user,
        ], 'Мой профиль'));
    }

    public function profile(Request $request): Response
    {
        $user = Auth::user();

        if ($user === null) {
            return Response::redirect(View::route('ui.dashboard'));
        }

        $id       = (int) $user['id'];
        $password = (string) $request->input('password', '');

        try {
            $this->users->update($id, ['name' => trim((string) $request->input('name', ''))]);

            if ($password !== '') {
                if ($password !== (string) $request->input('password_repeat', '')) {
                    throw new MailerException('Пароли не совпадают');
                }

                $this->users->setPassword($id, $password);
            }

            Audit::updated('user', $id, 'свой профиль' . ($password !== '' ? ', сменён пароль' : ''));
            View::flash('Профиль сохранён');
        } catch (Throwable $e) {
            View::flash($e->getMessage(), 'error');
        }

        return Response::redirect(View::route('ui.profile'));
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
            return Response::redirect(View::route('ui.login'));
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
            // Первый пользователь получает роль администратора: больше её выдать некому.
            // Если роли в базе нет (снесли руками, база из старой миграции) — заводим
            $admin = $this->roles->ensureAdmin();

            $user = $this->users->create([
                'login'    => $login,
                'password' => $password,
                'name'     => trim((string) $request->input('name', '')),
                'active'   => true,
                'role_id'  => (int) $admin['id'],
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
        Audit::created('user', (int) $user['id'], 'первый пользователь панели «' . $user['login'] . '»');

        View::flash('Пользователь «' . $user['login'] . '» создан, вы вошли в панель');

        return Response::redirect(View::route('ui.dashboard'));
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
