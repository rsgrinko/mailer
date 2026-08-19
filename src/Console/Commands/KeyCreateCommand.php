<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Repository\ProjectRepository;
use Mailer\Repository\TransportRepository;

/**
 * создать проект и выдать ключ.
 */
final class KeyCreateCommand extends Command
{
    public function name(): string
    {
        return 'key:create';
    }

    public function description(): string
    {
        return 'создать проект и выдать ключ';
    }

    public function usage(): string
    {
        return 'key:create <имя> [--transport=] [--limit-day=] [--webhook=]';
    }

    public function run(): int
    {

        $name = $this->args[0] ?? '';
        if ($name === '') {
            $this->line('Укажите имя проекта: php bin/mailer key:create my-site');

            return 1;
        }

        $transportId = null;
        if (isset($this->options['transport'])) {
            $transport = (new TransportRepository())->findByName($this->options['transport']);
            if ($transport === null) {
                $this->line('Транспорт «' . $this->options['transport'] . '» не найден');

                return 1;
            }
            $transportId = (int) $transport['id'];
        }

        $created = (new ProjectRepository())->create([
            'name'            => $name,
            'description'     => $this->options['description'] ?? null,
            'transport_id'    => $transportId,
            'rate_limit_hour' => (int) ($this->options['limit-hour'] ?? 0),
            'rate_limit_day'  => (int) ($this->options['limit-day'] ?? 0),
            'webhook_url'     => $this->options['webhook'] ?? null,
            'default_from_email' => $this->options['from'] ?? null,
            'default_from_name'  => $this->options['from-name'] ?? null,
        ]);

        $this->line('Проект создан: ' . $name);
        $this->line('API-ключ (сохраните, он больше не покажется):');
        $this->line('  ' . $created['key']);

        return 0;
    
    }
}
