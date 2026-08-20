<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Repository\TransportRepository;
use Mailer\Repository\UserRepository;
use Mailer\Support\Config;

/**
 * добавить транспорт.
 */
final class TransportAddCommand extends Command
{
    public function name(): string
    {
        return 'transport:add';
    }

    public function description(): string
    {
        return 'добавить транспорт';
    }

    public function usage(): string
    {
        return 'transport:add <имя> --type=smtp --host= --port= --encryption= --user= --password= [--from=] [--from-name=] [--force-from] [--default] [--owner=логин] [--shared]';
    }

    public function run(): int
    {

        $name = $this->args[0] ?? '';
        $type = $this->options['type'] ?? 'smtp';

        if ($name === '') {
            $this->line('Укажите имя транспорта: php bin/mailer transport:add yandex --type=smtp --host=smtp.yandex.ru ...');

            return 1;
        }

        $settings = [];

        if ($type === 'smtp') {
            $settings = [
                'host'       => $this->options['host'] ?? 'smtp.yandex.ru',
                'port'       => (int) ($this->options['port'] ?? 465),
                'encryption' => $this->options['encryption'] ?? 'ssl',
                'username'   => $this->options['user'] ?? '',
                'password'   => $this->options['password'] ?? '',
                'auth_mode'  => $this->options['auth'] ?? 'auto',
            ];
        } elseif ($type === 'sendmail') {
            $settings = ['path' => $this->options['path'] ?? '/usr/sbin/sendmail'];
        } elseif ($type === 'log') {
            $settings = ['dir' => $this->options['dir'] ?? (Config::get('paths.spool') . '/sent')];
        } elseif (in_array($type, ['failover', 'roundrobin'], true)) {
            $list = trim((string) ($this->options['transports'] ?? ''));
            if ($list === '') {
                $this->line('Для составного транспорта укажите --transports=имя1,имя2');

                return 1;
            }
            $settings = ['transports' => array_map('trim', explode(',', $list))];
        }

        // Отправитель транспорта важнее того, что указал клиент
        if (isset($this->options['force-from'])) {
            $settings['force_from'] = true;
        }

        // Без владельца транспорт общесервисный: с --shared его увидят все пользователи
        $ownerId = 0;
        if (isset($this->options['owner'])) {
            $owner = (new UserRepository())->findByLogin((string) $this->options['owner']);
            if ($owner === null) {
                $this->line('Пользователь «' . $this->options['owner'] . '» не найден');

                return 1;
            }
            $ownerId = (int) $owner['id'];
        }

        $id = (new TransportRepository())->create([
            'name'        => $name,
            'owner_id'    => $ownerId,
            'shared'      => isset($this->options['shared']),
            'type'        => $type,
            'settings'    => $settings,
            'from_email'  => $this->options['from'] ?? null,
            'from_name'   => $this->options['from-name'] ?? null,
            'daily_limit' => (int) ($this->options['daily-limit'] ?? 0),
            'priority'    => (int) ($this->options['priority'] ?? 100),
            'is_default'  => isset($this->options['default']),
        ]);

        $this->line('Транспорт «' . $name . '» создан (id=' . $id . ').');
        $this->line('Проверить: php bin/mailer transport:test ' . $name);

        return 0;
    
    }
}
