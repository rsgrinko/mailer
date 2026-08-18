<?php

declare(strict_types=1);

namespace Mailer\Repository;

use Mailer\Storage\Database;

/**
 * Служебные значения «ключ-значение»: heartbeat воркера, позиция round-robin и подобное.
 */
final class SettingRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $row = $this->db->selectOne(
            'SELECT value FROM settings WHERE setting_key = :key',
            ['key' => $key]
        );

        return $row === null ? $default : (string) $row['value'];
    }

    public function set(string $key, string $value): void
    {
        $updated = $this->db->update(
            'settings',
            ['value' => $value, 'updated_at' => Database::now()],
            ['setting_key' => $key]
        );

        if ($updated === 0) {
            // Строки не было — создаём. Гонку двух воркеров переживём: ключ уникальный,
            // повторная вставка просто не пройдёт, а значение уже записано соседом.
            try {
                $this->db->insert('settings', [
                    'setting_key' => $key,
                    'value'       => $value,
                    'updated_at'  => Database::now(),
                ]);
            } catch (\Throwable) {
                $this->db->update(
                    'settings',
                    ['value' => $value, 'updated_at' => Database::now()],
                    ['setting_key' => $key]
                );
            }
        }
    }

    public function forget(string $key): void
    {
        $this->db->delete('settings', ['setting_key' => $key]);
    }

    /**
     * Все значения — показываем в панели на странице состояния.
     *
     * @return array<string, array{value: string, updated_at: string}>
     */
    public function all(): array
    {
        $result = [];

        foreach ($this->db->select('SELECT * FROM settings ORDER BY setting_key') as $row) {
            $result[(string) $row['setting_key']] = [
                'value'      => (string) $row['value'],
                'updated_at' => (string) $row['updated_at'],
            ];
        }

        return $result;
    }
}
