<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Smtpd\SmtpServer;

/**
 * локальный SMTP-релей для чужих приложений.
 */
final class SmtpdCommand extends Command
{
    public function name(): string
    {
        return 'smtpd';
    }

    public function description(): string
    {
        return 'локальный SMTP-релей для чужих приложений';
    }

    public function usage(): string
    {
        return 'smtpd';
    }

    public function run(): int
    {

        $server = new SmtpServer(
            $this->options['host'] ?? null,
            isset($this->options['port']) ? (int) $this->options['port'] : null,
            fn (string $line): mixed => $this->line($line)
        );

        $server->run();

        return 0;
    
    }
}
