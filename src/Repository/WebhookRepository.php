<?php

declare(strict_types=1);

namespace Mailer\Repository;

use Mailer\Domain\Scope;
use Mailer\Storage\Database;

/**
 * Очередь вебхуков: что и кому мы отправляли, что ответили и когда повторим.
 *
 * Доставка хранит не только тело запроса, но и заголовки с ответом сервера —
 * иначе отладить чужой приёмник нечем: код 500 без тела не говорит ничего.
 */
final class WebhookRepository
{
    public const QUEUED    = 'queued';
    public const DELIVERED = 'delivered';
    public const FAILED    = 'failed';

    /** Сколько байт ответа храним: нужен смысл ошибки, а не вся страница */
    private const RESPONSE_LIMIT = 8192;

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
    }

    /**
     * Ставит доставку в очередь. Собирает её Webhook\Dispatcher: он знает и про
     * подписки, и про формат тела.
     *
     * @param array<string, mixed> $data
     */
    public function enqueue(array $data): int
    {
        $payload = $data['payload'] ?? [];

        return $this->db->insert('webhook_deliveries', [
            'uuid'            => (string) ($data['uuid'] ?? ''),
            'message_id'      => isset($data['message_id']) && $data['message_id'] !== null ? (int) $data['message_id'] : null,
            'project_id'      => isset($data['project_id']) && $data['project_id'] !== null ? (int) $data['project_id'] : null,
            'subscription_id' => isset($data['subscription_id']) && $data['subscription_id'] !== null ? (int) $data['subscription_id'] : null,
            'url'             => (string) $data['url'],
            'event'           => (string) $data['event'],
            'payload'         => is_string($payload)
                ? $payload
                : (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status'          => self::QUEUED,
            'attempts'        => 0,
            'available_at'    => Database::now(),
            'created_at'      => Database::now(),
            'updated_at'      => Database::now(),
        ]);
    }

    /**
     * Вебхуки, которым пора уходить.
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

    /**
     * Доставлено. Ответ сервера сохраняем целиком — по нему потом и разбираются.
     *
     * @param array{code: int, headers: string, body: string, duration: int, request_headers: string} $result
     */
    public function markDelivered(int $id, int $attempts, array $result): void
    {
        $this->db->update('webhook_deliveries', [
            'status'           => self::DELIVERED,
            'attempts'         => $attempts,
            'response_code'    => $result['code'],
            'request_headers'  => $result['request_headers'],
            'response_headers' => $result['headers'],
            'response_body'    => self::trim($result['body']),
            'duration_ms'      => $result['duration'],
            'last_error'       => null,
            'delivered_at'     => Database::now(),
            'updated_at'       => Database::now(),
        ], ['id' => $id]);
    }

    /**
     * Неудача: либо повтор позже, либо окончательный отказ.
     *
     * @param array{code: int, headers: string, body: string, duration: int, request_headers: string} $result
     */
    public function markFailed(int $id, int $attempts, string $error, array $result, ?string $retryAt): void
    {
        $this->db->update('webhook_deliveries', [
            'status'           => $retryAt === null ? self::FAILED : self::QUEUED,
            'attempts'         => $attempts,
            'response_code'    => $result['code'] > 0 ? $result['code'] : null,
            'request_headers'  => $result['request_headers'],
            'response_headers' => $result['headers'],
            'response_body'    => self::trim($result['body']),
            'duration_ms'      => $result['duration'],
            'last_error'       => $error,
            'available_at'     => $retryAt,
            'updated_at'       => Database::now(),
        ], ['id' => $id]);
    }

    /**
     * Список для панели.
     *
     * @param array<string, mixed> $filters
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 30, ?Scope $scope = null): array
    {
        [$conditions, $params] = self::scoped($scope);

        if (!empty($filters['status'])) {
            $conditions[]     = 'status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['event'])) {
            $conditions[]    = 'event = :event';
            $params['event'] = (string) $filters['event'];
        }
        if (!empty($filters['project_id'])) {
            $conditions[]         = 'project_id = :project_id';
            $params['project_id'] = (int) $filters['project_id'];
        }
        if (!empty($filters['subscription_id'])) {
            $conditions[]              = 'subscription_id = :subscription_id';
            $params['subscription_id'] = (int) $filters['subscription_id'];
        }
        if (!empty($filters['message_id'])) {
            $conditions[]         = 'message_id = :message_id';
            $params['message_id'] = (int) $filters['message_id'];
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        return $this->db->page(
            'SELECT * FROM webhook_deliveries' . $where . ' ORDER BY id DESC',
            $params,
            $page,
            $perPage
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id, ?Scope $scope = null): ?array
    {
        [$conditions, $params] = self::scoped($scope);

        $where = $conditions === [] ? '' : ' AND ' . implode(' AND ', $conditions);

        return $this->db->selectOne(
            'SELECT * FROM webhook_deliveries WHERE id = :id' . $where,
            array_merge(['id' => $id], $params)
        );
    }

    /**
     * Своя ли это доставка, решает проект: вебхуки принадлежат тому, чей проект.
     *
     * @return array{0: array<int, string>, 1: array<string, int>}
     */
    private static function scoped(?Scope $scope): array
    {
        if ($scope === null || $scope->isAll()) {
            return [[], []];
        }

        return [
            ['project_id IN (SELECT id FROM projects WHERE ' . $scope->sql() . ')'],
            $scope->params(),
        ];
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
     * Чистка старых доставок: тело запроса и ответ занимают место, а смысла в
     * прошлогодней доставке нет. Возвращает, сколько удалено.
     */
    public function purge(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        return $this->db->execute(
            'DELETE FROM webhook_deliveries WHERE status <> :status AND created_at < :border',
            ['status' => self::QUEUED, 'border' => Database::at(-1 * $days * 86400)]
        );
    }

    /**
     * @return array<string, int>
     */
    public function countByStatus(?Scope $scope = null): array
    {
        $result = [self::QUEUED => 0, self::DELIVERED => 0, self::FAILED => 0];

        [$conditions, $params] = self::scoped($scope);

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        foreach ($this->db->select('SELECT status, COUNT(*) AS total FROM webhook_deliveries' . $where . ' GROUP BY status', $params) as $row) {
            $result[(string) $row['status']] = (int) $row['total'];
        }

        return $result;
    }

    /**
     * Ответ чужого сервера может быть страницей на сотни килобайт — храним начало.
     */
    private static function trim(string $body): ?string
    {
        if ($body === '') {
            return null;
        }

        return strlen($body) > self::RESPONSE_LIMIT
            ? substr($body, 0, self::RESPONSE_LIMIT) . "\n… ответ обрезан"
            : $body;
    }
}
