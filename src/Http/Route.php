<?php

declare(strict_types=1);

namespace Mailer\Http;

use Mailer\Support\MailerException;

/**
 * Один маршрут: методы, шаблон пути, обработчик и прослойки.
 *
 * Шаблон пишется как '/ui/messages/{id}'. Параметру можно задать ограничение
 * через двоеточие — '{id:\d+}'; без него берётся любой кусок пути до слэша.
 */
final class Route
{
    /** @var array<int, string> */
    public array $methods;

    public string $pattern;

    /** @var callable|array{0: class-string, 1: string} */
    public mixed $handler;

    /** @var array<int, string> Имена прослоек в порядке применения */
    public array $middleware;

    public ?string $name = null;

    /** Собранная регулярка — считаем один раз при первом обращении */
    private ?string $regex = null;

    /**
     * @param array<int, string>                            $methods
     * @param callable|array{0: class-string, 1: string}    $handler
     * @param array<int, string>                            $middleware
     */
    public function __construct(array $methods, string $pattern, mixed $handler, array $middleware = [])
    {
        $this->methods    = array_map('strtoupper', $methods);
        $this->pattern    = self::normalize($pattern);
        $this->handler    = $handler;
        $this->middleware = $middleware;
    }

    /**
     * Имя маршрута — по нему потом собирается адрес.
     */
    public function name(string $name): self
    {
        $this->name = $name;

        Router::remember($this);

        return $this;
    }

    /**
     * Сопоставляет путь с шаблоном. Возвращает параметры или null, если не подошло.
     *
     * @return array<string, string>|null
     */
    public function match(string $path): ?array
    {
        if (preg_match($this->regex(), self::normalize($path), $matches) !== 1) {
            return null;
        }

        $params = [];

        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = rawurldecode((string) $value);
            }
        }

        return $params;
    }

    public function allows(string $method): bool
    {
        return in_array(strtoupper($method), $this->methods, true);
    }

    /**
     * Собирает адрес по шаблону: подставляет параметры, остальное уходит в query-строку.
     *
     * @param array<string, mixed> $params
     */
    public function url(array $params = []): string
    {
        $path = (string) preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[^{}]+)?\}#',
            static function (array $m) use (&$params): string {
                $name = $m[1];

                if (!array_key_exists($name, $params)) {
                    throw new MailerException('Для адреса не хватает параметра «' . $name . '»');
                }

                $value = (string) $params[$name];
                unset($params[$name]);

                return rawurlencode($value);
            },
            $this->pattern
        );

        $params = array_filter($params, static fn ($value): bool => $value !== '' && $value !== null);

        return $params === [] ? $path : $path . '?' . http_build_query($params);
    }

    /**
     * Путь без хвостового слэша: так же его нормализует Request.
     */
    public static function normalize(string $path): string
    {
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }

    private function regex(): string
    {
        if ($this->regex === null) {
            $regex = (string) preg_replace_callback(
                '#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^{}]+))?\}#',
                static fn (array $m): string => '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')',
                $this->pattern
            );

            $this->regex = '#^' . $regex . '$#u';
        }

        return $this->regex;
    }
}
