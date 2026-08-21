<?php

declare(strict_types=1);

namespace Mailer\Ui\Controllers;

use Mailer\Domain\Scope;
use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Repository\ProjectRepository;
use Mailer\Repository\WebhookRepository;
use Mailer\Repository\WebhookSubscriptionRepository;
use Mailer\Support\Config;
use Mailer\Ui\Audit;
use Mailer\Ui\View;
use Mailer\Webhook\WebhookSender;

/**
 * Доставки вебхуков: что ушло проектам, что ответили и что не получилось.
 * Кому и о чём мы вообще шлём — рядом, в SubscriptionsController.
 */
final class WebhooksController extends ResourceController
{
    private WebhookRepository $webhooks;
    private WebhookSubscriptionRepository $subscriptions;
    private ProjectRepository $projects;

    public function __construct(
        WebhookRepository $webhooks,
        WebhookSubscriptionRepository $subscriptions,
        ProjectRepository $projects
    ) {
        $this->webhooks      = $webhooks;
        $this->subscriptions = $subscriptions;
        $this->projects      = $projects;
    }

    protected function listRoute(): string
    {
        return 'ui.webhooks';
    }

    protected function notFoundMessage(): string
    {
        return 'Доставка не найдена';
    }

    public function index(Request $request, Scope $scope): Response
    {
        $filters = [
            'status'          => (string) $request->query('status', ''),
            'event'           => (string) $request->query('event', ''),
            'project_id'      => (string) $request->query('project_id', ''),
            'subscription_id' => (string) $request->query('subscription_id', ''),
        ];

        $result = $this->webhooks->paginate(
            $filters,
            (int) $request->query('page', 1),
            (int) Config::get('ui.per_page', 30),
            $scope
        );

        return Response::html(View::render('webhooks', [
            'active'   => 'webhooks',
            'result'   => $result,
            'counts'   => $this->webhooks->countByStatus($scope),
            'projects' => $this->projects->all($scope),
            'filters'  => $filters,
        ], 'Вебхуки'));
    }

    /**
     * Карточка доставки: запрос как есть и ответ сервера целиком. По коду 500 без
     * тела чужой приёмник не отладить, поэтому храним и показываем всё.
     */
    public function show(Request $request, int $id, Scope $scope): Response
    {
        $item = $this->require($this->webhooks->find($id, $scope));

        $subscription = $item['subscription_id'] === null
            ? null
            : $this->subscriptions->find((int) $item['subscription_id'], $scope);

        return Response::html(View::render('webhook', [
            'active'       => 'webhooks',
            'item'         => $item,
            'subscription' => $subscription,
            'project'      => $item['project_id'] === null ? null : $this->projects->find((int) $item['project_id'], $scope),
        ], 'Доставка вебхука'));
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

    public function action(Request $request, int $id, string $action, Scope $scope): Response
    {
        $item = $this->require($this->webhooks->find($id, $scope));

        switch ($action) {
            case 'retry':
                $this->webhooks->retry($id);
                View::flash('Вебхук поставлен в очередь заново');
                break;

            case 'send':
                $ok = (new WebhookSender())->deliver($item);
                View::flash($ok ? 'Вебхук доставлен' : 'Доставить не удалось, подробности в карточке', $ok ? 'ok' : 'error');
                break;

            case 'delete':
                $this->webhooks->delete($id);
                Audit::deleted('webhook', $id, 'доставка вебхука «' . (string) $item['event'] . '»');
                View::flash('Запись удалена');

                return Response::redirect(View::route('ui.webhooks'));

            default:
                View::flash('Неизвестное действие: ' . $action, 'error');
        }

        return Response::redirect(View::route('ui.webhooks.show', ['id' => $id]));
    }
}
