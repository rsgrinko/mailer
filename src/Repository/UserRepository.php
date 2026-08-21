<?php

declare(strict_types=1);

namespace Mailer\Repository;

use Mailer\Domain\Permission;
use Mailer\Security\Password;
use Mailer\Storage\Database;
use Mailer\Support\MailerException;

/**
 * Пользователи панели. Права приходят из роли (таблица roles), своих галочек
 * у пользователя нет: поменяли роль — поменялось у всех, кому она выдана.
 */
final class UserRepository
{
    /**
     * Пользователя всегда читаем вместе с ролью: без прав он бесполезен,
     * а второй запрос за ними пришлось бы делать на каждой странице панели.
     */
    private const SELECT = 'SELECT u.*, r.name AS role_name, r.is_system AS role_is_system,'
        . ' r.permissions AS role_permissions FROM users u LEFT JOIN roles r ON r.id = u.role_id';

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
        return array_map([$this, 'hydrate'], $this->db->select(self::SELECT . ' ORDER BY u.login'));
    }

    /**
     * Страница списка пользователей.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public function paginate(int $page = 1, int $perPage = 30): array
    {
        $result          = $this->db->page(self::SELECT . ' ORDER BY u.login', [], $page, $perPage);
        $result['items'] = array_map([$this, 'hydrate'], $result['items']);

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->db->selectOne(self::SELECT . ' WHERE u.id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByLogin(string $login): ?array
    {
        $row = $this->db->selectOne(
            self::SELECT . ' WHERE u.login = :login',
            ['login' => self::normalizeLogin($login)]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Сколько пользователей заведено — по нулю понимаем, что панель ещё не настроена.
     */
    public function count(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM users');
    }

    /**
     * Сколько пользователей могут войти. Последнего такого отключать нельзя.
     */
    public function countActive(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM users WHERE active = 1');
    }

    /**
     * Сколько активных пользователей могут управлять пользователями. Последнего такого
     * нельзя ни отключить, ни удалить, ни перевести на роль попроще — иначе в панель
     * никто не сможет ни завести человека, ни выдать права.
     */
    public function countManagers(): int
    {
        // Встроенная роль считается всегда: её права берутся из кода, в базе их может
        // не быть — там лежит набор, записанный при создании роли
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id'
            . ' WHERE u.active = 1 AND (r.is_system = 1 OR r.permissions LIKE :permission)',
            ['permission' => '%"' . Permission::USERS_MANAGE . '"%']
        );
    }

    /**
     * @param array<string, mixed> $data login, password, name, active, role_id
     * @return array<string, mixed> созданный пользователь
     */
    public function create(array $data): array
    {
        $login = self::normalizeLogin((string) ($data['login'] ?? ''));
        self::checkLogin($login);

        if ($this->findByLogin($login) !== null) {
            throw new MailerException('Пользователь «' . $login . '» уже есть');
        }

        $id = $this->db->insert('users', [
            'login'         => $login,
            'name'          => self::name($data),
            'password_hash' => Password::hash((string) ($data['password'] ?? '')),
            'active'        => (int) (bool) ($data['active'] ?? true),
            'role_id'       => self::roleId($data),
            'created_at'    => Database::now(),
            'updated_at'    => Database::now(),
        ]);

        return (array) $this->find($id);
    }

    /**
     * Меняет логин, имя и признак активности. Пароль — отдельным методом.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $user = $this->find($id);
        if ($user === null) {
            throw new MailerException('Пользователь не найден');
        }

        $fields = ['updated_at' => Database::now()];

        if (isset($data['login'])) {
            $login = self::normalizeLogin((string) $data['login']);
            self::checkLogin($login);

            $existing = $this->findByLogin($login);
            if ($existing !== null && (int) $existing['id'] !== $id) {
                throw new MailerException('Пользователь «' . $login . '» уже есть');
            }

            $fields['login'] = $login;
        }

        if (array_key_exists('name', $data)) {
            $fields['name'] = self::name($data);
        }

        if (array_key_exists('active', $data)) {
            $active = (int) (bool) $data['active'];

            if ($active === 0 && (int) $user['active'] === 1) {
                if ($this->countActive() <= 1) {
                    throw new MailerException('Это последний активный пользователь — отключить его нельзя');
                }

                $this->checkLastManager($user, 'отключить');
            }

            $fields['active'] = $active;
        }

        if (array_key_exists('role_id', $data)) {
            $roleId = self::roleId($data);

            if ($roleId !== (int) ($user['role_id'] ?? 0) && (int) $user['active'] === 1) {
                $this->checkLastManager($user, 'перевести на другую роль');
            }

            $fields['role_id'] = $roleId;
        }

        $this->db->update('users', $fields, ['id' => $id]);
    }

    /**
     * Не даёт остаться без единого человека, который управляет пользователями.
     *
     * @param array<string, mixed> $user
     */
    private function checkLastManager(array $user, string $action): void
    {
        if (!in_array(Permission::USERS_MANAGE, (array) ($user['permissions'] ?? []), true)) {
            return;
        }

        if ($this->countManagers() > 1) {
            return;
        }

        throw new MailerException(
            'Это последний пользователь, который управляет пользователями, — ' . $action . ' его нельзя'
        );
    }

    /**
     * Задаёт новый пароль.
     */
    public function setPassword(int $id, string $password): void
    {
        if ($this->find($id) === null) {
            throw new MailerException('Пользователь не найден');
        }

        $this->db->update('users', [
            'password_hash' => Password::hash($password),
            'updated_at'    => Database::now(),
        ], ['id' => $id]);
    }

    /**
     * Удаляет пользователя. Последнего не отдаём — иначе в панель никто не войдёт.
     * Из консоли ограничение можно снять ключом --force: там человек знает, что делает.
     */
    public function delete(int $id, bool $force = false): void
    {
        $user = $this->find($id);
        if ($user === null) {
            throw new MailerException('Пользователь не найден');
        }

        if ($force) {
            $this->db->delete('users', ['id' => $id]);

            return;
        }

        if ($this->count() <= 1) {
            throw new MailerException('Это последний пользователь — удалить его нельзя');
        }

        if ((int) $user['active'] === 1 && $this->countActive() <= 1) {
            throw new MailerException('Это последний активный пользователь — удалить его нельзя');
        }

        if ((int) $user['active'] === 1) {
            $this->checkLastManager($user, 'удалить');
        }

        $this->db->delete('users', ['id' => $id]);
    }

    /**
     * Проверяет логин с паролем. Возвращает пользователя или null.
     *
     * @return array<string, mixed>|null
     */
    public function verify(string $login, string $password): ?array
    {
        $user = $this->findByLogin($login);

        if ($user === null || (int) $user['active'] !== 1) {
            // Хешируем вхолостую, чтобы несуществующий логин отвечал не быстрее существующего
            Password::verify($password, '$2y$10$usesomesillystringfooobarbazquuxJ4KzHkVn7X8mLm2lkyEO');

            return null;
        }

        if (!Password::verify($password, (string) $user['password_hash'])) {
            return null;
        }

        // Алгоритм хеширования в PHP со временем меняется — обновляем хеш на лету
        if (Password::needsRehash((string) $user['password_hash'])) {
            $this->setPassword((int) $user['id'], $password);
        }

        return $user;
    }

    /**
     * Отмечает удачный вход.
     */
    public function touchLogin(int $id, string $ip): void
    {
        $this->db->update('users', [
            'last_login_at' => Database::now(),
            'last_login_ip' => $ip,
            'updated_at'    => Database::now(),
        ], ['id' => $id]);
    }

    /**
     * Логин без регистра и лишних пробелов — чтобы «Ivan» и «ivan» были одним человеком.
     */
    public static function normalizeLogin(string $login): string
    {
        return mb_strtolower(trim($login));
    }

    private static function checkLogin(string $login): void
    {
        if ($login === '') {
            throw new MailerException('Не указан логин');
        }

        if (mb_strlen($login) > 64) {
            throw new MailerException('Слишком длинный логин: не больше 64 символов');
        }

        if (preg_match('/^[a-z0-9._@-]+$/u', $login) !== 1) {
            throw new MailerException('В логине допустимы латинские буквы, цифры и символы . _ - @');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function name(array $data): ?string
    {
        $name = trim((string) ($data['name'] ?? ''));

        return $name === '' ? null : $name;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function roleId(array $data): ?int
    {
        $roleId = (int) ($data['role_id'] ?? 0);

        return $roleId > 0 ? $roleId : null;
    }

    /**
     * Права роли — рядом с пользователем. Роли нет (её удалили или пользователь
     * заведён до разделения прав) — прав нет: в панель пустит, дальше не пустит.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        // Правило про встроенную роль живёт в RoleRepository — здесь только зовём его
        $row['permissions'] = RoleRepository::permissions(
            (int) ($row['role_is_system'] ?? 0) === 1,
            (string) ($row['role_permissions'] ?? '[]')
        );

        unset($row['role_permissions']);

        return $row;
    }
}
