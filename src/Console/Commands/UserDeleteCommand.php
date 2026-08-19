<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Repository\UserRepository;

/**
 * удалить пользователя (--force — даже последнего).
 */
final class UserDeleteCommand extends Command
{
    public function name(): string
    {
        return 'user:delete';
    }

    public function description(): string
    {
        return 'удалить пользователя (--force — даже последнего)';
    }

    public function usage(): string
    {
        return 'user:delete <логин> [--force]';
    }

    public function run(): int
    {

        $login = $this->args[0] ?? '';
        $users = new UserRepository();
        $user  = $login === '' ? null : $users->findByLogin($login);

        if ($user === null) {
            $this->line('Пользователь не найден: php bin/mailer user:delete ivan');

            return 1;
        }

        $users->delete((int) $user['id'], isset($this->options['force']));
        $this->line('Пользователь «' . $user['login'] . '» удалён');

        return 0;
    
    }
}
