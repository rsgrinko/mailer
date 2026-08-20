<?php

declare(strict_types=1);

namespace Mailer\Ui\Controllers;

use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Repository\RoleRepository;
use Mailer\Repository\UserRepository;
use Mailer\Security\Password;
use Mailer\Support\Config;
use Mailer\Support\MailerException;
use Mailer\Ui\Auth;
use Mailer\Ui\Audit;
use Mailer\Ui\View;
use Throwable;

/**
 * Пользователи панели: логины, пароли и выданная роль. Права приходят из роли,
 * поэтому раздел закрыт правом users.manage.
 */
final class UsersController extends ResourceController
{
    private UserRepository $users;
    private RoleRepository $roles;

    public function __construct(
        UserRepository $users,
        RoleRepository $roles
    ) {
        $this->users = $users;
        $this->roles = $roles;
    }

    public function index(Request $request): Response
    {
        $result = $this->users->paginate(
            (int) $request->query('page', 1),
            (int) Config::get('ui.per_page', 30)
        );

        return Response::html(View::render('users', [
            'active'  => 'users',
            'items'   => $result['items'],
            'result'  => $result,
            'current' => Auth::user(),
        ], 'Пользователи'));
    }

    public function form(Request $request, ?int $id): Response
    {
        $user = $this->requireIfEditing($id, $id === null ? null : $this->users->find($id));

        return Response::html(View::render('user_form', [
            'active'  => 'users',
            'user'    => $user,
            'current' => Auth::user(),
            'roles'   => $this->roles->all(),
        ], $user === null ? 'Новый пользователь' : 'Пользователь «' . $user['login'] . '»'));
    }

    public function save(Request $request): Response
    {
        $id       = (int) $request->input('id', 0);
        $login    = trim((string) $request->input('login', ''));
        $name     = trim((string) $request->input('name', ''));
        $password = (string) $request->input('password', '');
        $repeat   = (string) $request->input('password_repeat', '');
        $active   = $request->input('active') !== null;
        $roleId   = (int) $request->input('role_id', 0);

        try {
            if ($password !== '' && $password !== $repeat) {
                throw new MailerException('Пароли не совпадают');
            }

            if ($id === 0) {
                if ($password === '') {
                    throw new MailerException('Задайте пароль новому пользователю');
                }

                $user = $this->users->create([
                    'login'    => $login,
                    'name'     => $name,
                    'password' => $password,
                    'active'   => $active,
                    'role_id'  => $roleId,
                ]);

                Audit::created('user', (int) $user['id'], 'пользователь «' . $user['login'] . '»');
                View::flash('Пользователь «' . $user['login'] . '» создан');

                return Response::redirect(View::route('ui.users'));
            }

            $current = Auth::user();
            if ($current !== null && (int) $current['id'] === $id && !$active) {
                throw new MailerException('Нельзя отключить самого себя');
            }

            $this->users->update($id, [
                'login'   => $login,
                'name'    => $name,
                'active'  => $active,
                'role_id' => $roleId,
            ]);

            if ($password !== '') {
                $this->users->setPassword($id, $password);
            }

            Audit::updated('user', $id, 'пользователь «' . $login . '»' . ($password !== '' ? ', сменён пароль' : ''));
            View::flash('Пользователь сохранён');

            return Response::redirect(View::route('ui.users'));
        } catch (Throwable $e) {
            View::flash($e->getMessage(), 'error');

            return Response::redirect($id === 0 ? View::route('ui.users.new') : View::route('ui.users.show', ['id' => $id]));
        }
    }

    /**
     * Действия из списка: удалить, включить, отключить.
     */
    public function action(Request $request, int $id, string $action): Response
    {
        $current = Auth::user();
        $user    = $this->require($this->users->find($id));

        try {
            if ($current !== null && (int) $current['id'] === $id && $action !== 'password') {
                throw new MailerException('Себя нельзя удалить или отключить — попросите об этом коллегу');
            }

            switch ($action) {
                case 'delete':
                    $this->users->delete($id);
                    Audit::deleted('user', $id, 'пользователь «' . $user['login'] . '»');
                    View::flash('Пользователь «' . $user['login'] . '» удалён');
                    break;

                case 'enable':
                    $this->users->update($id, ['active' => true]);
                    Audit::updated('user', $id, 'включён пользователь «' . $user['login'] . '»');
                    View::flash('Пользователь «' . $user['login'] . '» включён');
                    break;

                case 'disable':
                    $this->users->update($id, ['active' => false]);
                    Audit::updated('user', $id, 'отключён пользователь «' . $user['login'] . '»');
                    View::flash('Пользователь «' . $user['login'] . '» отключён');
                    break;

                case 'password':
                    $password = Password::generate();
                    $this->users->setPassword($id, $password);
                    Audit::action('user', $id, 'сброшен пароль пользователя «' . $user['login'] . '»');
                    View::flash('Новый пароль для «' . $user['login'] . '»: ' . $password);
                    break;

                default:
                    View::flash('Неизвестное действие: ' . $action, 'error');
            }
        } catch (Throwable $e) {
            View::flash($e->getMessage(), 'error');
        }

        return Response::redirect(View::route('ui.users'));
    }
    protected function listRoute(): string
    {
        return 'ui.users';
    }

    protected function notFoundMessage(): string
    {
        return 'Пользователь не найден';
    }
}
