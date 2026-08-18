<?php

declare(strict_types=1);

namespace Mailer\Http;

/**
 * Простой роутер. Маршруты вида '/api/v1/messages/{id}' — фигурные скобки
 * превращаются в параметры, которые получает обработчик.
 */
final class Router
{
    /** @var array<int, array{method: string, pattern: string, handler: callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): self
    {
        $this->routes[] = [
            'method'  => strtoupper($method),
            'pattern' => rtrim($pattern, '/') === '' ? '/' : rtrim($pattern, '/'),
            'handler' => $handler,
        ];

        return $this;
    }

    public function get(string $pattern, callable $handler): self
    {
        return $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): self
    {
        return $this->add('POST', $pattern, $handler);
    }

    public function delete(string $pattern, callable $handler): self
    {
        return $this->add('DELETE', $pattern, $handler);
    }

    /**
     * Ищет подходящий маршрут и вызывает его обработчик.
     * Если маршрут не найден — 404, если найден путь, но не метод — 405.
     */
    public function dispatch(Request $request): Response
    {
        $pathMatched = false;

        foreach ($this->routes as $route) {
            $params = $this->match($route['pattern'], $request->path);
            if ($params === null) {
                continue;
            }

            $pathMatched = true;

            if ($route['method'] !== $request->method) {
                continue;
            }

            return ($route['handler'])($request, $params);
        }

        if ($pathMatched) {
            return Response::error('Метод ' . $request->method . ' для этого адреса не поддерживается', 405);
        }

        return Response::error('Адрес не найден: ' . $request->path, 404);
    }

    /**
     * Сопоставляет шаблон и путь. Возвращает параметры или null.
     *
     * @return array<string, string>|null
     */
    private function match(string $pattern, string $path): ?array
    {
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#u';

        if (preg_match($regex, $path, $matches) !== 1) {
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
}
