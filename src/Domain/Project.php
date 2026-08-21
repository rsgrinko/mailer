<?php

declare(strict_types=1);

namespace Mailer\Domain;

/**
 * Проект-клиент API: то, что о нём нужно знать логике приёма писем.
 *
 * Строка из базы приходит массивом (её же ждут шаблоны панели), а вот код,
 * который принимает решения — лимиты, доступ, отправитель по умолчанию, —
 * работает с этим объектом: опечатку в имени поля здесь не спрячешь.
 */
final class Project
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly bool $active,
        public readonly int $rateLimitHour,
        public readonly int $rateLimitDay,
        public readonly ?int $transportId,
        public readonly ?string $defaultFromEmail,
        public readonly ?string $defaultFromName
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
            (int) ($row['active'] ?? 1) === 1,
            (int) ($row['rate_limit_hour'] ?? 0),
            (int) ($row['rate_limit_day'] ?? 0),
            isset($row['transport_id']) && $row['transport_id'] !== null ? (int) $row['transport_id'] : null,
            self::text($row['default_from_email'] ?? null),
            self::text($row['default_from_name'] ?? null)
        );
    }

    private static function text(mixed $value): ?string
    {
        $value = $value === null ? '' : trim((string) $value);

        return $value === '' ? null : $value;
    }
}
