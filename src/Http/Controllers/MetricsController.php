<?php

declare(strict_types=1);

namespace Mailer\Http\Controllers;

use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Queue\Worker;
use Mailer\Repository\MessageRepository;
use Mailer\Repository\ProjectRepository;
use Mailer\Repository\SettingRepository;
use Mailer\Repository\SuppressionRepository;
use Mailer\Repository\TransportRepository;
use Mailer\Repository\WebhookRepository;
use Mailer\Repository\WebhookSubscriptionRepository;
use Mailer\Storage\Database;
use Mailer\Support\Logger;
use Throwable;

/**
 * Метрики для Prometheus — обычный текст в формате экспозиции.
 *
 * Отдаём срез состояния: сколько писем в каких статусах, что с очередью, жив ли воркер.
 * Счётчиков в понимании Prometheus здесь нет: все значения читаются из базы, а чистка
 * старых писем их уменьшает. Поэтому всё — gauge, и rate() по ним считать не надо.
 *
 * Доступ закрывает прослойка metrics-token: без токена в .env адрес открыт, и тогда
 * его закрывают на nginx (в примере конфига это показано).
 */
final class MetricsController
{
    private MessageRepository $messages;
    private WebhookRepository $webhooks;
    private TransportRepository $transports;
    private ProjectRepository $projects;
    private SettingRepository $settings;
    private SuppressionRepository $suppressions;
    private WebhookSubscriptionRepository $subscriptions;

    /** Сколько групп метрик не собралось — отдаём это отдельной метрикой */
    private int $failed = 0;

    public function __construct(
        MessageRepository $messages,
        WebhookRepository $webhooks,
        TransportRepository $transports,
        ProjectRepository $projects,
        SettingRepository $settings,
        SuppressionRepository $suppressions,
        WebhookSubscriptionRepository $subscriptions
    ) {
        $this->messages      = $messages;
        $this->webhooks      = $webhooks;
        $this->transports    = $transports;
        $this->projects      = $projects;
        $this->settings      = $settings;
        $this->suppressions  = $suppressions;
        $this->subscriptions = $subscriptions;
    }

    /**
     * GET /metrics
     */
    public function index(Request $request): Response
    {
        $lines = [];

        try {
            Database::instance()->value('SELECT 1');
            $lines = $this->collect();
            $up    = 1;
        } catch (Throwable $e) {
            // Мониторингу нужен ответ даже с лежащей базой: по mailer_up и сработает алерт
            (new Logger('api'))->error('Метрики: база недоступна', ['error' => $e->getMessage()]);
            $up = 0;
        }

        $body = $this->metric('mailer_up', 'Сервис отвечает и видит базу', [['value' => $up]])
            . implode('', $lines);

        return Response::text($body)
            ->withHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
    }

    /**
     * Все метрики, кроме mailer_up: их можно собрать только с живой базой.
     *
     * @return array<int, string>
     */
    private function collect(): array
    {
        $lines = [];

        $this->section($lines, 'messages', function (): array {
            $stats = $this->messages->stats();

            $byStatus = [];
            foreach ($stats['by_status'] as $status => $count) {
                $byStatus[] = ['labels' => ['status' => (string) $status], 'value' => (int) $count];
            }

            return [
                $this->metric('mailer_messages', 'Письма по статусам', $byStatus),
                $this->metric('mailer_messages_total', 'Всего писем в базе', [['value' => (int) $stats['total']]]),
                $this->metric('mailer_queue_ready', 'Писем готово к отправке прямо сейчас', [
                    ['value' => (int) $stats['queue_ready']],
                ]),
                $this->metric('mailer_queue_delayed', 'Писем ждёт повторной попытки или своего времени', [
                    ['value' => (int) $stats['queue_delayed']],
                ]),
                $this->metric('mailer_queue_oldest_seconds', 'Возраст самого старого письма в очереди, секунды', [
                    ['value' => $this->age((string) ($stats['oldest_queued'] ?? ''))],
                ]),
                $this->metric('mailer_messages_today', 'Письма за сегодня', [
                    ['labels' => ['result' => 'sent'], 'value' => (int) $stats['today_sent']],
                    ['labels' => ['result' => 'failed'], 'value' => (int) $stats['today_failed']],
                ]),
                $this->metric('mailer_last_sent_seconds', 'Сколько секунд назад ушло последнее письмо', [
                    ['value' => $this->age((string) ($this->messages->lastSentAt() ?? ''))],
                ]),
            ];
        });

        $this->section($lines, 'webhooks', function (): array {
            $samples = [];
            foreach ($this->webhooks->countByStatus() as $status => $count) {
                $samples[] = ['labels' => ['status' => (string) $status], 'value' => (int) $count];
            }

            return [
                $this->metric('mailer_webhooks', 'Доставки вебхуков по статусам', $samples),
                $this->metric('mailer_webhook_subscriptions', 'Включённые вебхуки проектов', [
                    ['value' => $this->subscriptions->countActive()],
                ]),
            ];
        });

        $this->section($lines, 'suppressions', function (): array {
            $samples = [];
            foreach ($this->suppressions->countByReason() as $reason => $count) {
                $samples[] = ['labels' => ['reason' => (string) $reason], 'value' => (int) $count];
            }

            return [$this->metric('mailer_suppressions', 'Закрытые адреса по причинам', $samples)];
        });

        $this->section($lines, 'transports', fn (): array => [
            $this->metric('mailer_transports', 'Транспорты', [
                ['labels' => ['state' => 'all'], 'value' => Database::instance()->count('transports')],
                ['labels' => ['state' => 'active'], 'value' => $this->transports->countActive()],
            ]),
            $this->metric('mailer_projects', 'Проекты', [
                ['labels' => ['state' => 'all'], 'value' => Database::instance()->count('projects')],
                ['labels' => ['state' => 'active'], 'value' => $this->projects->countActive()],
            ]),
        ]);

        $this->section($lines, 'worker', fn (): array => $this->worker());

        $lines[] = $this->metric(
            'mailer_metrics_failed',
            'Групп метрик, которые не удалось собрать (обычно не накатана миграция)',
            [['value' => $this->failed]]
        );

        return $lines;
    }

    /**
     * Одна группа метрик.
     *
     * Упавшая группа не утаскивает за собой весь ответ: `mailer_up = 0` должно значить
     * «база недоступна», а не «в одной таблице беда». Так бывает, когда код приехал
     * раньше миграции — тогда остальные метрики собираются как обычно, а о пропаже
     * говорит `mailer_metrics_failed`.
     *
     * @param array<int, string> $lines
     * @param callable(): array<int, string> $collect
     */
    private function section(array &$lines, string $name, callable $collect): void
    {
        try {
            foreach ($collect() as $line) {
                $lines[] = $line;
            }
        } catch (Throwable $e) {
            $this->failed++;

            (new Logger('api'))->warning('Метрики: группа не собралась', [
                'group' => $name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Состояние воркера по его отметке в settings.
     *
     * @return array<int, string>
     */
    private function worker(): array
    {
        $raw = $this->settings->get(Worker::HEARTBEAT_KEY);

        if ($raw === null) {
            return [
                $this->metric('mailer_worker_up', 'Воркер отчитывался за последние две минуты', [['value' => 0]]),
            ];
        }

        $data    = (array) json_decode($raw, true);
        $seconds = $this->age((string) ($data['time'] ?? ''));

        return [
            $this->metric('mailer_worker_up', 'Воркер отчитывался за последние две минуты', [
                ['value' => $seconds >= 0 && $seconds < 120 ? 1 : 0],
            ]),
            $this->metric('mailer_worker_last_seen_seconds', 'Сколько секунд назад воркер отчитывался', [
                ['value' => $seconds],
            ]),
            $this->metric('mailer_worker_processed', 'Писем обработано текущим процессом воркера', [
                ['value' => (int) ($data['processed'] ?? 0)],
            ]),
        ];
    }

    /**
     * Сколько секунд прошло с момента. Минус один — момента не было: ноль здесь врал бы,
     * будто событие только что случилось.
     */
    private function age(string $time): int
    {
        if ($time === '') {
            return -1;
        }

        $timestamp = strtotime($time);

        return $timestamp === false ? -1 : max(0, time() - $timestamp);
    }

    /**
     * Одна метрика: заголовки HELP и TYPE плюс строки значений.
     *
     * @param array<int, array{labels?: array<string, string>, value: int|float}> $samples
     */
    private function metric(string $name, string $help, array $samples): string
    {
        $out = '# HELP ' . $name . ' ' . $help . "\n"
            . '# TYPE ' . $name . " gauge\n";

        foreach ($samples as $sample) {
            $labels = '';
            if (!empty($sample['labels'])) {
                $pairs = [];
                foreach ($sample['labels'] as $key => $value) {
                    $pairs[] = $key . '="' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value) . '"';
                }
                $labels = '{' . implode(',', $pairs) . '}';
            }

            $out .= $name . $labels . ' ' . $sample['value'] . "\n";
        }

        return $out;
    }
}
