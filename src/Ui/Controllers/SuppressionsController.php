<?php

declare(strict_types=1);

namespace Mailer\Ui\Controllers;

use Mailer\Domain\Scope;
use Mailer\Domain\Viewer;
use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Repository\ProjectRepository;
use Mailer\Repository\SuppressionRepository;
use Mailer\Storage\Database;
use Mailer\Support\Config;
use Mailer\Support\Validator;
use Mailer\Ui\Audit;
use Mailer\Ui\View;

/**
 * Стоп-лист: кому сервис больше не пишет.
 *
 * Адреса попадают сюда сами (отказ сервера получателя, отписка) и руками.
 * Видно только свои записи; те, что завёл сервис без владельца, — по data.all.
 */
final class SuppressionsController
{
    private SuppressionRepository $suppressions;
    private ProjectRepository $projects;

    public function __construct(SuppressionRepository $suppressions, ProjectRepository $projects)
    {
        $this->suppressions = $suppressions;
        $this->projects     = $projects;
    }

    public function index(Request $request, Scope $scope): Response
    {
        // Раздел может открыться раньше, чем накатят миграцию
        if (!Database::instance()->hasTable('suppressions')) {
            View::flash('Стоп-лист появится после миграции: php bin/mailer migrate', 'error');

            return Response::redirect(View::route('ui.dashboard'));
        }

        $filters = [
            'reason'     => (string) $request->query('reason', ''),
            'project_id' => (string) $request->query('project_id', ''),
            'search'     => trim((string) $request->query('search', '')),
        ];

        $result = $this->suppressions->paginate(
            $filters,
            (int) $request->query('page', 1),
            (int) Config::get('ui.per_page', 30),
            $scope
        );

        return Response::html(View::render('suppressions', [
            'active'   => 'suppress',
            'result'   => $result,
            'filters'  => $filters,
            'counts'   => $this->suppressions->countByReason($scope),
            'projects' => $this->projects->all($scope),
        ], 'Стоп-лист'));
    }

    /**
     * Закрыть адрес руками.
     */
    public function store(Request $request, Scope $scope, Viewer $viewer): Response
    {
        $email = trim((string) $request->input('email', ''));

        if (!Validator::isEmail($email)) {
            View::flash('Это не похоже на адрес: ' . $email, 'error');

            return Response::redirect(View::route('ui.suppressions'));
        }

        // Проект берём только свой: чужой в списке всё равно не покажется
        $projectId = (int) $request->input('project_id', 0);
        if ($projectId > 0 && $this->projects->find($projectId, $scope) === null) {
            $projectId = 0;
        }

        $id = $this->suppressions->block($email, (string) $request->input('reason', SuppressionRepository::MANUAL), 'ui', [
            'project_id' => $projectId,
            'owner_id'   => $viewer->id(),
            'note'       => trim((string) $request->input('note', '')),
        ]);

        Audit::created('suppression', $id, 'адрес ' . $email . ' закрыт стоп-листом');
        View::flash('Адрес ' . $email . ' закрыт: письма ему больше не уйдут');

        return Response::redirect(View::route('ui.suppressions'));
    }

    /**
     * Открыть адрес обратно.
     */
    public function delete(Request $request, int $id, Scope $scope): Response
    {
        $row = $this->suppressions->find($id, $scope);

        if ($row === null) {
            View::flash('Запись не найдена', 'error');

            return Response::redirect(View::route('ui.suppressions'));
        }

        $this->suppressions->delete($id);

        Audit::deleted('suppression', $id, 'адрес ' . $row['email'] . ' открыт обратно');
        View::flash('Адрес ' . $row['email'] . ' снова доступен для отправки');

        return Response::redirect(View::route('ui.suppressions'));
    }
}
