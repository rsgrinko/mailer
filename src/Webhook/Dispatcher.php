<?php

declare(strict_types=1);

namespace Mailer\Webhook;

use Mailer\Repository\ProjectRepository;
use Mailer\Repository\WebhookRepository;
use Mailer\Repository\WebhookSubscriptionRepository;
use Mailer\Storage\Database;
use Mailer\Support\Logger;
use Mailer\Support\Str;
use Throwable;

/**
 * Раздаёт событие всем подпискам проекта: одно событие — столько доставок,
 * сколько подписчиков его ждут.
 *
 * Место, где события превращаются в вебхуки, ровно одно. Тому, кто зовёт диспетчер,
 * не нужно знать ни про подписки, ни про формат тела: случилось — сообщили.
 *
 * Свои ошибки диспетчер глушит: вебхук — дело десятое, ронять из-за него отправку
 * письма нельзя.
 */
final class Dispatcher
{
    private WebhookSubscriptionRepository $subscriptions;
    private WebhookRepository $webhooks;
    private ProjectRepository $projects;
    private Logger $logger;

    public function __construct(?Database $db = null)
    {
        $db                  = $db ?? Database::instance();
        $this->subscriptions = new WebhookSubscriptionRepository($db);
        $this->webhooks      = new WebhookRepository($db);
        $this->projects      = new ProjectRepository($db);
        $this->logger        = new Logger('webhook');
    }

    /**
     * Событие про письмо. Возвращает, сколько доставок поставлено в очередь.
     *
     * @param array<string, mixed> $row  строка письма из базы
     * @param array<string, mixed> $data что относится к самому событию: ошибка, попытка, адреса
     */
    public function message(string $event, array $row, array $data = []): int
    {
        $projectId = isset($row['project_id']) && $row['project_id'] !== null ? (int) $row['project_id'] : 0;

        return $this->dispatch($event, $projectId, $data, $row);
    }

    /**
     * Событие про получателя, а не про письмо: отписался по ссылке из письма,
     * а из какого именно — на той стороне уже неизвестно.
     *
     * @param array<string, mixed> $data
     */
    public function recipient(string $event, int $projectId, array $data): int
    {
        return $this->dispatch($event, $projectId, $data);
    }

    /**
     * Проверка связи из панели: письма за ней нет, тело — тот же конверт.
     * Возвращает идентификатор поставленной доставки.
     *
     * @param array<string, mixed> $subscription
     * @param array<string, mixed> $project
     */
    public function ping(array $subscription, array $project): int
    {
        $uuid = Str::uuid();

        return $this->webhooks->enqueue([
            'uuid'            => $uuid,
            'project_id'      => (int) $project['id'],
            'subscription_id' => (int) $subscription['id'],
            'message_id'      => null,
            'url'             => (string) $subscription['url'],
            'event'           => Event::PING,
            'payload'         => Payload::envelope($uuid, Event::PING, $project, [
                'note' => 'Проверка связи из панели сервиса',
            ]),
        ]);
    }

    /**
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $row
     */
    private function dispatch(string $event, int $projectId, array $data, ?array $row = null): int
    {
        if ($projectId === 0) {
            return 0;
        }

        try {
            $subscriptions = $this->subscriptions->forEvent($projectId, $event);
            if ($subscriptions === []) {
                return 0;
            }

            $project = $this->projects->find($projectId);
            if ($project === null) {
                return 0;
            }

            $queued = 0;
            foreach ($subscriptions as $subscription) {
                $queued += $this->queue($subscription, $project, $event, $data, $row) ? 1 : 0;
            }

            return $queued;
        } catch (Throwable $e) {
            $this->logger->error('Не удалось поставить вебхук в очередь', [
                'event'   => $event,
                'uuid'    => $row['uuid'] ?? '',
                'message' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Одна доставка под одну подписку.
     *
     * @param array<string, mixed>      $subscription
     * @param array<string, mixed>      $project
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $row
     */
    private function queue(array $subscription, array $project, string $event, array $data, ?array $row): bool
    {
        $uuid      = Str::uuid();
        $messageId = $row === null ? null : (int) $row['id'];

        // Старая подписка знает только два события и плоское тело
        if ((int) $subscription['payload_version'] === Payload::V1) {
            $legacy = $row === null ? null : Payload::legacyEvent($event);
            if ($legacy === null) {
                return false;
            }

            $this->webhooks->enqueue([
                'uuid'            => $uuid,
                'project_id'      => (int) $project['id'],
                'subscription_id' => (int) $subscription['id'],
                'message_id'      => $messageId,
                'url'             => (string) $subscription['url'],
                'event'           => $legacy,
                'payload'         => Payload::legacy($legacy, $row, $data),
            ]);

            return true;
        }

        if ($row !== null) {
            $data = array_merge(['message' => Payload::message($row, $event)], $data);
        }

        $this->webhooks->enqueue([
            'uuid'            => $uuid,
            'project_id'      => (int) $project['id'],
            'subscription_id' => (int) $subscription['id'],
            'message_id'      => $messageId,
            'url'             => (string) $subscription['url'],
            'event'           => $event,
            'payload'         => Payload::envelope($uuid, $event, $project, $data),
        ]);

        return true;
    }
}
