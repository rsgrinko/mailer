<?php

declare(strict_types=1);

namespace Mailer\Repository;

use Mailer\Domain\Scope;
use Mailer\Storage\Database;
use Mailer\Support\MailerException;

/**
 * Шаблоны писем. В теме и телах можно использовать переменные вида {{ name }}.
 */
final class TemplateRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(?Scope $scope = null): array
    {
        [$where, $params] = self::where($scope);

        return $this->db->select('SELECT * FROM templates' . $where . ' ORDER BY name', $params);
    }

    /**
     * Страница списка шаблонов.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public function paginate(int $page = 1, int $perPage = 30, ?Scope $scope = null): array
    {
        [$where, $params] = self::where($scope);

        return $this->db->page('SELECT * FROM templates' . $where . ' ORDER BY name', $params, $page, $perPage);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id, ?Scope $scope = null): ?array
    {
        [$where, $params] = self::where($scope, ' AND ');

        return $this->db->selectOne(
            'SELECT * FROM templates WHERE id = :id' . $where,
            array_merge(['id' => $id], $params)
        );
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
     * Шаблон по имени. Область видимости нужна там, где имя пришло снаружи:
     * письмо по API ссылается на шаблон строкой, и чужой подставляться не должен.
     *
     * @return array<string, mixed>|null
     */
    public function findByName(string $name, ?Scope $scope = null): ?array
    {
        [$where, $params] = self::where($scope, ' AND ');

        return $this->db->selectOne(
            'SELECT * FROM templates WHERE name = :name' . $where,
            array_merge(['name' => $name], $params)
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new MailerException('У шаблона должно быть имя');
        }
        if ($this->findByName($name) !== null) {
            throw new MailerException('Шаблон «' . $name . '» уже существует');
        }

        return $this->db->insert('templates', [
            'name'        => $name,
            'description' => $data['description'] ?? null,
            'subject'     => $data['subject'] ?? null,
            'html'        => $data['html'] ?? null,
            'text'        => $data['text'] ?? null,
            'owner_id'    => (int) ($data['owner_id'] ?? 0),
            'created_at'  => Database::now(),
            'updated_at'  => Database::now(),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $fields = ['updated_at' => Database::now()];

        foreach (['name', 'description', 'subject', 'html', 'text'] as $key) {
            if (array_key_exists($key, $data)) {
                $fields[$key] = $data[$key] === '' ? null : (string) $data[$key];
            }
        }

        if (array_key_exists('owner_id', $data)) {
            $fields['owner_id'] = (int) $data['owner_id'];
        }

        $this->db->update('templates', $fields, ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->delete('templates', ['id' => $id]);
    }
}
