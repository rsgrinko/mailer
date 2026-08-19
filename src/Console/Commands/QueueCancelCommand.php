<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Queue\Queue;
use Mailer\Repository\MessageRepository;

/**
 * отменить письмо.
 */
final class QueueCancelCommand extends Command
{
    public function name(): string
    {
        return 'queue:cancel';
    }

    public function description(): string
    {
        return 'отменить письмо';
    }

    public function usage(): string
    {
        return 'queue:cancel <id>';
    }

    public function run(): int
    {

        $id  = $this->args[0] ?? '';
        $row = (new MessageRepository())->findAny($id);

        if ($row === null) {
            $this->line('Письмо не найдено: ' . $id);

            return 1;
        }

        if (!(new Queue())->cancel((int) $row['id'], 'Отмена из CLI')) {
            $this->line('Письмо нельзя отменить: оно уже отправлено или отменено.');

            return 1;
        }

        $this->line('Письмо ' . $row['uuid'] . ' отменено.');

        return 0;
    
    }
}
