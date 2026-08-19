<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Queue\Worker;
use Mailer\Repository\SettingRepository;
use Mailer\Support\Config;

/**
 * попросить работающий воркер перезапуститься.
 */
final class WorkerRestartCommand extends Command
{
    public function name(): string
    {
        return 'worker:restart';
    }

    public function description(): string
    {
        return 'попросить работающий воркер перезапуститься';
    }

    public function usage(): string
    {
        return 'worker:restart';
    }

    /**
     * Просит работающий воркер завершиться: под systemd он сразу поднимется заново.
     */
    public function run(): int
    {

        $settings  = new SettingRepository();
        $heartbeat = $settings->get(Worker::HEARTBEAT_KEY);

        if ($heartbeat === null) {
            $this->line('Воркер ни разу не запускался — перезапускать нечего.');

            return 1;
        }

        Worker::requestRestart();

        $state = (array) json_decode($heartbeat, true);
        $sleep = (int) Config::get('queue.sleep', 5);

        $this->line('Запрос отправлен воркеру ' . (string) ($state['worker'] ?? '?'));
        $this->line('Он доработает текущую пачку и выйдет — это займёт до ' . $sleep . ' с.');
        $this->line('Если воркер под systemd, служба поднимет его сама; иначе запустите заново.');

        return 0;
    
    }
}
