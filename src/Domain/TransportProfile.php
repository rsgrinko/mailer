<?php

declare(strict_types=1);

namespace Mailer\Domain;

/**
 * Профиль отправки из таблицы transports — настройки, а не сам транспорт.
 * Сам отправляющий объект собирает TransportFactory, здесь только данные.
 */
final class TransportProfile
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $type,
        public readonly bool $active,
        public readonly bool $isDefault,
        public readonly int $dailyLimit,
        public readonly ?string $fromEmail,
        public readonly ?string $fromName
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) ($row['id'] ?? 0),
            (string) ($row['name'] ?? ''),
            (string) ($row['type'] ?? ''),
            (int) ($row['active'] ?? 1) === 1,
            (int) ($row['is_default'] ?? 0) === 1,
            (int) ($row['daily_limit'] ?? 0),
            self::text($row['from_email'] ?? null),
            self::text($row['from_name'] ?? null)
        );
    }

    private static function text(mixed $value): ?string
    {
        $value = $value === null ? '' : trim((string) $value);

        return $value === '' ? null : $value;
    }
}
