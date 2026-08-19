<?php

declare(strict_types=1);

namespace Mailer\Ui\Controllers;

use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Repository\ProjectRepository;
use Mailer\Repository\WebhookRepository;
use Mailer\Support\Config;
use Mailer\Ui\View;
use Mailer\Webhook\WebhookSender;

/**
 * Вебхуки: что отправлено проектам и что не получилось.
 */
final class WebhooksController
{
    private WebhookRepository $webhooks;
    private ProjectRepository $projects;

    public function __construct(
        WebhookRepository $webhooks,
        ProjectRepository $projects
    ) {
        $this->webhooks = $webhooks;
        $this->projects = $projects;
    }

    public function index(Request $request): Response
    {
        $result = $this->webhooks->paginate(
            [
                'status'     => (string) $request->query('status', ''),
                'project_id' => (string) $request->query('project_id', ''),
            ],
            (int) $request->query('page', 1),
            (int) Config::get('ui.per_page', 30)
        );

        return Response::html(View::render('webhooks', [
            'active'   => 'webhooks',
            'result'   => $result,
            'counts'   => $this->webhooks->countByStatus(),
            'projects' => $this->projects->all(),
            'filters'  => [
                'status'     => (string) $request->query('status', ''),
                'project_id' => (string) $request->query('project_id', ''),
            ],
        ], 'Вебхуки'));
    }

    /**
     * Разослать всё, что накопилось, не дожидаясь воркера.
     */
    public function process(Request $request): Response
    {
        $count = (new WebhookSender())->processQueue(100);
        View::flash('Обработано вебхуков: ' . $count);

        return Response::redirect(View::route('ui.webhooks'));
    }

    public function action(Request $request, int $id, string $action): Response
    {
        $item = $this->webhooks->find($id);

        if ($item === null) {
            View::flash('Запись не найдена', 'error');

            return Response::redirect(View::route('ui.webhooks'));
        }

        switch ($action) {
            case 'retry':
                $this->webhooks->retry($id);
                View::flash('Вебхук поставлен в очередь заново');
                break;

            case 'send':
                $ok = (new WebhookSender())->deliver($item);
                View::flash($ok ? 'Вебхук доставлен' : 'Доставить не удалось, подробности в списке', $ok ? 'ok' : 'error');
                break;

            case 'delete':
                $this->webhooks->delete($id);
                View::flash('Запись удалена');
                break;

            default:
                View::flash('Неизвестное действие: ' . $action, 'error');
        }

        return Response::redirect(View::route('ui.webhooks'));
    }
}
