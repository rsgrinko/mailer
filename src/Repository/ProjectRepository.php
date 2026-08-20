<?php

declare(strict_types=1);

namespace Mailer\Repository;

use Mailer\Domain\Scope;
use Mailer\Security\ApiKey;
use Mailer\Storage\Database;
use Mailer\Support\MailerException;
use Mailer\Support\Str;

/**
 * Проекты — те, кто пользуется нашим API. У каждого свой ключ, лимиты и вебхук.
 */
final class ProjectRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
    }

    /**
     * Область видимости: без неё видно всё (консоль, воркер, API — там своя проверка
     * по ключу), с ней — только проекты владельца.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(?Scope $scope = null): array
    {
        [$where, $params] = self::where($scope);

        return array_map([$this, 'hydrate'], $this->db->select('SELECT * FROM projects' . $where . ' ORDER BY name', $params));
    }

    /**
     * Сколько проектов включено — для метрик и проверки состояния.
     */
    public function countActive(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM projects WHERE active = 1');
    }

    /**
     * Страница списка проектов — панели незачем тянуть всё разом.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public function paginate(int $page = 1, int $perPage = 30, ?Scope $scope = null): array
    {
        [$where, $params] = self::where($scope);

        $result          = $this->db->page('SELECT * FROM projects' . $where . ' ORDER BY name', $params, $page, $perPage);
        $result['items'] = array_map([$this, 'hydrate'], $result['items']);

        return $result;
    }

    /**
     * Чужой проект для обычного пользователя просто не существует: панель покажет
     * «Проект не найден» вместо «нет доступа» — постороннему знать нечего.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id, ?Scope $scope = null): ?array
    {
        [$where, $params] = self::where($scope, ' AND ');

        $row = $this->db->selectOne(
            'SELECT * FROM projects WHERE id = :id' . $where,
            array_merge(['id' => $id], $params)
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Кусок WHERE от области видимости.
     *
     * @return array{0: string, 1: array<string, int>}
     */
    private static function where(?Scope $scope, string $prefix = ' WHERE '): array
    {
        $sql = $scope === null ? '' : $scope->sql();

        return [$sql === '' ? '' : $prefix . $sql, $scope === null ? [] : $scope->params()];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByName(string $name): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM projects WHERE name = :name', ['name' => $name]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Ищет проект по присланному API-ключу. Возвращает null, если ключ не подошёл.
     *
     * @return array<string, mixed>|null
     */
    public function findByApiKey(string $key): ?array
    {
        $prefix = ApiKey::prefix($key);
        if ($prefix === '') {
            return null;
        }

        $row = $this->db->selectOne(
            'SELECT * FROM projects WHERE api_key_prefix = :prefix',
            ['prefix' => $prefix]
        );

        if ($row === null || !ApiKey::matches($key, (string) $row['api_key_hash'])) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * Создаёт проект и сразу выдаёт ключ. Сам ключ показывается один раз —
     * в базе от него остаётся только хеш.
     *
     * @param array<string, mixed> $data
     * @return array{project: array<string, mixed>, key: string}
     */
    public function create(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new MailerException('У проекта должно быть имя');
        }
        if ($this->findByName($name) !== null) {
            throw new MailerException('Проект «' . $name . '» уже существует');
        }

        $apiKey = ApiKey::generate();

        $id = $this->db->insert('projects', [
            'name'               => $name,
            'description'        => $data['description'] ?? null,
            'api_key_prefix'     => $apiKey['prefix'],
            'api_key_hash'       => $apiKey['hash'],
            'transport_id'       => isset($data['transport_id']) && $data['transport_id'] !== '' ? (int) $data['transport_id'] : null,
            'default_from_email' => $data['default_from_email'] ?? null,
            'default_from_name'  => $data['default_from_name'] ?? null,
            'rate_limit_hour'    => (int) ($data['rate_limit_hour'] ?? 0),
            'rate_limit_day'     => (int) ($data['rate_limit_day'] ?? 0),
            'webhook_url'        => $data['webhook_url'] ?? null,
            'webhook_secret'     => ($data['webhook_secret'] ?? '') !== '' ? (string) $data['webhook_secret'] : Str::random(32),
            'active'             => (int) (bool) ($data['active'] ?? true),
            'unsubscribe'        => (int) (bool) ($data['unsubscribe'] ?? false),
            'owner_id'           => (int) ($data['owner_id'] ?? 0),
            'created_at'         => Database::now(),
            'updated_at'         => Database::now(),
        ]);

        return [
            'project' => (array) $this->find($id),
            'key'     => $apiKey['key'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $fields = ['updated_at' => Database::now()];

        foreach (['name', 'description', 'default_from_email', 'default_from_name', 'webhook_url', 'webhook_secret'] as $key) {
            if (array_key_exists($key, $data)) {
                $fields[$key] = $data[$key] === '' ? null : (string) $data[$key];
            }
        }

        if (array_key_exists('transport_id', $data)) {
            $fields['transport_id'] = $data['transport_id'] === '' || $data['transport_id'] === null
                ? null
                : (int) $data['transport_id'];
        }

        foreach (['rate_limit_hour', 'rate_limit_day'] as $key) {
            if (array_key_exists($key, $data)) {
                $fields[$key] = (int) $data[$key];
            }
        }

        foreach (['active', 'unsubscribe'] as $key) {
            if (array_key_exists($key, $data)) {
                $fields[$key] = (int) (bool) $data[$key];
            }
        }

        if (!array_key_exists('owner_id', $data)) {
            $this->db->update('projects', $fields, ['id' => $id]);

            return;
        }

        // Проект передали другому — его письма уезжают вместе с ним, иначе прежний
        // владелец продолжит видеть их в своём списке, а новый не увидит вовсе
        $fields['owner_id'] = (int) $data['owner_id'];

        $this->db->transaction(function () use ($id, $fields): void {
            $this->db->update('projects', $fields, ['id' => $id]);
            $this->db->update('messages', ['owner_id' => $fields['owner_id']], ['project_id' => $id]);
        });
    }

    /**
     * Выдаёт проекту новый ключ взамен старого.
     */
    public function regenerateKey(int $id): string
    {
        $apiKey = ApiKey::generate();

        $this->db->update('projects', [
            'api_key_prefix' => $apiKey['prefix'],
            'api_key_hash'   => $apiKey['hash'],
            'updated_at'     => Database::now(),
        ], ['id' => $id]);

        return $apiKey['key'];
    }

    public function delete(int $id): void
    {
        $this->db->delete('projects', ['id' => $id]);
    }

    /**
     * Находит проект по имени, а если его нет — создаёт.
     * Нужно для sendmail-shim и SMTP-релея: они работают без ключа.
     *
     * @return array<string, mixed>
     */
    public function findOrCreate(string $name, string $description = ''): array
    {
        $project = $this->findByName($name);
        if ($project !== null) {
            return $project;
        }

        $created = $this->create(['name' => $name, 'description' => $description]);

        return $created['project'];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $row['id']              = (int) $row['id'];
        $row['transport_id']    = $row['transport_id'] === null ? null : (int) $row['transport_id'];
        $row['rate_limit_hour'] = (int) $row['rate_limit_hour'];
        $row['rate_limit_day']  = (int) $row['rate_limit_day'];
        $row['active']          = (int) $row['active'];
        $row['unsubscribe']     = (int) ($row['unsubscribe'] ?? 0);
        $row['owner_id']        = (int) ($row['owner_id'] ?? 0);

        return $row;
    }
}
