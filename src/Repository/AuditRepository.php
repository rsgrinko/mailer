<?php

declare(strict_types=1);

namespace Mailer\Repository;

use Mailer\Storage\Database;

/**
 * Журнал действий в панели: кто, что и над какой записью сделал.
 *
 * Пишется только то, что меняет данные или состояние сервиса. Просмотр страниц
 * сюда не попадает — журнал должен читаться глазами, а не грепом.
 */
final class AuditRepository
{
    // Что сделали
    public const CREATED  = 'created';
    public const UPDATED  = 'updated';
    public const DELETED  = 'deleted';
    public const ACTION   = 'action';
    public const LOGIN    = 'login';
    public const LOGOUT   = 'logout';

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
    }

    /**
     * Записывает действие. Возвращает id записи.
     */
    public function log(
        int $userId,
        string $login,
        string $action,
        string $entity,
        ?int $entityId = null,
        string $summary = '',
        string $ip = ''
    ): int {
        return $this->db->insert('audit_log', [
            'user_id'    => $userId,
            'user_login' => $login === '' ? null : $login,
            'action'     => $action,
            'entity'     => $entity,
            'entity_id'  => $entityId,
            'summary'    => $summary === '' ? null : $summary,
            'ip'         => $ip === '' ? null : $ip,
            'created_at' => Database::now(),
        ]);
    }

    /**
     * Список для панели.
     *
     * @param array<string, mixed> $filters user_id, entity, action, search
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 30): array
    {
        $conditions = [];
        $params     = [];

        if (!empty($filters['user_id'])) {
            $conditions[]      = 'user_id = :user_id';
            $params['user_id'] = (int) $filters['user_id'];
        }
        if (!empty($filters['entity'])) {
            $conditions[]     = 'entity = :entity';
            $params['entity'] = (string) $filters['entity'];
        }
        if (!empty($filters['action'])) {
            $conditions[]     = 'action = :action';
            $params['action'] = (string) $filters['action'];
        }
        if (!empty($filters['search'])) {
            $conditions[]            = '(summary LIKE :search_summary OR user_login LIKE :search_login)';
            $params['search_summary'] = '%' . $filters['search'] . '%';
            $params['search_login']   = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['from'])) {
            $conditions[]   = 'created_at >= :from';
            $params['from'] = (string) $filters['from'] . ' 00:00:00';
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        return $this->db->page(
            'SELECT * FROM audit_log' . $where . ' ORDER BY id DESC',
            $params,
            $page,
            $perPage
        );
    }

    /**
     * Последние действия над одной записью — для карточки раздела.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forEntity(string $entity, int $entityId, int $limit = 10): array
    {
        return $this->db->select(
            'SELECT * FROM audit_log WHERE entity = :entity AND entity_id = :id ORDER BY id DESC LIMIT ' . max(1, $limit),
            ['entity' => $entity, 'id' => $entityId]
        );
    }

    /**
     * Разделы, которые уже встречались в журнале — для фильтра.
     *
     * @return array<int, string>
     */
    public function entities(): array
    {
        $rows = $this->db->select('SELECT DISTINCT entity FROM audit_log ORDER BY entity');

        return array_map(static fn (array $row): string => (string) $row['entity'], $rows);
    }

    /**
     * Кто уже что-то делал — для фильтра по пользователю.
     *
     * @return array<int, array{user_id: int, user_login: string}>
     */
    public function users(): array
    {
        $rows = $this->db->select(
            'SELECT user_id, MAX(user_login) AS user_login FROM audit_log GROUP BY user_id ORDER BY user_login'
        );

        return array_map(
            static fn (array $row): array => [
                'user_id'    => (int) $row['user_id'],
                'user_login' => (string) ($row['user_login'] ?? ''),
            ],
            $rows
        );
    }

    /**
     * Чистка старых записей — журнал растёт без остановки.
     */
    public function purge(int $olderThanDays): int
    {
        $days = max(1, $olderThanDays);

        return $this->db->execute(
            'DELETE FROM audit_log WHERE created_at < :before',
            ['before' => date('Y-m-d H:i:s', strtotime('-' . $days . ' days'))]
        );
    }
}
