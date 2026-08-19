<?php

declare(strict_types=1);

namespace Mailer\Queue;

use Mailer\RateLimit\RateLimiter;
use Mailer\Repository\SettingRepository;
use Mailer\Storage\Database;
use Mailer\Support\Config;
use Mailer\Support\Logger;
use Mailer\Webhook\WebhookSender;

/**
 * Фоновый воркер: берёт письма из очереди и отправляет их, а заодно рассылает вебхуки.
 *
 * Запуск: php bin/mailer worker  (демоном через systemd)
 *         php bin/mailer worker --once  (один проход, удобно для cron)
 */
final class Worker
{
    /** Ключ, под которым воркер отмечается «жив» — панель показывает это состояние */
    public const HEARTBEAT_KEY = 'worker:heartbeat';

    /** Сюда кладут время запроса на перезапуск: воркер увидит его и выйдет */
    public const RESTART_KEY = 'worker:restart';

    private Queue $queue;
    private Sender $sender;
    private WebhookSender $webhooks;
    private SettingRepository $settings;
    private RateLimiter $limiter;
    private Logger $logger;

    /** @var callable(string): void Куда печатать ход работы */
    private $output;

    private string $id;
    private bool $stopping = false;
    private bool $restarting = false;
    private int $processed = 0;

    /** Время старта: запросы на перезапуск, сделанные раньше, нас не касаются */
    private int $startedAt;

    /** Когда в последний раз чистили логи — чаще раза в сутки незачем */
    private int $logsPurgedAt = 0;

    public function __construct(?Database $db = null, ?callable $output = null)
    {
        $db = $db ?? Database::instance();

        $this->queue    = new Queue($db);
        $this->sender   = new Sender($db);
        $this->webhooks = new WebhookSender($db);
        $this->settings = new SettingRepository($db);
        $this->limiter  = new RateLimiter($db);
        $this->logger   = new Logger('worker');
        $this->output   = $output ?? static function (string $line): void {
            echo $line . PHP_EOL;
        };

        $this->id        = (gethostname() ?: 'host') . ':' . getmypid();
        $this->startedAt = time();
    }

    /**
     * Просит работающий воркер завершиться. Под systemd он тут же поднимется заново,
     * то есть это и есть «перезапустить»: нужен, например, после правки транспорта.
     */
    public static function requestRestart(?Database $db = null): void
    {
        (new SettingRepository($db ?? Database::instance()))->set(self::RESTART_KEY, (string) time());
    }

    /**
     * Основной цикл.
     *
     * @param bool $once      один проход и выход
     * @param int|null $limit сколько писем обработать максимум
     */
    public function run(bool $once = false, ?int $limit = null): int
    {
        $this->listenForSignals();
        $this->say('Воркер запущен (' . $this->id . ')');
        $this->logger->info('Воркер запущен', ['worker' => $this->id]);

        $batch = (int) Config::get('queue.batch', 20);
        $sleep = (int) Config::get('queue.sleep', 5);
        $tick  = 0;

        while (!$this->stopping) {
            $this->heartbeat();

            if ($this->restartRequested()) {
                $this->restarting = true;
                $this->stopping   = true;
                $this->say('Запрошен перезапуск, завершаем работу…');
                $this->logger->info('Запрошен перезапуск воркера', ['worker' => $this->id]);

                break;
            }

            // Раз в примерно 10 кругов подбираем зависшие письма и чистим счётчики
            if ($tick % 10 === 0) {
                $stuck = $this->queue->requeueStuck();
                if ($stuck > 0) {
                    $this->say('Вернули в очередь зависших писем: ' . $stuck);
                }
                $this->limiter->cleanup();
                $this->purgeLogs();
            }
            $tick++;

            $messages = $this->queue->claim($batch, $this->id);

            foreach ($messages as $row) {
                if ($this->stopping) {
                    break;
                }

                $result = $this->sender->send($row);
                $this->processed++;

                $this->say(sprintf(
                    '[%s] %s -> %s: %s',
                    date('H:i:s'),
                    (string) $row['uuid'],
                    $result['status'],
                    mb_substr($result['info'], 0, 160)
                ));

                if ($limit !== null && $this->processed >= $limit) {
                    $this->stopping = true;
                    break;
                }
            }

            // Вебхуки шлём тем же процессом — отдельный демон разводить незачем
            $this->webhooks->processQueue();

            if ($once) {
                break;
            }

            if ($messages === [] && !$this->stopping) {
                sleep(max(1, $sleep));
            }

            // pcntl есть не везде, но если есть — обработаем сигналы остановки
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }

        $this->heartbeat();
        $this->say($this->restarting
            ? 'Воркер остановлен для перезапуска, обработано писем: ' . $this->processed
            : 'Воркер завершил работу, обработано писем: ' . $this->processed);
        $this->logger->info('Воркер остановлен', [
            'worker'    => $this->id,
            'processed' => $this->processed,
            'restart'   => $this->restarting,
        ]);

        return $this->processed;
    }

    /**
     * Просили ли перезапуститься уже после того, как мы стартовали.
     */
    private function restartRequested(): bool
    {
        $requested = $this->settings->get(self::RESTART_KEY);

        return $requested !== null && (int) $requested > $this->startedAt;
    }

    /**
     * Раз в сутки убирает старые файлы логов: иначе var/log растёт бесконечно.
     */
    private function purgeLogs(): void
    {
        if (time() - $this->logsPurgedAt < 86400) {
            return;
        }

        $this->logsPurgedAt = time();
        $removed            = $this->logger->purge();

        if ($removed !== []) {
            $this->logger->info('Удалены старые логи', ['files' => $removed]);
        }
    }

    /**
     * Отметка «воркер жив» — по ней панель понимает, работает ли очередь.
     */
    private function heartbeat(): void
    {
        $this->settings->set(self::HEARTBEAT_KEY, (string) json_encode([
            'worker'    => $this->id,
            'time'      => Database::now(),
            'processed' => $this->processed,
            'pid'       => getmypid(),
            // Панель работает под веб-сервером и pcntl там не увидит — отчитываемся сами
            'php'       => PHP_VERSION,
            'pcntl'     => function_exists('pcntl_signal'),
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Аккуратная остановка по Ctrl+C или systemctl stop.
     */
    private function listenForSignals(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        $handler = function (): void {
            $this->say('Получен сигнал остановки, доработаем текущую пачку…');
            $this->stopping = true;
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }

    private function say(string $line): void
    {
        ($this->output)($line);
    }
}
