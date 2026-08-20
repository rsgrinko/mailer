<?php

declare(strict_types=1);

namespace Mailer\Repository;

use Mailer\Domain\Scope;
use Mailer\Storage\Database;

/**
 * Стоп-лист: адреса, которым сервис больше не пишет.
 *
 * Запись без проекта закрывает адрес для всех — так попадают сюда отказы почтовых
 * серверов: несуществующего ящика нет ни для одного проекта. Запись с проектом
 * ограничивает запрет им одним: отписка от рассылки одного приложения не должна
 * отменять письма другого.
 */
final class SuppressionRepository
{
    // Почему адрес закрыт
    public const BOUNCE      = 'bounce';
    public const COMPLAINT   = 'complaint';
    public const UNSUBSCRIBE = 'unsubscribe';
    public const MANUAL      = 'manual';

    public const REASONS = [self::BOUNCE, self::COMPLAINT, self::UNSUBSCRIBE, self::MANUAL];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
    }

    /**
     * Закрывает адрес. Тот же адрес с тем же охватом не дублируется — запись обновляется.
     *
     * @param array<string, mixed> $options project_id, owner_id, message_id, note, expires_at
     */
    public function block(string $email, string $reason = self::MANUAL, string $source = 'ui', array $options = []): int
    {
        $email     = self::normalize($email);
        $projectId = isset($options['project_id']) && (int) $options['project_id'] > 0
            ? (int) $options['project_id']
            : null;

        $existing = $this->findRecord($email, $projectId);

        $data = [
            'reason'     => in_array($reason, self::REASONS, true) ? $reason : self::MANUAL,
            'source'     => $source,
            'message_id' => isset($options['message_id']) && (int) $options['message_id'] > 0
                ? (int) $options['message_id']
                : null,
            'note'       => isset($options['note']) && (string) $options['note'] !== '' ? (string) $options['note'] : null,
            'expires_at' => $options['expires_at'] ?? null,
            'updated_at' => Database::now(),
        ];

        if ($existing !== null) {
            $this->db->update('suppressions', $data, ['id' => (int) $existing['id']]);

            return (int) $existing['id'];
        }

        return $this->db->insert('suppressions', array_merge($data, [
            'email'      => $email,
            'project_id' => $projectId,
            'owner_id'   => (int) ($options['owner_id'] ?? 0),
            'created_at' => Database::now(),
        ]));
    }

    /**
     * Какие из адресов закрыты для этого проекта.
     *
     * Возвращает массив «адрес => запись». Просроченные записи не считаются: мягкий
     * отказ закрывает адрес на срок, а не навсегда.
     *
     * @param array<int, string> $emails
     * @return array<string, array<string, mixed>>
     */
    public function blocked(array $emails, ?int $projectId = null): array
    {
        $emails = array_values(array_unique(array_filter(array_map(
            static fn (string $email): string => self::normalize($email),
            $emails
        ))));

        if ($emails === []) {
            return [];
        }

        // Каждому адресу — свой именованный параметр: MySQL работает без эмуляции
        // подготовленных выражений и повтор имени не принимает
        $names  = [];
        $params = ['now' => Database::now()];
        foreach ($emails as $index => $email) {
            $name          = 'email_' . $index;
            $names[]       = ':' . $name;
            $params[$name] = $email;
        }

        $where = 'email IN (' . implode(', ', $names) . ')'
            . ' AND (expires_at IS NULL OR expires_at > :now)';

        if ($projectId !== null && $projectId > 0) {
            $where               .= ' AND (project_id IS NULL OR project_id = :project_id)';
            $params['project_id'] = $projectId;
        } else {
            $where .= ' AND project_id IS NULL';
        }

        $result = [];
        foreach ($this->db->select('SELECT * FROM suppressions WHERE ' . $where, $params) as $row) {
            $result[(string) $row['email']] = $row;
        }

        return $result;
    }

    /**
     * Закрыт ли один адрес.
     */
    public function isBlocked(string $email, ?int $projectId = null): bool
    {
        return $this->blocked([$email], $projectId) !== [];
    }

    /**
     * Запись по адресу и охвату — на неё же ложится повторная блокировка.
     *
     * @return array<string, mixed>|null
     */
    private function findRecord(string $email, ?int $projectId): ?array
    {
        if ($projectId === null) {
            return $this->db->selectOne(
                'SELECT * FROM suppressions WHERE email = :email AND project_id IS NULL',
                ['email' => $email]
            );
        }

        return $this->db->selectOne(
            'SELECT * FROM suppressions WHERE email = :email AND project_id = :project_id',
            ['email' => $email, 'project_id' => $projectId]
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
            'SELECT * FROM suppressions WHERE id = :id' . $where,
            array_merge(['id' => $id], $params)
        );
    }

    /**
     * Список для панели и API.
     *
     * @param array<string, mixed> $filters reason, project_id, for_project, search
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 30, ?Scope $scope = null): array
    {
        [$conditions, $params] = self::scoped($scope);

        if (!empty($filters['reason'])) {
            $conditions[]     = 'reason = :reason';
            $params['reason'] = (string) $filters['reason'];
        }
        if (!empty($filters['project_id'])) {
            $conditions[]         = 'project_id = :project_id';
            $params['project_id'] = (int) $filters['project_id'];
        }
        // Что видит проект по своему ключу: свои записи и общие
        if (!empty($filters['for_project'])) {
            $conditions[]          = '(project_id IS NULL OR project_id = :for_project)';
            $params['for_project'] = (int) $filters['for_project'];
        }
        if (!empty($filters['search'])) {
            $conditions[]     = 'email LIKE :search';
            $params['search'] = '%' . self::normalize((string) $filters['search']) . '%';
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        return $this->db->page(
            'SELECT * FROM suppressions' . $where . ' ORDER BY id DESC',
            $params,
            $page,
            $perPage
        );
    }

    /**
     * Сколько адресов закрыто по каждой причине.
     *
     * @return array<string, int>
     */
    public function countByReason(?Scope $scope = null): array
    {
        $result = array_fill_keys(self::REASONS, 0);

        [$conditions, $params] = self::scoped($scope);

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        foreach ($this->db->select('SELECT reason, COUNT(*) AS total FROM suppressions' . $where . ' GROUP BY reason', $params) as $row) {
            $result[(string) $row['reason']] = (int) $row['total'];
        }

        return $result;
    }

    /**
     * Открывает адрес обратно.
     */
    public function delete(int $id): void
    {
        $this->db->delete('suppressions', ['id' => $id]);
    }

    /**
     * Открывает адрес по самому адресу — так это делает API.
     *
     * С проектом снимаются только его собственные записи: закрытый для всех адрес
     * (обычно это отказ почтового сервера) клиент по своему ключу открыть не может —
     * иначе один проект развяжет руки остальным.
     *
     * @return int сколько записей снято
     */
    public function unblock(string $email, ?int $projectId = null): int
    {
        $email = self::normalize($email);

        if ($projectId !== null && $projectId > 0) {
            return $this->db->execute(
                'DELETE FROM suppressions WHERE email = :email AND project_id = :project_id',
                ['email' => $email, 'project_id' => $projectId]
            );
        }

        return $this->db->execute('DELETE FROM suppressions WHERE email = :email', ['email' => $email]);
    }

    /**
     * Область видимости: свои записи и общие (те, что без владельца, завёл сервис сам).
     *
     * @return array{0: array<int, string>, 1: array<string, int>}
     */
    private static function scoped(?Scope $scope): array
    {
        if ($scope === null || $scope->isAll()) {
            return [[], []];
        }

        return [[$scope->sql()], $scope->params()];
    }

    /**
     * Похож ли ответ сервера на отказ по самому ящику.
     *
     * Блокировать любой 5xx нельзя: «relay denied» и отказ по политике — это про наш
     * сервер, а не про адрес получателя. Ориентируемся на расширенный код: 5.1.x —
     * адреса или домена нет, 5.2.1 — ящик отключён. Остальное оставляем человеку.
     */
    public static function isHardBounce(string $answer): bool
    {
        return preg_match('/\b5\.(1\.\d{1,3}|2\.1)\b/', $answer) === 1;
    }

    /**
     * Адреса сравниваем в нижнем регистре и без пробелов по краям — иначе один и тот же
     * ящик попадёт в список дважды и проверка его не найдёт.
     */
    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
