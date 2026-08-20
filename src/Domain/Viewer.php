<?php

declare(strict_types=1);

namespace Mailer\Domain;

/**
 * Кто смотрит панель: пользователь, его права и область видимости.
 *
 * Объект собирает прослойка panel-auth и кладёт в атрибуты запроса, а роутер
 * подставляет его в методы контроллеров по имени аргумента — так же, как проект
 * от прослойки api-key. Контроллеру не нужно знать про сессию.
 */
final class Viewer
{
    private int $id;

    private string $login;

    /** @var array<int, string> */
    private array $permissions;

    /** Доступ без ограничений: авторизация панели выключена или это консоль */
    private bool $full;

    /**
     * @param array<int, string> $permissions
     */
    private function __construct(int $id, string $login, array $permissions, bool $full)
    {
        $this->id          = $id;
        $this->login       = $login;
        $this->permissions = $permissions;
        $this->full        = $full;
    }

    /**
     * Полный доступ — когда спрашивать некого: UI_AUTH=false, консоль, воркер.
     */
    public static function full(): self
    {
        return new self(0, '', Permission::all(), true);
    }

    /**
     * @param array<string, mixed> $user строка users вместе с правами роли
     */
    public static function fromUser(array $user): self
    {
        return new self(
            (int) ($user['id'] ?? 0),
            (string) ($user['login'] ?? ''),
            Permission::filter((array) ($user['permissions'] ?? [])),
            false
        );
    }

    public function id(): int
    {
        return $this->id;
    }

    public function login(): string
    {
        return $this->login;
    }

    public function can(string $permission): bool
    {
        return $this->full || in_array($permission, $this->permissions, true);
    }

    /**
     * Есть ли хотя бы одно право из списка — для разделов, куда пускают и на просмотр,
     * и на правку.
     *
     * @param array<int, string> $permissions
     */
    public function canAny(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Видит ли чужие записи.
     */
    public function isAdmin(): bool
    {
        return $this->can(Permission::DATA_ALL);
    }

    public function scope(): Scope
    {
        return $this->isAdmin() ? Scope::all() : Scope::owner($this->id);
    }

    /**
     * @return array<int, string>
     */
    public function permissions(): array
    {
        return $this->permissions;
    }
}
