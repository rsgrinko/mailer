<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Repository\TransportRepository;

/**
 * сделать основным.
 */
final class TransportDefaultCommand extends Command
{
    public function name(): string
    {
        return 'transport:default';
    }

    public function description(): string
    {
        return 'сделать основным';
    }

    public function usage(): string
    {
        return 'transport:default <имя>';
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

        $repository->setDefault((int) $transport['id']);
        $this->line('Основной транспорт теперь «' . $name . '».');

        return 0;
    
    }
}
