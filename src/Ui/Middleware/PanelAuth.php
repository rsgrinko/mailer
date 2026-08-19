<?php

declare(strict_types=1);

namespace Mailer\Ui\Middleware;

use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Repository\UserRepository;
use Mailer\Ui\Auth;
use Mailer\Ui\View;

/**
 * Обычные страницы панели: пускаем только вошедших.
 * Пользователей в базе ещё нет — отправляем заводить первого.
 */
final class PanelAuth
{
    private UserRepository $users;

    public function __construct(?UserRepository $users = null)
    {
        $this->users = $users ?? new UserRepository();
    }

    public function __invoke(Request $request, callable $next): Response
    {
        if (!Auth::enabled()) {
            return $next($request);
        }

        Auth::start();

        if (Auth::check()) {
            return $next($request);
        }

        if ($this->users->count() === 0) {
            return Response::redirect(View::route('ui.setup'));
        }

        // Куда вернуть после входа: только для обычных переходов, POST повторять не будем
        $next = $request->method === 'GET'
            ? $request->path . ($request->query === [] ? '' : '?' . http_build_query($request->query))
            : '';

        return Response::redirect(View::route('ui.login', $next !== '' ? ['next' => $next] : []));
    }
}
