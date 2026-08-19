<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Queue\Worker;

/**
 * запустить воркер.
 */
final class WorkerCommand extends Command
{
    public function name(): string
    {
        return 'worker';
    }

    public function description(): string
    {
        return 'запустить воркер';
    }

    public function usage(): string
    {
        return 'worker [--once] [--limit=N]';
    }

    public function run(): int
    {

        $once  = isset($this->options['once']);
        $limit = isset($this->options['limit']) ? (int) $this->options['limit'] : null;

        (new Worker())->run($once, $limit);

        return 0;
    
    }
}
