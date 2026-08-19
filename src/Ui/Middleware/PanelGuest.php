<?php

declare(strict_types=1);

namespace Mailer\Ui\Middleware;

use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Repository\UserRepository;
use Mailer\Ui\Auth;
use Mailer\Ui\View;

/**
 * Форма входа: вошедшему тут делать нечего, а если пользователей ещё нет —
 * сначала первый запуск.
 */
final class PanelGuest
{
    private ?UserRepository $users;

    public function __construct(?UserRepository $users = null)
    {
        $this->users = $users;
    }

    public function __invoke(Request $request, callable $next): Response
    {
        if (!Auth::enabled()) {
            return $next($request);
        }

        Auth::start();

        if (Auth::check()) {
            return Response::redirect(View::route('ui.dashboard'));
        }

        if ($this->users()->count() === 0) {
            return Response::redirect(View::route('ui.setup'));
        }

        return $next($request);
    }
    /**
     * Репозиторий берём при первом обращении: собирать маршруты можно и с лежащей базой.
     */
    private function users(): UserRepository
    {
        return $this->users ??= new UserRepository();
    }
}
