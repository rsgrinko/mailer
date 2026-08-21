<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Repository\ProjectRepository;
use Mailer\Repository\TransportRepository;
use Mailer\Repository\UserRepository;
use Mailer\Repository\WebhookSubscriptionRepository;

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
        return 'key:create <имя> [--transport=] [--owner=логин] [--limit-day=] [--webhook=]';
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

        // Без владельца проект остаётся ничьим: в панели его увидят только те,
        // у кого есть доступ к чужим данным
        $ownerId = 0;
        if (isset($this->options['owner'])) {
            $owner = (new UserRepository())->findByLogin((string) $this->options['owner']);
            if ($owner === null) {
                $this->line('Пользователь «' . $this->options['owner'] . '» не найден');

                return 1;
            }
            $ownerId = (int) $owner['id'];
        }

        $created = (new ProjectRepository())->create([
            'name'            => $name,
            'owner_id'        => $ownerId,
            'description'     => $this->options['description'] ?? null,
            'transport_id'    => $transportId,
            'rate_limit_hour' => (int) ($this->options['limit-hour'] ?? 0),
            'rate_limit_day'  => (int) ($this->options['limit-day'] ?? 0),
            'default_from_email' => $this->options['from'] ?? null,
            'default_from_name'  => $this->options['from-name'] ?? null,
        ]);

        $this->line('Проект создан: ' . $name);

        $webhook = trim((string) ($this->options['webhook'] ?? ''));
        if ($webhook !== '') {
            // Без списка событий подписка получает все — так же, как из панели
            (new WebhookSubscriptionRepository())->create([
                'project_id' => (int) $created['project']['id'],
                'name'       => 'Вебхук проекта',
                'url'        => $webhook,
            ]);

            $this->line('Вебхук на все события: ' . $webhook);
        }

        $this->line('API-ключ (сохраните, он больше не покажется):');
        $this->line('  ' . $created['key']);

        return 0;
    
    }
}
