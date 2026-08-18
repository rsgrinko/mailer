<?php

declare(strict_types=1);

namespace Mailer\Security;

use Mailer\Support\Str;

/**
 * API-ключ проекта. Выглядит так: mlr_<префикс>_<секрет>.
 * Префикс хранится открыто (по нему быстро находим проект), секрет — только хешем.
 */
final class ApiKey
{
    /**
     * Создаёт новый ключ.
     *
     * @return array{key: string, prefix: string, hash: string}
     */
    public static function generate(): array
    {
        $prefix = Str::random(8);
        $secret = Str::random(40);
        $key    = 'mlr_' . $prefix . '_' . $secret;

        return [
            'key'    => $key,
            'prefix' => $prefix,
            'hash'   => self::hash($key),
        ];
    }

    /**
     * Хеш ключа для хранения в базе.
     */
    public static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    /**
     * Достаёт префикс из ключа — по нему ищем проект в базе.
     */
    public static function prefix(string $key): string
    {
        $parts = explode('_', $key);

        return count($parts) >= 3 ? $parts[1] : '';
    }

    /**
     * Сравнение хешей без утечки времени.
     */
    public static function matches(string $key, string $storedHash): bool
    {
        return hash_equals($storedHash, self::hash($key));
    }

    /**
     * Как показывать ключ в панели, не раскрывая его целиком.
     */
    public static function mask(string $prefix): string
    {
        return 'mlr_' . $prefix . '_' . str_repeat('•', 8);
    }
}
