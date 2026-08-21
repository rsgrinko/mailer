<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Storage\Migrator;

/**
 * откатить последнюю пачку миграций.
 */
final class MigrateRollbackCommand extends Command
{
    public function name(): string
    {
        return 'migrate:rollback';
    }

    public function description(): string
    {
        return 'откатить последнюю пачку миграций';
    }

    public function usage(): string
    {
        return 'migrate:rollback [--steps=1]';
    }

    public function run(): int
    {
        $steps = (int) ($this->options['steps'] ?? 1);

        $rolledBack = (new Migrator())->rollback(max(1, $steps));

        if ($rolledBack === []) {
            $this->line('Откатывать нечего: применённых миграций нет.');

            return 0;
        }

        foreach ($rolledBack as $name) {
            $this->line('Откачена миграция: ' . $name);
        }

        return 0;
    }
}
