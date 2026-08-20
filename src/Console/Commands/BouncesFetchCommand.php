<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Bounce\Collector;
use Mailer\Console\Command;
use Throwable;

/**
 * разобрать ящик отказов.
 */
final class BouncesFetchCommand extends Command
{
    public function name(): string
    {
        return 'bounces:fetch';
    }

    public function description(): string
    {
        return 'забрать отказы из почтового ящика и закрыть адреса';
    }

    public function usage(): string
    {
        return 'bounces:fetch [--limit=50]';
    }

    public function run(): int
    {
        if (!Collector::enabled()) {
            $this->line('Ящик отказов не настроен: включите BOUNCE_ENABLED и задайте BOUNCE_HOST в .env');

            return 1;
        }

        try {
            $result = (new Collector())->run((int) $this->option('limit', '50'));
        } catch (Throwable $e) {
            $this->line('Не получилось: ' . $e->getMessage());

            return 1;
        }

        $this->line('Прочитано писем:  ' . $result['fetched']);
        $this->line('Закрыто адресов:  ' . $result['suppressed']);
        $this->line('Без отказов:      ' . $result['skipped']);

        return 0;
    }
}
