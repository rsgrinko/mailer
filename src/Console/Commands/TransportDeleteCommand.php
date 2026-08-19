<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Repository\TransportRepository;

/**
 * удалить транспорт.
 */
final class TransportDeleteCommand extends Command
{
    public function name(): string
    {
        return 'transport:delete';
    }

    public function description(): string
    {
        return 'удалить транспорт';
    }

    public function usage(): string
    {
        return 'transport:delete <имя>';
    }

    public function run(): int
    {

        $name       = $this->args[0] ?? '';
        $repository = new TransportRepository();
        $transport  = $repository->findByName($name);

        if ($transport === null) {
            $this->line('Транспорт «' . $name . '» не найден');

            return 1;
        }

        $repository->delete((int) $transport['id']);
        $this->line('Транспорт «' . $name . '» удалён.');

        return 0;
    
    }
}
