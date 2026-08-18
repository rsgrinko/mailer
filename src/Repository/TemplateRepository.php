<?php

declare(strict_types=1);

namespace Mailer\Repository;

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
    public function all(): array
    {
        return $this->db->select('SELECT * FROM templates ORDER BY name');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM templates WHERE id = :id', ['id' => $id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByName(string $name): ?array
    {
        return $this->db->selectOne('SELECT * FROM templates WHERE name = :name', ['name' => $name]);
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

        $this->db->update('templates', $fields, ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->delete('templates', ['id' => $id]);
    }
}
