<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Repository\RoleRepository;
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
        return 'user:create <логин> [--password=] [--name=] [--role=название]';
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

        // Роль по умолчанию — «Пользователь»: свои проекты и письма, чужого не видно
        $roleName = (string) ($this->options['role'] ?? RoleRepository::DEFAULT);
        $role     = (new RoleRepository())->findByName($roleName);

        if ($role === null) {
            $this->line('Роль «' . $roleName . '» не найдена. Список: php bin/mailer role:list');

            return 1;
        }

        $user = (new UserRepository())->create([
            'login'    => $login,
            'password' => $password,
            'name'     => $this->options['name'] ?? '',
            'active'   => true,
            'role_id'  => (int) $role['id'],
        ]);

        $this->line('Пользователь создан: ' . $user['login'] . ', роль: ' . $role['name']);
        if ($generated) {
            $this->line('Пароль (больше не покажется): ' . $password);
        }

        return 0;
    
    }
}
