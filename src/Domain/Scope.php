<?php

declare(strict_types=1);

namespace Mailer\Domain;

/**
 * Область видимости: чьи записи показывать. Либо все (администратор, консоль, воркер),
 * либо только записи одного владельца.
 *
 * Это отдельная от прав вещь: право пускает в раздел, область решает, какие строки
 * из него видно. Условие уезжает прямо в SQL — так фильтр нельзя забыть в одном
 * из десятка мест, где раньше проверяли бы вручную.
 */
final class Scope
{
    /** Имя параметра в запросе. Своё, чтобы не столкнуться с фильтрами списка */
    public const PARAM = 'scope_owner';

    /** null — ограничений нет */
    private ?int $ownerId;

    private function __construct(?int $ownerId)
    {
        $this->ownerId = $ownerId;
    }

    /**
     * Видно всё.
     */
    public static function all(): self
    {
        return new self(null);
    }

    /**
     * Видно только своё.
     */
    public static function owner(int $userId): self
    {
        return new self($userId);
    }

    /**
     * Область видимости проекта-клиента API: ему доступны записи его владельца.
     * Ключ проекта — такой же вход в данные, как и панель, и чужие шаблоны с
     * транспортами через него доставаться не должны.
     *
     * У проекта, заведённого до разделения прав, владельца нет — ограничивать
     * его нечем, поэтому null: видно всё, как и раньше.
     *
     * @param array<string, mixed>|null $project
     */
    public static function forProject(?array $project): ?self
    {
        $ownerId = (int) ($project['owner_id'] ?? 0);

        return $ownerId > 0 ? self::owner($ownerId) : null;
    }

    public function isAll(): bool
    {
        return $this->ownerId === null;
    }

    /**
     * Владелец, от лица которого смотрим. 0 — ограничений нет.
     */
    public function ownerId(): int
    {
        return $this->ownerId ?? 0;
    }

    /**
     * Условие для WHERE или пустая строка, если ограничивать нечем.
     *
     * $sharedColumn — колонка «общая запись» (транспорты): такие видно всем.
     */
    public function sql(string $column = 'owner_id', ?string $sharedColumn = null): string
    {
        if ($this->isAll()) {
            return '';
        }

        $own = $column . ' = :' . self::PARAM;

        return $sharedColumn === null ? $own : '(' . $own . ' OR ' . $sharedColumn . ' = 1)';
    }

    /**
     * Параметры к условию.
     *
     * @return array<string, int>
     */
    public function params(): array
    {
        return $this->isAll() ? [] : [self::PARAM => $this->ownerId];
    }

    /**
     * Своя ли запись. Нужно там, где строка уже прочитана: действия над письмом,
     * выбор транспорта в форме.
     *
     * @param array<string, mixed>|null $row
     */
    public function owns(?array $row, string $column = 'owner_id', ?string $sharedColumn = null): bool
    {
        if ($row === null) {
            return false;
        }

        if ($this->isAll()) {
            return true;
        }

        if ($sharedColumn !== null && (int) ($row[$sharedColumn] ?? 0) === 1) {
            return true;
        }

        return (int) ($row[$column] ?? 0) === $this->ownerId;
    }
}
