<?php

declare(strict_types=1);

namespace Mailer\Ui\Middleware;

use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Ui\Csrf;
use Mailer\Ui\View;

/**
 * Проверка токена у всех изменяющих запросов панели.
 * Читающие запросы (GET, HEAD, OPTIONS) проходят как есть.
 */
final class CsrfGuard
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __invoke(Request $request, callable $next): Response
    {
        if (in_array($request->method, self::SAFE_METHODS, true)) {
            return $next($request);
        }

        $sent = (string) $request->input(Csrf::FIELD, '');
        if ($sent === '') {
            $sent = $request->header(Csrf::HEADER);
        }

        if (!Csrf::check($sent)) {
            return Response::html(
                View::render(
                    'error',
                    ['message' => 'Форма устарела или отправлена не из панели. Обновите страницу и повторите действие.'],
                    'Проверка формы'
                ),
                403
            );
        }

        return $next($request);
    }
}
