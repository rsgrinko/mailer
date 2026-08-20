<?php

declare(strict_types=1);

namespace Mailer\Ui\Middleware;

use Mailer\Domain\Viewer;
use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Ui\Auth;
use Mailer\Ui\View;

/**
 * Право на раздел: `can:projects.manage` у группы маршрутов.
 *
 * Несколько прав через вертикальную черту — «хватит любого»:
 * `can:webhooks.view|webhooks.manage`.
 *
 * Прослойка отвечает только за доступ к адресу. Чьи записи показывать, решает Scope
 * внутри репозиториев.
 */
final class Can
{
    public function __invoke(Request $request, callable $next, string $permission = ''): Response
    {
        $viewer = $request->attribute('viewer');

        if (!$viewer instanceof Viewer) {
            $viewer = Auth::viewer();
        }

        if ($permission === '' || $viewer->canAny(explode('|', $permission))) {
            return $next($request);
        }

        // Кнопки и пункты меню без права не показываются, сюда попадают по прямой ссылке
        if ($request->method !== 'GET') {
            View::flash('Нет прав на это действие', 'error');

            return Response::redirect(View::route('ui.dashboard'));
        }

        return Response::html(
            View::render('error', [
                'heading' => 'Нет доступа',
                'message' => 'Этот раздел закрыт вашей ролью. Если доступ нужен — попросите администратора.',
            ], 'Нет доступа'),
            403
        );
    }
}
