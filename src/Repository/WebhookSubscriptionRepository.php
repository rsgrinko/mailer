<?php

declare(strict_types=1);

namespace Mailer\Repository;

use Mailer\Domain\Scope;
use Mailer\Security\Crypto;
use Mailer\Storage\Database;
use Mailer\Support\MailerException;
use Mailer\Support\Str;
use Mailer\Webhook\Event;
use Mailer\Webhook\Payload;

/**
 * Подписки проекта на события: куда слать, с каким секретом и о чём именно.
 * Раньше адрес был один на проект и события к нему прилагались все сразу.
 *
 * Пустой список событий означает «все» — так подписка не остаётся глухой к тому,
 * что появится позже.
 */
final class WebhookSubscriptionRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
    }

    /**
     * Подписки, которым положено это событие.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forEvent(int $projectId, string $event): array
    {
        $rows = $this->db->select(
            'SELECT * FROM project_webhooks WHERE project_id = :project_id AND active = 1 ORDER BY id',
            ['project_id' => $projectId]
        );

        $matched = [];
        foreach ($rows as $row) {
            $row = $this->hydrate($row);

            if ($row['events'] === [] || in_array($event, $row['events'], true)) {
                $matched[] = $row;
            }
        }

        return $matched;
    }

    /**
     * Все подписки проекта — для его карточки.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forProject(int $projectId): array
    {
        return array_map([$this, 'hydrate'], $this->db->select(
            'SELECT * FROM project_webhooks WHERE project_id = :project_id ORDER BY id',
            ['project_id' => $projectId]
        ));
    }

    /**
     * Страница списка со своей областью видимости.
     *
     * @param array<string, mixed> $filters
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 30, ?Scope $scope = null): array
    {
        [$conditions, $params] = self::scoped($scope);

        if (!empty($filters['project_id'])) {
            $conditions[]         = 'project_id = :project_id';
            $params['project_id'] = (int) $filters['project_id'];
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        $result          = $this->db->page('SELECT * FROM project_webhooks' . $where . ' ORDER BY id DESC', $params, $page, $perPage);
        $result['items'] = array_map([$this, 'hydrate'], $result['items']);

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id, ?Scope $scope = null): ?array
    {
        [$conditions, $params] = self::scoped($scope);

        $where = $conditions === [] ? '' : ' AND ' . implode(' AND ', $conditions);

        $row = $this->db->selectOne(
            'SELECT * FROM project_webhooks WHERE id = :id' . $where,
            array_merge(['id' => $id], $params)
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $url = trim((string) ($data['url'] ?? ''));
        if ($url === '') {
            throw new MailerException('У подписки должен быть адрес');
        }

        $secret = trim((string) ($data['secret'] ?? ''));

        return $this->db->insert('project_webhooks', [
            'project_id'      => (int) ($data['project_id'] ?? 0),
            'name'            => trim((string) ($data['name'] ?? '')) ?: null,
            'url'             => $url,
            'secret'          => Crypto::encrypt($secret !== '' ? $secret : Str::random(32)),
            'events'          => self::encodeEvents($data['events'] ?? []),
            'payload_version' => (int) ($data['payload_version'] ?? Payload::V2),
            'active'          => (int) (bool) ($data['active'] ?? true),
            'failures'        => 0,
            'created_at'      => Database::now(),
            'updated_at'      => Database::now(),
        ]);
    }

    /**
     * Пустой секрет означает «оставить прежний»: в форме он не показывается,
     * и пустое поле не должно стирать рабочую подпись.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $fields = ['updated_at' => Database::now()];

        if (array_key_exists('name', $data)) {
            $fields['name'] = trim((string) $data['name']) ?: null;
        }

        if (array_key_exists('url', $data)) {
            $url = trim((string) $data['url']);
            if ($url === '') {
                throw new MailerException('У подписки должен быть адрес');
            }

            $fields['url'] = $url;
        }

        if (array_key_exists('secret', $data) && trim((string) $data['secret']) !== '') {
            $fields['secret'] = Crypto::encrypt(trim((string) $data['secret']));
        }

        if (array_key_exists('events', $data)) {
            $fields['events'] = self::encodeEvents($data['events']);
        }

        if (array_key_exists('payload_version', $data)) {
            $fields['payload_version'] = (int) $data['payload_version'] === Payload::V1 ? Payload::V1 : Payload::V2;
        }

        if (array_key_exists('active', $data)) {
            $fields['active'] = (int) (bool) $data['active'];
        }

        if (array_key_exists('project_id', $data)) {
            $fields['project_id'] = (int) $data['project_id'];
        }

        $this->db->update('project_webhooks', $fields, ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->delete('project_webhooks', ['id' => $id]);
    }

    /**
     * Отметка о последней доставке: что ответили и сколько неудач подряд.
     * По ней в списке видно молчащую подписку, не открывая доставки.
     */
    public function noteDelivery(int $id, bool $ok, ?string $error): void
    {
        $fields = [
            'last_status'      => $ok ? 'delivered' : 'failed',
            'last_error'       => $ok ? null : $error,
            'last_delivery_at' => Database::now(),
            'updated_at'       => Database::now(),
        ];

        if ($ok) {
            $fields['failures'] = 0;

            $this->db->update('project_webhooks', $fields, ['id' => $id]);

            return;
        }

        $this->db->execute(
            'UPDATE project_webhooks SET failures = failures + 1, last_status = :last_status,
             last_error = :last_error, last_delivery_at = :last_delivery_at, updated_at = :updated_at
             WHERE id = :id',
            array_merge($fields, ['id' => $id])
        );
    }

    /**
     * Секрет подписи в открытом виде. Нужен только отправителю вебхука.
     *
     * @param array<string, mixed> $row
     */
    public static function secret(array $row): string
    {
        return Crypto::decrypt((string) ($row['secret'] ?? ''));
    }

    /**
     * Сколько подписок включено — для обзора и метрик.
     */
    public function countActive(?Scope $scope = null): int
    {
        [$conditions, $params] = self::scoped($scope);

        $conditions[] = 'active = 1';

        return (int) $this->db->value(
            'SELECT COUNT(*) FROM project_webhooks WHERE ' . implode(' AND ', $conditions),
            $params
        );
    }

    /**
     * Своя ли подписка, решает проект — она принадлежит тому, чей проект.
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

    private static function encodeEvents(mixed $events): ?string
    {
        $filtered = Event::filter(is_array($events) ? $events : []);

        // Пустой список — подписка на всё: так новое событие не пройдёт мимо неё
        return $filtered === [] ? null : (string) json_encode($filtered, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $events = $row['events'] === null || $row['events'] === '' ? [] : json_decode((string) $row['events'], true);

        $row['id']              = (int) $row['id'];
        $row['project_id']      = (int) $row['project_id'];
        $row['events']          = is_array($events) ? array_values(array_map('strval', $events)) : [];
        $row['payload_version'] = (int) $row['payload_version'];
        $row['active']          = (int) $row['active'];
        $row['failures']        = (int) $row['failures'];

        return $row;
    }
}
