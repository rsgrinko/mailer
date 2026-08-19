<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Repository\UserRepository;
use Mailer\Security\Password;

/**
 * сменить пароль.
 */
final class UserPasswordCommand extends Command
{
    public function name(): string
    {
        return 'user:password';
    }

    public function description(): string
    {
        return 'сменить пароль';
    }

    public function usage(): string
    {
        return 'user:password <логин> [--password=]';
    }

    public function run(): int
    {

        $login = $this->args[0] ?? '';
        $users = new UserRepository();
        $user  = $login === '' ? null : $users->findByLogin($login);

        if ($user === null) {
            $this->line('Пользователь не найден: php bin/mailer user:password ivan [--password=]');

            return 1;
        }

        $password  = (string) ($this->options['password'] ?? '');
        $generated = $password === '';
        if ($generated) {
            $password = Password::generate();
        }

        $users->setPassword((int) $user['id'], $password);

        $this->line('Пароль пользователя «' . $user['login'] . '» изменён');
        if ($generated) {
            $this->line('Новый пароль (больше не покажется): ' . $password);
        }

        return 0;
    
    }
}
