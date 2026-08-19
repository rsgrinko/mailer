<?php

declare(strict_types=1);

namespace Mailer\Http\Controllers;

use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\MailService;
use Mailer\Queue\Queue;
use Mailer\Repository\EventRepository;
use Mailer\Repository\MessageRepository;
use Mailer\Support\Config;

/**
 * Письма: приём, статус, список, повтор и отмена.
 */
final class MessagesController
{
    private MailService $service;
    private MessageRepository $messages;
    private EventRepository $events;
    private Queue $queue;

    public function __construct(
        MailService $service,
        MessageRepository $messages,
        EventRepository $events,
        Queue $queue
    ) {
        $this->service  = $service;
        $this->messages = $messages;
        $this->events   = $events;
        $this->queue    = $queue;
    }

    /**
     * POST /api/v1/messages — принять письмо.
     *
     * @param array<string, mixed> $project
     */
    public function create(Request $request, array $project): Response
    {
        $payload = $request->body;

        if ($payload === []) {
            return Response::error('Пустое тело запроса: ожидается JSON с описанием письма', 422);
        }

        // Ключ идемпотентности можно передать заголовком
        $header = $request->header('idempotency-key');
        if ($header !== '' && !isset($payload['idempotency_key'])) {
            $payload['idempotency_key'] = $header;
        }

        $result = $this->service->accept($payload, $project, MessageRepository::SOURCE_API);

        $status = ($result['duplicate'] ?? false) ? 200 : 202;
        if (($result['sync'] ?? false) === true) {
            $status = $result['status'] === MessageRepository::SENT ? 200 : 502;
        }

        return Response::json([
            'id'        => $result['uuid'],
            'status'    => $result['status'],
            'duplicate' => $result['duplicate'] ?? false,
            'sync'      => $result['sync'] ?? false,
            'info'      => $result['info'] ?? null,
        ], $status);
    }

    /**
     * GET /api/v1/messages/{id} — состояние письма.
     *
     * @param array<string, mixed> $project
     */
    public function show(Request $request, array $project, string $id): Response
    {
        $row = $this->messages->findAny($id);

        if ($row === null || (int) $row['project_id'] !== (int) $project['id']) {
            return Response::error('Письмо не найдено', 404);
        }

        $events = [];
        foreach ($this->events->forMessage((int) $row['id']) as $event) {
            $events[] = [
                'type'    => $event['type'],
                'message' => $event['message'],
                'at'      => $event['created_at'],
            ];
        }

        return Response::json(['message' => $this->present($row), 'events' => $events]);
    }

    /**
     * GET /api/v1/messages — список писем проекта.
     *
     * @param array<string, mixed> $project
     */
    public function index(Request $request, array $project): Response
    {
        $result = $this->messages->paginate(
            [
                'project_id' => (int) $project['id'],
                'status'     => (string) $request->query('status', ''),
                'tag'        => (string) $request->query('tag', ''),
                'search'     => (string) $request->query('search', ''),
                'date_from'  => (string) $request->query('date_from', ''),
                'date_to'    => (string) $request->query('date_to', ''),
            ],
            (int) $request->query('page', 1),
            (int) $request->query('per_page', (int) Config::get('ui.per_page', 30))
        );

        return Response::json([
            'items' => array_map([$this, 'present'], $result['items']),
            'page'  => $result['page'],
            'pages' => $result['pages'],
            'total' => $result['total'],
        ]);
    }

    /**
     * POST /api/v1/messages/{id}/retry — повторить отправку.
     *
     * @param array<string, mixed> $project
     */
    public function retry(Request $request, array $project, string $id): Response
    {
        $row = $this->messages->findAny($id);

        if ($row === null || (int) $row['project_id'] !== (int) $project['id']) {
            return Response::error('Письмо не найдено', 404);
        }

        if ((string) $row['status'] === MessageRepository::SENT) {
            return Response::error('Письмо уже отправлено', 409);
        }

        $this->queue->retry((int) $row['id'], 'Повтор запрошен через API');

        return Response::json(['id' => $row['uuid'], 'status' => MessageRepository::QUEUED]);
    }

    /**
     * DELETE /api/v1/messages/{id} — отменить письмо в очереди.
     *
     * @param array<string, mixed> $project
     */
    public function cancel(Request $request, array $project, string $id): Response
    {
        $row = $this->messages->findAny($id);

        if ($row === null || (int) $row['project_id'] !== (int) $project['id']) {
            return Response::error('Письмо не найдено', 404);
        }

        if (!$this->queue->cancel((int) $row['id'], 'Отменено через API')) {
            return Response::error('Письмо уже отправлено или отменено', 409);
        }

        return Response::json(['id' => $row['uuid'], 'status' => MessageRepository::CANCELED]);
    }

    /**
     * Как письмо выглядит в ответе API.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'id'         => $row['uuid'],
            'status'     => $row['status'],
            'subject'    => $row['subject'],
            'to'         => array_column($this->messages->decodeArray($row['to_json'] ?? null), 'email'),
            'from'       => $row['from_email'] ?? null,
            'tag'        => $row['tag'] ?? null,
            'attempts'   => (int) ($row['attempts'] ?? 0),
            'transport'  => $row['transport_used'] ?? null,
            'error'      => $row['last_error'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'sent_at'    => $row['sent_at'] ?? null,
        ];
    }
}
