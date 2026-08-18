<?php

declare(strict_types=1);

namespace Mailer\Support;

/**
 * Конфигурация сервиса: один раз читаем config/config.php и потом берём значения
 * по «точечному» пути, например Config::get('db.driver').
 */
final class Config
{
    /** @var array<string, mixed> */
    private static array $items = [];

    private static bool $loaded = false;

    /**
     * Читает config/config.php. Второй вызов ничего не делает.
     */
    public static function load(?string $file = null): void
    {
        if (self::$loaded) {
            return;
        }

        $file = $file ?? MAILER_ROOT . '/config/config.php';
        $data = is_file($file) ? require $file : [];

        self::$items  = is_array($data) ? $data : [];
        self::$loaded = true;
    }

    /**
     * Значение по пути вида 'smtp.timeout'. Если пути нет — вернём $default.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();

        $value = self::$items;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }

        return $value;
    }

    /**
     * Подменить значение в рантайме (нужно тестам).
     */
    public static function set(string $key, mixed $value): void
    {
        self::load();

        $parts = explode('.', $key);
        $ref    = &self::$items;
        foreach ($parts as $part) {
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = [];
            }
            $ref = &$ref[$part];
        }
        $ref = $value;
    }

    /**
     * Весь массив конфигурации.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        self::load();

        return self::$items;
    }
}
