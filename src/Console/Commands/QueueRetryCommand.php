<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Queue\Queue;
use Mailer\Repository\MessageRepository;

/**
 * повторить письмо или все неудачные.
 */
final class QueueRetryCommand extends Command
{
    public function name(): string
    {
        return 'queue:retry';
    }

    public function description(): string
    {
        return 'повторить письмо или все неудачные';
    }

    public function usage(): string
    {
        return 'queue:retry <id|--failed>';
    }

    public function run(): int
    {

        $queue    = new Queue();
        $messages = new MessageRepository();

        if (isset($this->options['failed'])) {
            $count = 0;
            $page  = $messages->paginate(['status' => MessageRepository::FAILED], 1, 200);

            foreach ($page['items'] as $row) {
                $count += $queue->retry((int) $row['id'], 'Массовый повтор из CLI') ? 1 : 0;
            }

            $this->line('Возвращено в очередь писем: ' . $count);

            return 0;
        }

        $id  = $this->args[0] ?? '';
        $row = $messages->findAny($id);

        if ($row === null) {
            $this->line('Письмо не найдено: ' . $id);

            return 1;
        }

        if (!$queue->retry((int) $row['id'], 'Повтор из CLI')) {
            $this->line('Повторить нельзя: письмо уже отправлено.');

            return 1;
        }

        $this->line('Письмо ' . $row['uuid'] . ' снова в очереди.');

        return 0;
    
    }
}
