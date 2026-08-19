<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Repository\ProjectRepository;

/**
 * выдать новый ключ.
 */
final class KeyRegenerateCommand extends Command
{
    public function name(): string
    {
        return 'key:regenerate';
    }

    public function description(): string
    {
        return 'выдать новый ключ';
    }

    public function usage(): string
    {
        return 'key:regenerate <имя>';
    }

    public function run(): int
    {

        $name    = $this->args[0] ?? '';
        $projects = new ProjectRepository();
        $project = $projects->findByName($name);

        if ($project === null) {
            $this->line('Проект «' . $name . '» не найден');

            return 1;
        }

        $key = $projects->regenerateKey((int) $project['id']);
        $this->line('Новый ключ проекта ' . $name . ':');
        $this->line('  ' . $key);
        $this->line('Старый ключ больше не работает.');

        return 0;
    
    }
}
