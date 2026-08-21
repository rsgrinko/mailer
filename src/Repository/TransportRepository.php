<?php

declare(strict_types=1);

namespace Mailer\Repository;

use Mailer\Domain\Scope;
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

    /**
     * Настройки, которые нельзя держать в базе открытыми.
     *
     * Список общий на все типы: новому транспорту с ключом провайдера достаточно
     * назвать настройку одним из этих имён, и она зашифруется сама. Забытый ключ —
     * это пароль от чужой почты в открытом виде, поэтому лучше так, чем «не забыть».
     */
    public const SECRET_KEYS = ['password', 'api_key', 'secret_key', 'token'];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
    }

    /**
     * Со своей областью видимости пользователь видит свои транспорты и общие
     * (`shared = 1`) — те заводит администратор, чтобы не поднимать каждому свой SMTP.
     * Общие видно, но правит их только тот, у кого есть доступ к чужим данным.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(bool $onlyActive = false, ?Scope $scope = null): array
    {
        $conditions = $onlyActive ? ['active = 1'] : [];
        [$sql, $params] = self::scoped($scope);

        if ($sql !== '') {
            $conditions[] = $sql;
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        return array_map([$this, 'hydrate'], $this->db->select(
            'SELECT * FROM transports' . $where . ' ORDER BY priority, id',
            $params
        ));
    }

    /**
     * Сколько транспортов включено — для метрик и проверки состояния.
     */
    public function countActive(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM transports WHERE active = 1');
    }

    /**
     * Страница списка транспортов.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public function paginate(int $page = 1, int $perPage = 30, ?Scope $scope = null): array
    {
        [$sql, $params] = self::scoped($scope);
        $where          = $sql === '' ? '' : ' WHERE ' . $sql;

        $result          = $this->db->page('SELECT * FROM transports' . $where . ' ORDER BY priority, id', $params, $page, $perPage);
        $result['items'] = array_map([$this, 'hydrate'], $result['items']);

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id, ?Scope $scope = null): ?array
    {
        [$sql, $params] = self::scoped($scope);

        $row = $this->db->selectOne(
            'SELECT * FROM transports WHERE id = :id' . ($sql === '' ? '' : ' AND ' . $sql),
            array_merge(['id' => $id], $params)
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Условие области видимости: свои транспорты плюс общие.
     *
     * @return array{0: string, 1: array<string, int>}
     */
    private static function scoped(?Scope $scope): array
    {
        if ($scope === null) {
            return ['', []];
        }

        return [$scope->sql('owner_id', 'shared'), $scope->params()];
    }

    /**
     * Транспорт по имени. Область видимости нужна там, где имя пришло снаружи:
     * клиент API просит транспорт строкой, и чужой личный SMTP ему не положен.
     * Общие (`shared = 1`) доступны всем — для того их и заводят.
     *
     * @return array<string, mixed>|null
     */
    public function findByName(string $name, ?Scope $scope = null): ?array
    {
        [$sql, $params] = self::scoped($scope);

        $row = $this->db->selectOne(
            'SELECT * FROM transports WHERE name = :name' . ($sql === '' ? '' : ' AND ' . $sql),
            array_merge(['name' => $name], $params)
        );

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
            'owner_id'    => (int) ($data['owner_id'] ?? 0),
            'shared'      => (int) (bool) ($data['shared'] ?? false),
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

        if (array_key_exists('owner_id', $data)) {
            $fields['owner_id'] = (int) $data['owner_id'];
        }

        foreach (['active', 'is_default', 'shared'] as $key) {
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
     * Настройки в JSON, секреты шифруем.
     *
     * @param array<string, mixed> $settings
     */
    private function encodeSettings(array $settings): string
    {
        foreach (self::SECRET_KEYS as $key) {
            if (isset($settings[$key]) && is_string($settings[$key]) && $settings[$key] !== '') {
                $settings[$key] = Crypto::encrypt($settings[$key]);
            }
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

        foreach (self::SECRET_KEYS as $key) {
            if (isset($settings[$key]) && is_string($settings[$key])) {
                $settings[$key] = Crypto::decrypt($settings[$key]);
            }
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
        $row['owner_id']   = (int) ($row['owner_id'] ?? 0);
        $row['shared']     = (int) ($row['shared'] ?? 0);

        return $row;
    }
}
