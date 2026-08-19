<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Repository\ProjectRepository;

/**
 * отключить проект.
 */
final class KeyRevokeCommand extends Command
{
    public function name(): string
    {
        return 'key:revoke';
    }

    public function description(): string
    {
        return 'отключить проект';
    }

    public function usage(): string
    {
        return 'key:revoke <имя>';
    }

    public function run(): int
    {

        $name     = $this->args[0] ?? '';
        $projects = new ProjectRepository();
        $project  = $projects->findByName($name);

        if ($project === null) {
            $this->line('Проект «' . $name . '» не найден');

            return 1;
        }

        $projects->update((int) $project['id'], ['active' => false]);
        $this->line('Проект «' . $name . '» отключён, его ключ больше не принимается.');

        return 0;
    
    }
}
