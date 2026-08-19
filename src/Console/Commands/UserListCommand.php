<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Repository\UserRepository;

/**
 * список пользователей.
 */
final class UserListCommand extends Command
{
    public function name(): string
    {
        return 'user:list';
    }

    public function description(): string
    {
        return 'список пользователей';
    }

    public function usage(): string
    {
        return 'user:list';
    }

    public function run(): int
    {

        $users = (new UserRepository())->all();

        if ($users === []) {
            $this->line('Пользователей нет. Создайте: php bin/mailer user:create ivan');

            return 0;
        }

        $this->line($this->pad('Логин', 24) . $this->pad('Состояние', 12) . 'Последний вход');
        foreach ($users as $user) {
            $this->line(
                $this->pad((string) $user['login'], 24)
                . $this->pad((int) $user['active'] === 1 ? 'активен' : 'отключён', 12)
                . (string) ($user['last_login_at'] ?? 'не входил')
            );
        }

        return 0;
    
    }
}
