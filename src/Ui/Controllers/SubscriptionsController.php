<?php

declare(strict_types=1);

namespace Mailer\Ui\Controllers;

use Mailer\Domain\Permission;
use Mailer\Domain\Scope;
use Mailer\Domain\Viewer;
use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Repository\ProjectRepository;
use Mailer\Repository\WebhookRepository;
use Mailer\Repository\WebhookSubscriptionRepository;
use Mailer\Security\Crypto;
use Mailer\Support\Config;
use Mailer\Ui\Audit;
use Mailer\Ui\View;
use Mailer\Webhook\Dispatcher;
use Mailer\Webhook\WebhookSender;
use Throwable;

/**
 * Подписки на события: куда проекту слать вебхуки и о чём именно.
 *
 * Доставки лежат рядом, в WebhooksController: там видно, что ушло и что ответили,
 * а здесь — кому и о чём мы вообще собираемся сообщать.
 */
final class SubscriptionsController extends ResourceController
{
    private WebhookSubscriptionRepository $subscriptions;
    private WebhookRepository $webhooks;
    private ProjectRepository $projects;

    public function __construct(
        WebhookSubscriptionRepository $subscriptions,
        WebhookRepository $webhooks,
        ProjectRepository $projects
    ) {
        $this->subscriptions = $subscriptions;
        $this->webhooks      = $webhooks;
        $this->projects      = $projects;
    }

    protected function listRoute(): string
    {
        return 'ui.subscriptions';
    }

    protected function notFoundMessage(): string
    {
        return 'Вебхук не найден';
    }

    public function index(Request $request, Scope $scope): Response
    {
        $result = $this->subscriptions->paginate(
            ['project_id' => (string) $request->query('project_id', '')],
            (int) $request->query('page', 1),
            (int) Config::get('ui.per_page', 30),
            $scope
        );

        return Response::html(View::render('subscriptions', [
            'active'   => 'webhooks',
            'result'   => $result,
            'projects' => $this->projects->all($scope),
            'filters'  => ['project_id' => (string) $request->query('project_id', '')],
        ], 'Вебхуки проектов'));
    }

    public function form(Request $request, ?int $id, Scope $scope, Viewer $viewer): Response
    {
        $subscription = $this->requireIfEditing($id, $id === null ? null : $this->subscriptions->find($id, $scope));

        $deliveries = [];
        if ($subscription !== null) {
            $deliveries = $this->webhooks->paginate(['subscription_id' => $id], 1, 10, $scope)['items'];
        }

        return Response::html(View::render('subscription_form', [
            'active'       => 'webhooks',
            'subscription' => $subscription,
            'projects'     => $this->projects->all($scope),
            'deliveries'   => $deliveries,
            // Проект приходит из карточки проекта: оттуда вебхук и заводят чаще всего
            'projectId'    => (int) $request->query('project_id', 0),
            'editable'     => $viewer->can(Permission::WEBHOOKS_MANAGE),
            'hasKey'       => Crypto::hasKey(),
        ], $subscription === null ? 'Новый вебхук' : 'Вебхук проекта'));
    }

    public function save(Request $request, Scope $scope): Response
    {
        $id        = (int) $request->input('id', 0);
        $projectId = (int) $request->input('project_id', 0);

        // Вебхук чужому проекту не заведёшь: проект должен быть виден
        $project = $this->require($this->projects->find($projectId, $scope));

        $data = [
            'project_id'      => (int) $project['id'],
            'name'            => trim((string) $request->input('name', '')),
            'url'             => trim((string) $request->input('url', '')),
            'secret'          => trim((string) $request->input('secret', '')),
            'events'          => (array) $request->input('events', []),
            'payload_version' => (int) $request->input('payload_version', 2),
            'active'          => $request->input('active') !== null,
        ];

        if ($id > 0) {
            $this->require($this->subscriptions->find($id, $scope));
        }

        try {
            if ($id > 0) {
                $this->subscriptions->update($id, $data);
                Audit::updated('subscription', $id, 'вебхук ' . $data['url']);
                View::flash('Вебхук сохранён');
            } else {
                $id = $this->subscriptions->create($data);
                Audit::created('subscription', $id, 'вебхук ' . $data['url']);
                View::flash('Вебхук заведён');
            }
        } catch (Throwable $e) {
            View::flash('Не сохранилось: ' . $e->getMessage(), 'error');

            return Response::redirect(View::route('ui.subscriptions'));
        }

        return Response::redirect(View::route('ui.subscriptions.show', ['id' => $id]));
    }

    public function action(Request $request, int $id, string $action, Scope $scope): Response
    {
        $subscription = $this->require($this->subscriptions->find($id, $scope));

        switch ($action) {
            case 'toggle':
                $active = (int) $subscription['active'] === 0;
                $this->subscriptions->update($id, ['active' => $active]);
                Audit::action('subscription', $id, ($active ? 'включён' : 'выключен') . ' вебхук ' . $subscription['url']);
                View::flash($active ? 'Вебхук включён' : 'Вебхук выключен');
                break;

            case 'test':
                return $this->test($subscription, $scope);

            case 'delete':
                $this->subscriptions->delete($id);
                Audit::deleted('subscription', $id, 'вебхук ' . $subscription['url']);
                View::flash('Вебхук удалён');

                return Response::redirect(View::route('ui.subscriptions'));

            default:
                View::flash('Неизвестное действие: ' . $action, 'error');
        }

        return Response::redirect(View::route('ui.subscriptions.show', ['id' => $id]));
    }

    /**
     * Проверка связи: шлём событие ping прямо сейчас и показываем, что ответили.
     * Ждать воркера тут незачем — человек стоит у формы и хочет знать результат.
     *
     * @param array<string, mixed> $subscription
     */
    private function test(array $subscription, Scope $scope): Response
    {
        $project = $this->projects->find((int) $subscription['project_id'], $scope);
        if ($project === null) {
            View::flash('Проект вебхука не найден', 'error');

            return Response::redirect(View::route('ui.subscriptions'));
        }

        $deliveryId = (new Dispatcher())->ping($subscription, $project);
        $delivery   = $this->webhooks->find($deliveryId);

        if ($delivery === null) {
            View::flash('Не удалось поставить проверку в очередь', 'error');

            return Response::redirect(View::route('ui.subscriptions.show', ['id' => (int) $subscription['id']]));
        }

        $ok = (new WebhookSender())->deliver($delivery);

        Audit::action('subscription', (int) $subscription['id'], 'проверка связи с ' . $subscription['url']);

        $result = $this->webhooks->find($deliveryId);
        View::flash(
            $ok
                ? 'Проверка прошла: сервер ответил ' . (int) ($result['response_code'] ?? 0)
                : 'Проверка не прошла: ' . (string) ($result['last_error'] ?? 'нет ответа'),
            $ok ? 'ok' : 'error'
        );

        return Response::redirect(View::route('ui.webhooks.show', ['id' => $deliveryId]));
    }
}
