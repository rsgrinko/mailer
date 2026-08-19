<?php

declare(strict_types=1);

namespace Mailer\Ui\Controllers;

use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Repository\UserRepository;
use Mailer\Security\Password;
use Mailer\Support\MailerException;
use Mailer\Ui\Auth;
use Mailer\Ui\View;
use Throwable;

/**
 * Пользователи панели. Права у всех одинаковые, поэтому управлять ими может любой вошедший.
 */
final class UsersController
{
    private UserRepository $users;

    public function __construct()
    {
        $this->users = new UserRepository();
    }

    public function index(Request $request): Response
    {
        return Response::html(View::render('users', [
            'active'  => 'users',
            'items'   => $this->users->all(),
            'current' => Auth::user(),
        ], 'Пользователи'));
    }

    public function form(Request $request, ?int $id): Response
    {
        $user = $id !== null ? $this->users->find($id) : null;

        if ($id !== null && $user === null) {
            View::flash('Пользователь не найден', 'error');

            return Response::redirect(View::url('/users'));
        }

        return Response::html(View::render('user_form', [
            'active'  => 'users',
            'user'    => $user,
            'current' => Auth::user(),
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
                ]);

                View::flash('Пользователь «' . $user['login'] . '» создан');

                return Response::redirect(View::url('/users'));
            }

            $current = Auth::user();
            if ($current !== null && (int) $current['id'] === $id && !$active) {
                throw new MailerException('Нельзя отключить самого себя');
            }

            $this->users->update($id, ['login' => $login, 'name' => $name, 'active' => $active]);

            if ($password !== '') {
                $this->users->setPassword($id, $password);
            }

            View::flash('Пользователь сохранён');

            return Response::redirect(View::url('/users'));
        } catch (Throwable $e) {
            View::flash($e->getMessage(), 'error');

            return Response::redirect($id === 0 ? View::url('/users/new') : View::url('/users/' . $id));
        }
    }

    /**
     * Действия из списка: удалить, включить, отключить.
     */
    public function action(Request $request, int $id, string $action): Response
    {
        $current = Auth::user();
        $user    = $this->users->find($id);

        if ($user === null) {
            View::flash('Пользователь не найден', 'error');

            return Response::redirect(View::url('/users'));
        }

        try {
            if ($current !== null && (int) $current['id'] === $id && $action !== 'password') {
                throw new MailerException('Себя нельзя удалить или отключить — попросите об этом коллегу');
            }

            switch ($action) {
                case 'delete':
                    $this->users->delete($id);
                    View::flash('Пользователь «' . $user['login'] . '» удалён');
                    break;

                case 'enable':
                    $this->users->update($id, ['active' => true]);
                    View::flash('Пользователь «' . $user['login'] . '» включён');
                    break;

                case 'disable':
                    $this->users->update($id, ['active' => false]);
                    View::flash('Пользователь «' . $user['login'] . '» отключён');
                    break;

                case 'password':
                    $password = Password::generate();
                    $this->users->setPassword($id, $password);
                    View::flash('Новый пароль для «' . $user['login'] . '»: ' . $password);
                    break;

                default:
                    View::flash('Неизвестное действие: ' . $action, 'error');
            }
        } catch (Throwable $e) {
            View::flash($e->getMessage(), 'error');
        }

        return Response::redirect(View::url('/users'));
    }
}
