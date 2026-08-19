<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Http\Router;

/**
 * карта адресов сервиса.
 */
final class RouteListCommand extends Command
{
    public function name(): string
    {
        return 'route:list';
    }

    public function description(): string
    {
        return 'карта адресов сервиса';
    }

    public function usage(): string
    {
        return 'route:list [строка]';
    }

    /**
     * Карта адресов сервиса: что куда ведёт, под какими прослойками и как называется.
     */
    public function run(): int
    {
        $router = new Router();

        // Настоящие прослойки не нужны — нам важны только их имена у маршрутов
        foreach (['api-key', 'panel-auth', 'panel-guest', 'panel-setup'] as $name) {
            $router->middleware($name, static fn (Request $request, callable $next): Response => $next($request));
        }

        $router->load(MAILER_ROOT . '/routes/api.php');
        $router->load(MAILER_ROOT . '/routes/ui.php');

        $needle = (string) ($this->args[0] ?? '');
        $rows   = [];

        foreach ($router->routes() as $route) {
            $handler = is_array($route->handler)
                ? substr((string) strrchr('\\' . $route->handler[0], '\\'), 1) . '::' . $route->handler[1]
                : 'функция';

            $row = [
                implode('|', $route->methods),
                $route->pattern,
                $handler,
                implode(', ', $route->middleware) ?: '—',
                $route->name ?? '',
            ];

            if ($needle !== '' && !str_contains(mb_strtolower(implode(' ', $row)), mb_strtolower($needle))) {
                continue;
            }

            $rows[] = $row;
        }

        if ($rows === []) {
            $this->line($needle === '' ? 'Маршрутов нет.' : 'Ничего не нашлось по «' . $needle . '».');

            return 0;
        }

        $widths = [];
        foreach ([0, 1, 2, 3] as $column) {
            $widths[$column] = max(array_map(static fn (array $row): int => mb_strlen($row[$column]), $rows)) + 2;
        }

        $this->line($this->pad('Метод', $widths[0]) . $this->pad('Адрес', $widths[1])
            . $this->pad('Обработчик', $widths[2]) . $this->pad('Прослойки', $widths[3]) . 'Имя');

        foreach ($rows as $row) {
            $this->line($this->pad($row[0], $widths[0]) . $this->pad($row[1], $widths[1])
                . $this->pad($row[2], $widths[2]) . $this->pad($row[3], $widths[3]) . $row[4]);
        }

        $this->line('');
        $this->line('Всего маршрутов: ' . count($rows));

        return 0;
    
    }
}
