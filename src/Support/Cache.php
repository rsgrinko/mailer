<?php

declare(strict_types=1);

namespace Mailer\Support;

/**
 * Простейший файловый кэш на несколько секунд.
 *
 * Нужен ровно для одного случая: тяжёлых сводок на обзоре панели. Считать график
 * за две недели заново на каждое обновление страницы незачем — цифры там не про
 * секундную точность. Общий каталог, поэтому кэш видят все процессы php-fpm.
 */
final class Cache
{
    /**
     * Значение из кэша или свежее, если срок вышел.
     *
     * @template T
     * @param callable(): T $factory
     * @return T
     */
    public static function remember(string $key, int $seconds, callable $factory): mixed
    {
        if ($seconds <= 0) {
            return $factory();
        }

        $file = self::file($key);

        if (is_file($file) && (time() - (int) filemtime($file)) < $seconds) {
            $cached = json_decode((string) file_get_contents($file), true);

            if (is_array($cached) && array_key_exists('value', $cached)) {
                /** @var T $value */
                $value = $cached['value'];

                return $value;
            }
        }

        $value = $factory();

        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        // Пишем через временный файл: параллельный процесс не должен прочитать половину
        $temp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temp, (string) json_encode(['value' => $value], JSON_UNESCAPED_UNICODE)) !== false) {
            @rename($temp, $file);
        }

        return $value;
    }

    /**
     * Забыть значение — например, после массового действия над письмами.
     */
    public static function forget(string $key): void
    {
        $file = self::file($key);

        if (is_file($file)) {
            @unlink($file);
        }
    }

    private static function file(string $key): string
    {
        $dir = (string) Config::get('paths.tmp', MAILER_ROOT . '/var/tmp');

        return $dir . '/cache/' . md5($key) . '.json';
    }
}
