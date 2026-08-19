<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Support\Logger;

/**
 * удалить старые файлы логов.
 */
final class LogsPurgeCommand extends Command
{
    public function name(): string
    {
        return 'logs:purge';
    }

    public function description(): string
    {
        return 'удалить старые файлы логов';
    }

    public function usage(): string
    {
        return 'logs:purge [--days=30]';
    }

    /**
     * Убирает старые файлы логов. По умолчанию срок берётся из LOG_KEEP_DAYS.
     */
    public function run(): int
    {
        $days    = isset($this->options['days']) ? (int) $this->options['days'] : null;
        $removed = (new Logger('cli'))->purge($days);

        if ($removed === []) {
            $this->line('Удалять нечего.');

            return 0;
        }

        $this->line('Удалено файлов: ' . count($removed));
        foreach ($removed as $name) {
            $this->line('  ' . $name);
        }

        return 0;
    
    }
}
