<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Transport\TransportFactory;
use Throwable;

/**
 * проверить подключение.
 */
final class TransportTestCommand extends Command
{
    public function name(): string
    {
        return 'transport:test';
    }

    public function description(): string
    {
        return 'проверить подключение';
    }

    public function usage(): string
    {
        return 'transport:test <имя>';
    }

    public function run(): int
    {

        $name = $this->args[0] ?? '';
        if ($name === '') {
            $this->line('Укажите имя транспорта');

            return 1;
        }

        $transport = (new TransportFactory())->byName($name);

        $this->line('Проверяем «' . $name . '»…');

        try {
            $this->line($transport->test());
            $this->line('Транспорт работает.');

            return 0;
        } catch (Throwable $e) {
            $this->line('Не получилось: ' . $e->getMessage());

            return 1;
        }
    
    }
}
