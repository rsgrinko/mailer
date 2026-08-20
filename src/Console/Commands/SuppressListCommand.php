<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Repository\SuppressionRepository;
use Mailer\Support\Str;

/**
 * кто в стоп-листе.
 */
final class SuppressListCommand extends Command
{
    public function name(): string
    {
        return 'suppress:list';
    }

    public function description(): string
    {
        return 'закрытые адреса';
    }

    public function usage(): string
    {
        return 'suppress:list [строка для поиска] [--reason=bounce] [--limit=50]';
    }

    public function run(): int
    {
        $result = (new SuppressionRepository())->paginate(
            [
                'search' => $this->arg(0),
                'reason' => (string) $this->option('reason', ''),
            ],
            1,
            max(1, (int) $this->option('limit', '50'))
        );

        if ($result['items'] === []) {
            $this->line('Стоп-лист пуст.');

            return 0;
        }

        $this->line($this->pad('Адрес', 40) . $this->pad('Причина', 14) . $this->pad('Откуда', 12)
            . $this->pad('Охват', 12) . 'Когда');

        foreach ($result['items'] as $row) {
            $this->line(
                $this->pad(Str::limit((string) $row['email'], 38), 40)
                . $this->pad((string) $row['reason'], 14)
                . $this->pad((string) $row['source'], 12)
                . $this->pad($row['project_id'] === null ? 'все' : 'проект ' . (int) $row['project_id'], 12)
                . (string) $row['created_at']
            );
        }

        $this->line('');
        $this->line('Всего адресов: ' . $result['total']);

        return 0;
    }
}
