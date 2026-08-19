<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Repository\MessageRepository;
use Mailer\Support\Config;

/**
 * удалить старые письма.
 */
final class QueuePurgeCommand extends Command
{
    public function name(): string
    {
        return 'queue:purge';
    }

    public function description(): string
    {
        return 'удалить старые письма';
    }

    public function usage(): string
    {
        return 'queue:purge [--status=sent] [--days=30]';
    }

    public function run(): int
    {

        $status = $this->options['status'] ?? MessageRepository::SENT;
        $days   = (int) ($this->options['days'] ?? Config::get('queue.keep_sent_days', 30));

        $deleted = (new MessageRepository())->purge($status, $days);
        $this->line('Удалено писем в статусе «' . $status . '» старше ' . $days . ' дней: ' . $deleted);

        return 0;
    
    }
}
