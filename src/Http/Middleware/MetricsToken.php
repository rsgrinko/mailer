<?php

declare(strict_types=1);

namespace Mailer\Http\Middleware;

use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Support\Config;

/**
 * Доступ к метрикам. Токен задаётся в .env (METRICS_TOKEN) и передаётся заголовком
 * Authorization: Bearer <токен> или параметром ?token= — Prometheus умеет и то, и другое.
 *
 * Токен не задан — адрес открыт: в закрытом контуре так удобнее, а снаружи его
 * всё равно закрывают на nginx. Выключить совсем — METRICS_ENABLED=false, тогда
 * адреса просто нет.
 */
final class MetricsToken
{
    public function __invoke(Request $request, callable $next): Response
    {
        if (!(bool) Config::get('metrics.enabled', true)) {
            return Response::error('Метрики выключены', 404);
        }

        $expected = (string) Config::get('metrics.token', '');

        if ($expected === '') {
            return $next($request);
        }

        $given = $request->bearerToken();
        if ($given === '') {
            $given = (string) $request->query('token', '');
        }

        if (!hash_equals($expected, $given)) {
            return Response::error('Неверный токен метрик', 401);
        }

        return $next($request);
    }
}
