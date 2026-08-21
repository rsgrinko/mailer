<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Storage\Database;
use Mailer\Storage\Migrator;

/**
 * создать или обновить таблицы.
 */
final class MigrateCommand extends Command
{
    public function name(): string
    {
        return 'migrate';
    }

    public function description(): string
    {
        return 'создать или обновить таблицы';
    }

    public function usage(): string
    {
        return 'migrate [--pretend]';
    }

    public function run(): int
    {
        $migrator = new Migrator();

        // --pretend показывает запросы, не трогая базу: перед накатом на боевую
        // полезно увидеть, что именно там выполнится
        if (isset($this->options['pretend'])) {
            $plan = $migrator->pretend();

            if ($plan === []) {
                $this->line('Все миграции уже применены, база в порядке.');

                return 0;
            }

            foreach ($plan as $name => $queries) {
                $this->line($name . ':');
                foreach ($queries as $sql) {
                    $this->line('  ' . preg_replace('/\s+/', ' ', $sql));
                }
                $this->line();
            }

            return 0;
        }

        $applied = $migrator->run();

        if ($applied === []) {
            $this->line('Все миграции уже применены, база в порядке.');
        } else {
            foreach ($applied as $name) {
                $this->line('Применена миграция: ' . $name);
            }

            // Так выглядит докат миграции, упавшей на середине: часть её шагов уже была
            $skipped = $migrator->skipped();
            if ($skipped !== []) {
                $this->line('Пропущено уже выполненных шагов: ' . count($skipped));
                foreach ($skipped as $sql) {
                    $this->line('  ' . preg_replace('/\s+/', ' ', $sql));
                }
            }
        }

        $this->line('База: ' . Database::instance()->driver());

        return 0;
    }
}
