<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Webhook\WebhookSender;

/**
 * разослать накопившиеся вебхуки.
 */
final class WebhookProcessCommand extends Command
{
    public function name(): string
    {
        return 'webhook:process';
    }

    public function description(): string
    {
        return 'разослать накопившиеся вебхуки';
    }

    public function usage(): string
    {
        return 'webhook:process';
    }

    public function run(): int
    {

        $count = (new WebhookSender())->processQueue(100);
        $this->line('Обработано вебхуков: ' . $count);

        return 0;
    
    }
}
