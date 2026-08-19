<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Repository\UserRepository;
use Mailer\Security\Password;

/**
 * завести пользователя панели.
 */
final class UserCreateCommand extends Command
{
    public function name(): string
    {
        return 'user:create';
    }

    public function description(): string
    {
        return 'завести пользователя панели';
    }

    public function usage(): string
    {
        return 'user:create <логин> [--password=] [--name=]';
    }

    public function run(): int
    {

        $login = $this->args[0] ?? '';
        if ($login === '') {
            $this->line('Укажите логин: php bin/mailer user:create ivan');

            return 1;
        }

        $password = (string) ($this->options['password'] ?? '');
        $generated = $password === '';
        if ($generated) {
            $password = Password::generate();
        }

        $user = (new UserRepository())->create([
            'login'    => $login,
            'password' => $password,
            'name'     => $this->options['name'] ?? '',
            'active'   => true,
        ]);

        $this->line('Пользователь создан: ' . $user['login']);
        if ($generated) {
            $this->line('Пароль (больше не покажется): ' . $password);
        }

        return 0;
    
    }
}
