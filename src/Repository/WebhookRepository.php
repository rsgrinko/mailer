<?php

declare(strict_types=1);

namespace Mailer\Repository;

use Mailer\Storage\Database;

/**
 * Очередь вебхуков: сообщения проекту о том, что случилось с письмом.
 */
final class WebhookRepository
{
    public const QUEUED    = 'queued';
    public const DELIVERED = 'delivered';
    public const FAILED    = 'failed';

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
    }

    /**
     * Ставит вебхук в очередь.
     *
     * @param array<string, mixed> $payload
     */
    public function enqueue(int $projectId, ?int $messageId, string $url, string $event, array $payload): int
    {
        return $this->db->insert('webhook_deliveries', [
            'message_id'   => $messageId,
            'project_id'   => $projectId,
            'url'          => $url,
            'event'        => $event,
            'payload'      => (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status'       => self::QUEUED,
            'attempts'     => 0,
            'available_at' => Database::now(),
            'created_at'   => Database::now(),
            'updated_at'   => Database::now(),
        ]);
    }

    /**
     * Вебхуки, которые пора отправить.
     *
     * @return array<int, array<string, mixed>>
     */
    public function due(int $limit = 20): array
    {
        return $this->db->select(
            'SELECT * FROM webhook_deliveries
             WHERE status = :status AND (available_at IS NULL OR available_at <= :now)
             ORDER BY id LIMIT ' . max(1, $limit),
            ['status' => self::QUEUED, 'now' => Database::now()]
        );
    }

    public function markDelivered(int $id, int $responseCode): void
    {
        $this->db->update('webhook_deliveries', [
            'status'        => self::DELIVERED,
            'response_code' => $responseCode,
            'last_error'    => null,
            'delivered_at'  => Database::now(),
            'updated_at'    => Database::now(),
        ], ['id' => $id]);
    }

    /**
     * Отмечает неудачу: либо повтор позже, либо окончательный отказ.
     */
    public function markFailed(int $id, int $attempts, string $error, ?int $responseCode, ?string $retryAt): void
    {
        $this->db->update('webhook_deliveries', [
            'status'        => $retryAt === null ? self::FAILED : self::QUEUED,
            'attempts'      => $attempts,
            'response_code' => $responseCode,
            'last_error'    => $error,
            'available_at'  => $retryAt,
            'updated_at'    => Database::now(),
        ], ['id' => $id]);
    }

    /**
     * Список для панели.
     *
     * @param array<string, mixed> $filters
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 30): array
    {
        $conditions = [];
        $params     = [];

        if (!empty($filters['status'])) {
            $conditions[]     = 'status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['project_id'])) {
            $conditions[]         = 'project_id = :project_id';
            $params['project_id'] = (int) $filters['project_id'];
        }
        if (!empty($filters['message_id'])) {
            $conditions[]         = 'message_id = :message_id';
            $params['message_id'] = (int) $filters['message_id'];
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        $total   = (int) $this->db->value('SELECT COUNT(*) FROM webhook_deliveries ' . $where, $params);
        $perPage = max(1, min(200, $perPage));
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($page, $pages));

        $items = $this->db->select(
            'SELECT * FROM webhook_deliveries ' . $where . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $params
        );

        return ['items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM webhook_deliveries WHERE id = :id', ['id' => $id]);
    }

    /**
     * Повторить вручную из панели.
     */
    public function retry(int $id): void
    {
        $this->db->update('webhook_deliveries', [
            'status'       => self::QUEUED,
            'available_at' => Database::now(),
            'updated_at'   => Database::now(),
        ], ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->delete('webhook_deliveries', ['id' => $id]);
    }

    /**
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        $result = [self::QUEUED => 0, self::DELIVERED => 0, self::FAILED => 0];

        foreach ($this->db->select('SELECT status, COUNT(*) AS total FROM webhook_deliveries GROUP BY status') as $row) {
            $result[(string) $row['status']] = (int) $row['total'];
        }

        return $result;
    }
}
