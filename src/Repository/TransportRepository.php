<?php

declare(strict_types=1);

namespace Mailer\Repository;

use Mailer\Security\Crypto;
use Mailer\Storage\Database;
use Mailer\Support\MailerException;

/**
 * Транспорты (способы отправки) в базе. Пароли внутри настроек шифруются.
 */
final class TransportRepository
{
    /** Типы транспортов, которые умеет сервис */
    public const TYPES = ['smtp', 'sendmail', 'log', 'null', 'failover', 'roundrobin'];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(bool $onlyActive = false): array
    {
        $sql = 'SELECT * FROM transports';
        if ($onlyActive) {
            $sql .= ' WHERE active = 1';
        }
        $sql .= ' ORDER BY priority, id';

        return array_map([$this, 'hydrate'], $this->db->select($sql));
    }

    /**
     * Страница списка транспортов.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public function paginate(int $page = 1, int $perPage = 30): array
    {
        $result          = $this->db->page('SELECT * FROM transports ORDER BY priority, id', [], $page, $perPage);
        $result['items'] = array_map([$this, 'hydrate'], $result['items']);

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM transports WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByName(string $name): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM transports WHERE name = :name', ['name' => $name]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Транспорт по умолчанию: помеченный флагом, иначе первый активный.
     *
     * @return array<string, mixed>|null
     */
    public function default(): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM transports WHERE is_default = 1 AND active = 1 ORDER BY priority, id');

        if ($row === null) {
            $row = $this->db->selectOne('SELECT * FROM transports WHERE active = 1 ORDER BY priority, id');
        }

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Создаёт транспорт. В $data ожидаются name, type, settings и необязательные поля.
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        $type = (string) ($data['type'] ?? '');

        if ($name === '') {
            throw new MailerException('У транспорта должно быть имя');
        }
        if (!in_array($type, self::TYPES, true)) {
            throw new MailerException('Неизвестный тип транспорта: ' . $type);
        }
        if ($this->findByName($name) !== null) {
            throw new MailerException('Транспорт с именем «' . $name . '» уже есть');
        }

        $id = $this->db->insert('transports', [
            'name'        => $name,
            'type'        => $type,
            'settings'    => $this->encodeSettings((array) ($data['settings'] ?? [])),
            'from_email'  => $data['from_email'] ?? null,
            'from_name'   => $data['from_name'] ?? null,
            'priority'    => (int) ($data['priority'] ?? 100),
            'daily_limit' => (int) ($data['daily_limit'] ?? 0),
            'is_default'  => (int) (bool) ($data['is_default'] ?? false),
            'active'      => (int) (bool) ($data['active'] ?? true),
            'created_at'  => Database::now(),
            'updated_at'  => Database::now(),
        ]);

        if ((bool) ($data['is_default'] ?? false)) {
            $this->setDefault($id);
        }

        return $id;
    }

    /**
     * Обновляет транспорт. Пустой пароль в настройках означает «оставить прежний».
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $current = $this->find($id);
        if ($current === null) {
            throw new MailerException('Транспорт не найден: id=' . $id);
        }

        $fields = ['updated_at' => Database::now()];

        foreach (['name', 'from_email', 'from_name'] as $key) {
            if (array_key_exists($key, $data)) {
                $fields[$key] = $data[$key] === '' ? null : (string) $data[$key];
            }
        }

        if (isset($data['type'])) {
            if (!in_array((string) $data['type'], self::TYPES, true)) {
                throw new MailerException('Неизвестный тип транспорта: ' . $data['type']);
            }
            $fields['type'] = (string) $data['type'];
        }

        foreach (['priority', 'daily_limit'] as $key) {
            if (array_key_exists($key, $data)) {
                $fields[$key] = (int) $data[$key];
            }
        }

        foreach (['active', 'is_default'] as $key) {
            if (array_key_exists($key, $data)) {
                $fields[$key] = (int) (bool) $data[$key];
            }
        }

        if (array_key_exists('settings', $data)) {
            $settings = (array) $data['settings'];

            if (($settings['password'] ?? '') === '' && isset($current['settings']['password'])) {
                $settings['password'] = $current['settings']['password'];
            }

            $fields['settings'] = $this->encodeSettings($settings);
        }

        $this->db->update('transports', $fields, ['id' => $id]);

        if ((bool) ($data['is_default'] ?? false)) {
            $this->setDefault($id);
        }
    }

    public function delete(int $id): void
    {
        $this->db->delete('transports', ['id' => $id]);
    }

    /**
     * Делает транспорт основным, снимая флаг с остальных.
     */
    public function setDefault(int $id): void
    {
        // Обе строки только вместе: иначе можно остаться вообще без основного транспорта
        $this->db->transaction(function () use ($id): void {
            $this->db->execute('UPDATE transports SET is_default = 0');
            $this->db->update('transports', ['is_default' => 1, 'active' => 1, 'updated_at' => Database::now()], ['id' => $id]);
        });
    }

    /**
     * Отмечаем результат последней отправки — видно в панели.
     */
    public function markUsed(int $id, ?string $error = null): void
    {
        $this->db->update('transports', [
            'last_used_at' => Database::now(),
            'last_error'   => $error,
            'updated_at'   => Database::now(),
        ], ['id' => $id]);
    }

    /**
     * Настройки в JSON, пароль шифруем.
     *
     * @param array<string, mixed> $settings
     */
    private function encodeSettings(array $settings): string
    {
        if (isset($settings['password']) && is_string($settings['password']) && $settings['password'] !== '') {
            $settings['password'] = Crypto::encrypt($settings['password']);
        }

        if (isset($settings['dkim']['private_key']) && is_string($settings['dkim']['private_key']) && $settings['dkim']['private_key'] !== '') {
            $settings['dkim']['private_key'] = Crypto::encrypt($settings['dkim']['private_key']);
        }

        return (string) json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Приводит строку из базы к удобному виду: настройки — массивом, пароль расшифрован.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $settings = (array) json_decode((string) ($row['settings'] ?? '{}'), true);

        if (isset($settings['password']) && is_string($settings['password'])) {
            $settings['password'] = Crypto::decrypt($settings['password']);
        }

        if (isset($settings['dkim']['private_key']) && is_string($settings['dkim']['private_key'])) {
            $settings['dkim']['private_key'] = Crypto::decrypt($settings['dkim']['private_key']);
        }

        $row['settings']   = $settings;
        $row['id']         = (int) $row['id'];
        $row['priority']   = (int) $row['priority'];
        $row['active']     = (int) $row['active'];
        $row['is_default'] = (int) $row['is_default'];
        $row['daily_limit'] = (int) $row['daily_limit'];

        return $row;
    }
}
