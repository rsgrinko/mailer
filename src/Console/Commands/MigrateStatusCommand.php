<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Storage\Migrator;

/**
 * какие миграции применены, а какие нет.
 */
final class MigrateStatusCommand extends Command
{
    public function name(): string
    {
        return 'migrate:status';
    }

    public function description(): string
    {
        return 'какие миграции применены, а какие нет';
    }

    public function run(): int
    {
        $migrator = new Migrator();
        $applied  = $migrator->applied();
        $unknown  = $migrator->unknown();

        foreach (array_keys($migrator->migrations()) as $name) {
            $mark = in_array($name, $applied, true) ? 'применена' : 'ждёт';
            $this->line($this->pad($name, 40) . $mark);
        }

        if ($unknown !== []) {
            $this->line();
            $this->line('В базе есть миграции, которых нет в коде — похоже на откат релиза:');
            foreach ($unknown as $name) {
                $this->line('  ' . $name);
            }
        }

        return 0;
    }
}
