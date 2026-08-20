<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Domain\Permission;
use Mailer\Repository\RoleRepository;

/**
 * роли панели и их права.
 */
final class RoleListCommand extends Command
{
    public function name(): string
    {
        return 'role:list';
    }

    public function description(): string
    {
        return 'роли панели и их права';
    }

    public function usage(): string
    {
        return 'role:list [название]';
    }

    public function run(): int
    {
        $roles  = new RoleRepository();
        $filter = trim((string) ($this->args[0] ?? ''));

        if ($filter !== '') {
            $role = $roles->findByName($filter);

            if ($role === null) {
                $this->line('Роль «' . $filter . '» не найдена');

                return 1;
            }

            $this->line($role['name'] . ' — людей: ' . $roles->usersCount((int) $role['id']));
            $this->line((string) ($role['description'] ?? ''));
            $this->line('');

            foreach ((array) $role['permissions'] as $code) {
                $this->line('  ' . $this->pad((string) $code, 22) . Permission::label((string) $code));
            }

            return 0;
        }

        $this->line($this->pad('Роль', 24) . $this->pad('Прав', 8) . $this->pad('Людей', 8) . 'Описание');

        foreach ($roles->all() as $role) {
            $this->line(
                $this->pad((string) $role['name'], 24)
                . $this->pad((string) count((array) $role['permissions']), 8)
                . $this->pad((string) $roles->usersCount((int) $role['id']), 8)
                . (string) ($role['description'] ?? '')
            );
        }

        $this->line('');
        $this->line('Права роли меняются в панели: /ui/roles');

        return 0;
    }
}
