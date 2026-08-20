<?php

declare(strict_types=1);

namespace Mailer\Ui\Controllers;

use Mailer\Domain\Scope;
use Mailer\Domain\Viewer;
use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\RateLimit\RateLimiter;
use Mailer\Repository\MessageRepository;
use Mailer\Repository\ProjectRepository;
use Mailer\Repository\TransportRepository;
use Mailer\Repository\UserRepository;
use Mailer\Support\Config;
use Mailer\Ui\View;
use Throwable;

/**
 * Проекты и их API-ключи.
 */
final class ProjectsController extends ResourceController
{
    private ProjectRepository $projects;
    private TransportRepository $transports;
    private MessageRepository $messages;
    private RateLimiter $limiter;
    private UserRepository $users;

    public function __construct(
        ProjectRepository $projects,
        TransportRepository $transports,
        MessageRepository $messages,
        RateLimiter $limiter,
        UserRepository $users
    ) {
        $this->projects   = $projects;
        $this->transports = $transports;
        $this->messages   = $messages;
        $this->limiter    = $limiter;
        $this->users      = $users;
    }

    public function index(Request $request, Scope $scope): Response
    {
        $result = $this->projects->paginate(
            (int) $request->query('page', 1),
            (int) Config::get('ui.per_page', 30),
            $scope
        );

        $usage = [];
        foreach ($result['items'] as $item) {
            $usage[(int) $item['id']] = $this->limiter->projectUsage((int) $item['id']);
        }

        return Response::html(View::render('projects', [
            'active' => 'projects',
            'items'  => $result['items'],
            'result' => $result,
            'usage'  => $usage,
        ], 'Проекты'));
    }

    public function form(Request $request, ?int $id, Scope $scope, Viewer $viewer): Response
    {
        $project = $this->requireIfEditing($id, $id === null ? null : $this->projects->find($id, $scope));

        $recent = [];
        if ($project !== null) {
            $recent = $this->messages->paginate(['project_id' => $id], 1, 10, $scope)['items'];
        }

        return Response::html(View::render('project_form', [
            'active'     => 'projects',
            'project'    => $project,
            'transports' => $this->transports->all(false, $scope),
            'usage'      => $project !== null ? $this->limiter->projectUsage((int) $project['id']) : ['hour' => 0, 'day' => 0],
            'recent'     => $recent,
            // Владельца выбирают только те, кто видит чужие данные: остальным он и так свой
            'owners'     => $viewer->isAdmin() ? $this->users->all() : [],
        ], $project === null ? 'Новый проект' : 'Проект «' . $project['name'] . '»'));
    }

    public function save(Request $request, Scope $scope, Viewer $viewer): Response
    {
        $id = (int) $request->input('id', 0);

        $data = [
            'name'               => trim((string) $request->input('name', '')),
            'description'        => trim((string) $request->input('description', '')),
            'transport_id'       => (int) $request->input('transport_id', 0) ?: null,
            'default_from_email' => trim((string) $request->input('default_from_email', '')),
            'default_from_name'  => trim((string) $request->input('default_from_name', '')),
            'rate_limit_hour'    => (int) $request->input('rate_limit_hour', 0),
            'rate_limit_day'     => (int) $request->input('rate_limit_day', 0),
            'webhook_url'        => trim((string) $request->input('webhook_url', '')),
            'active'             => $request->input('active') !== null,
        ];

        $secret = trim((string) $request->input('webhook_secret', ''));
        if ($secret !== '') {
            $data['webhook_secret'] = $secret;
        }

        // Владельца назначает только тот, кто видит чужие данные, остальные заводят на себя
        if ($viewer->isAdmin()) {
            $owner = (int) $request->input('owner_id', 0);
            if ($owner > 0 || $id === 0) {
                $data['owner_id'] = $owner;
            }
        } elseif ($id === 0) {
            $data['owner_id'] = $viewer->id();
        }

        // Чужой проект правится ровно так же, как несуществующий, — никак
        if ($id > 0) {
            $this->require($this->projects->find($id, $scope));
        }

        try {
            if ($id > 0) {
                $this->projects->update($id, $data);
                View::flash('Проект сохранён');
            } else {
                $created = $this->projects->create($data);
                $id      = (int) $created['project']['id'];

                View::flash('Проект создан. API-ключ (сохраните, он больше не покажется): ' . $created['key']);
            }
        } catch (Throwable $e) {
            View::flash('Не сохранилось: ' . $e->getMessage(), 'error');

            return Response::redirect($id > 0 ? View::route('ui.projects.show', ['id' => $id]) : View::route('ui.projects.new'));
        }

        return Response::redirect(View::route('ui.projects.show', ['id' => $id]));
    }

    public function action(Request $request, int $id, string $action, Scope $scope): Response
    {
        $project = $this->require($this->projects->find($id, $scope));

        switch ($action) {
            case 'key':
                $key = $this->projects->regenerateKey($id);
                View::flash('Новый ключ (старый больше не работает): ' . $key);
                break;

            case 'toggle':
                $this->projects->update($id, ['active' => (int) $project['active'] !== 1]);
                View::flash((int) $project['active'] === 1 ? 'Проект отключён' : 'Проект включён');
                break;

            case 'delete':
                $this->projects->delete($id);
                View::flash('Проект удалён. Его письма остались в истории.');

                return Response::redirect(View::route('ui.projects'));

            default:
                View::flash('Неизвестное действие: ' . $action, 'error');
        }

        return Response::redirect(View::route('ui.projects.show', ['id' => $id]));
    }
    protected function listRoute(): string
    {
        return 'ui.projects';
    }

    protected function notFoundMessage(): string
    {
        return 'Проект не найден';
    }
}
