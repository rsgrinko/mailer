<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Queue\Worker;
use Mailer\Repository\MessageRepository;
use Mailer\Repository\SettingRepository;
use Mailer\Repository\WebhookRepository;
use Mailer\Security\Crypto;
use Mailer\Storage\Database;

/**
 * общее состояние сервиса.
 */
final class StatusCommand extends Command
{
    public function name(): string
    {
        return 'status';
    }

    public function description(): string
    {
        return 'общее состояние сервиса';
    }

    public function usage(): string
    {
        return 'status';
    }

    public function run(): int
    {

        $messages = new MessageRepository();
        $stats    = $messages->stats();

        $this->line('=== Состояние сервиса ===');
        $this->line('База данных:        ' . Database::instance()->driver());
        $this->line('Ключ шифрования:    ' . (Crypto::hasKey() ? 'задан' : 'НЕ задан (пароли лежат открытым текстом)'));
        $this->line('Всего писем:        ' . $stats['total']);

        foreach ($stats['by_status'] as $status => $count) {
            $this->line('  ' . $this->pad($status, 18) . $count);
        }

        $this->line('Готовы к отправке:  ' . $stats['queue_ready']);
        $this->line('Ждут своего часа:   ' . $stats['queue_delayed']);
        $this->line('Отправлено сегодня: ' . $stats['today_sent']);
        $this->line('Ошибок сегодня:     ' . $stats['today_failed']);

        $heartbeat = (new SettingRepository())->get(Worker::HEARTBEAT_KEY);
        if ($heartbeat === null) {
            $this->line('Воркер:             ни разу не запускался');
        } else {
            $data    = (array) json_decode($heartbeat, true);
            $seconds = time() - (int) strtotime((string) ($data['time'] ?? 'now'));
            $this->line('Воркер:             ' . ($seconds < 120 ? 'работает' : 'молчит')
                . ' (последний отклик ' . $seconds . ' с назад, обработано ' . ($data['processed'] ?? 0) . ')');
        }

        $webhooks = (new WebhookRepository())->countByStatus();
        $this->line('Вебхуки:            в очереди ' . $webhooks['queued'] . ', доставлено ' . $webhooks['delivered'] . ', не удалось ' . $webhooks['failed']);

        return 0;
    
    }
}
