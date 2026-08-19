<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Repository\MessageRepository;
use Mailer\Support\Str;

/**
 * что сейчас в очереди.
 */
final class QueueStatusCommand extends Command
{
    public function name(): string
    {
        return 'queue:status';
    }

    public function description(): string
    {
        return 'что сейчас в очереди';
    }

    public function usage(): string
    {
        return 'queue:status';
    }

    public function run(): int
    {

        $messages = new MessageRepository();
        $stats    = $messages->stats();

        $this->line('В очереди готовы:  ' . $stats['queue_ready']);
        $this->line('Отложены:          ' . $stats['queue_delayed']);
        $this->line('Отправляются:      ' . ($stats['by_status']['sending'] ?? 0));
        $this->line('Ошибки:            ' . ($stats['by_status']['failed'] ?? 0));
        $this->line('Отправлено всего:  ' . ($stats['by_status']['sent'] ?? 0));

        if ($stats['oldest_queued'] !== null) {
            $this->line('Самое старое в очереди: ' . $stats['oldest_queued']);
        }

        $recent = $messages->paginate(['status' => MessageRepository::FAILED], 1, 5);
        if ($recent['items'] !== []) {
            $this->line('');
            $this->line('Последние неудачные:');
            foreach ($recent['items'] as $row) {
                $this->line('  ' . $row['uuid'] . '  ' . Str::limit((string) $row['subject'], 40)
                    . '  ' . Str::limit((string) $row['last_error'], 60));
            }
        }

        return 0;
    
    }
}
