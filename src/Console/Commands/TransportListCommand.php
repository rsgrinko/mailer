<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\RateLimit\RateLimiter;
use Mailer\Repository\TransportRepository;
use Mailer\Support\Str;

/**
 * список транспортов.
 */
final class TransportListCommand extends Command
{
    public function name(): string
    {
        return 'transport:list';
    }

    public function description(): string
    {
        return 'список транспортов';
    }

    public function usage(): string
    {
        return 'transport:list';
    }

    public function run(): int
    {

        $transports = (new TransportRepository())->all();
        $limiter    = new RateLimiter();

        if ($transports === []) {
            $this->line('Транспортов нет. Добавьте: php bin/mailer transport:add yandex --type=smtp ...');

            return 0;
        }

        $this->line($this->pad('ID', 5) . $this->pad('Имя', 20) . $this->pad('Тип', 12) . $this->pad('Куда', 30) . $this->pad('Сегодня', 12) . 'Признаки');

        foreach ($transports as $transport) {
            $settings = (array) $transport['settings'];
            $target   = match ($transport['type']) {
                'smtp'     => ($settings['host'] ?? '') . ':' . ($settings['port'] ?? ''),
                'sendmail' => (string) ($settings['path'] ?? ''),
                'log'      => (string) ($settings['dir'] ?? ''),
                default    => implode(',', (array) ($settings['transports'] ?? [])),
            };

            $flags = [];
            if ((int) $transport['is_default'] === 1) {
                $flags[] = 'основной';
            }
            if ((int) $transport['active'] !== 1) {
                $flags[] = 'выключен';
            }
            if (($transport['last_error'] ?? null) !== null) {
                $flags[] = 'была ошибка';
            }

            $used = $limiter->transportUsage((int) $transport['id']);

            $this->line(
                $this->pad((string) $transport['id'], 5)
                . $this->pad(Str::limit((string) $transport['name'], 18), 20)
                . $this->pad((string) $transport['type'], 12)
                . $this->pad(Str::limit($target, 28), 30)
                . $this->pad($used . ((int) $transport['daily_limit'] > 0 ? '/' . $transport['daily_limit'] : ''), 12)
                . implode(', ', $flags)
            );
        }

        return 0;
    
    }
}
