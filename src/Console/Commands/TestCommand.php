<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;

/**
 * прогнать тесты.
 */
final class TestCommand extends Command
{
    public function name(): string
    {
        return 'test';
    }

    public function description(): string
    {
        return 'прогнать тесты';
    }

    public function usage(): string
    {
        return 'test [--filter=] [--stop-on-failure] [--shuffle]';
    }

    public function run(): int
    {
        $runner = MAILER_ROOT . '/tests/run.php';

        if (!is_file($runner)) {
            $this->line('Тесты не найдены: ' . $runner);

            return 1;
        }

        // Раннер запускают и напрямую (php tests/run.php), поэтому опции он читает
        // из глобальной переменной, а своего разбора аргументов не имеет
        $GLOBALS['test_options'] = $this->options;

        return (int) require $runner;
    }
}
