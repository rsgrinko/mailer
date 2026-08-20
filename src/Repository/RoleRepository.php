<?php

declare(strict_types=1);

namespace Mailer\Repository;

use Mailer\Domain\Permission;
use Mailer\Storage\Database;
use Mailer\Support\MailerException;

/**
 * Роли панели: набор прав под именем. Пользователю выдаётся одна роль,
 * права он берёт из неё — отдельных галочек у пользователя нет.
 *
 * Роль администратора помечена is_system: её права не меняются и удалить её нельзя,
 * иначе панель рискует остаться без хозяина.
 */
final class RoleRepository
{
    /** Роль, которую панель считает административной */
    public const ADMIN = 'Администратор';

    /** Роль по умолчанию для новых пользователей */
    public const DEFAULT = 'Пользователь';

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return array_map([$this, 'hydrate'], $this->db->select('SELECT * FROM roles ORDER BY is_system DESC, name'));
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public function paginate(int $page = 1, int $perPage = 30): array
    {
        $result          = $this->db->page('SELECT * FROM roles ORDER BY is_system DESC, name', [], $page, $perPage);
        $result['items'] = array_map([$this, 'hydrate'], $result['items']);

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM roles WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByName(string $name): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM roles WHERE name = :name', ['name' => trim($name)]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Роль администратора. Нужна первому пользователю и консоли.
     *
     * @return array<string, mixed>|null
     */
    public function admin(): ?array
    {
        return $this->findByName(self::ADMIN);
    }

    /**
     * Сколько человек с этой ролью — по этому числу решаем, можно ли её удалить.
     */
    public function usersCount(int $id): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM users WHERE role_id = :id', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $data name, description, permissions
     */
    public function create(array $data): int
    {
        $name = self::checkName((string) ($data['name'] ?? ''));

        if ($this->findByName($name) !== null) {
            throw new MailerException('Роль «' . $name . '» уже есть');
        }

        return $this->db->insert('roles', [
            'name'        => $name,
            'description' => self::description($data),
            'permissions' => self::encode((array) ($data['permissions'] ?? [])),
            'is_system'   => 0,
            'created_at'  => Database::now(),
            'updated_at'  => Database::now(),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $role = $this->find($id);
        if ($role === null) {
            throw new MailerException('Роль не найдена');
        }

        $fields = ['updated_at' => Database::now()];

        if (isset($data['name'])) {
            $name = self::checkName((string) $data['name']);

            $existing = $this->findByName($name);
            if ($existing !== null && (int) $existing['id'] !== $id) {
                throw new MailerException('Роль «' . $name . '» уже есть');
            }

            $fields['name'] = $name;
        }

        if (array_key_exists('description', $data)) {
            $fields['description'] = self::description($data);
        }

        // У встроенной роли права не трогаем: это последний способ попасть в панель
        if (array_key_exists('permissions', $data) && (int) $role['is_system'] !== 1) {
            $fields['permissions'] = self::encode((array) $data['permissions']);
        }

        $this->db->update('roles', $fields, ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $role = $this->find($id);
        if ($role === null) {
            throw new MailerException('Роль не найдена');
        }

        if ((int) $role['is_system'] === 1) {
            throw new MailerException('Встроенную роль удалить нельзя');
        }

        $count = $this->usersCount($id);
        if ($count > 0) {
            throw new MailerException('Роль назначена пользователям (' . $count . ') — сначала переведите их на другую');
        }

        $this->db->delete('roles', ['id' => $id]);
    }

    /**
     * @param array<int, mixed> $permissions
     */
    private static function encode(array $permissions): string
    {
        return (string) json_encode(Permission::filter($permissions));
    }

    private static function checkName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw new MailerException('Не указано название роли');
        }

        if (mb_strlen($name) > 64) {
            throw new MailerException('Слишком длинное название роли: не больше 64 символов');
        }

        return $name;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function description(array $data): ?string
    {
        $description = trim((string) ($data['description'] ?? ''));

        return $description === '' ? null : $description;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $permissions = json_decode((string) ($row['permissions'] ?? '[]'), true);

        $row['permissions'] = Permission::filter(is_array($permissions) ? $permissions : []);

        return $row;
    }
}
