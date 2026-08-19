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
        return 'migrate';
    }

    public function run(): int
    {

        $migrator = new Migrator();
        $applied  = $migrator->run();

        if ($applied === []) {
            $this->line('Все миграции уже применены, база в порядке.');
        } else {
            foreach ($applied as $name) {
                $this->line('Применена миграция: ' . $name);
            }
        }

        $this->line('База: ' . Database::instance()->driver());

        return 0;
    
    }
}
