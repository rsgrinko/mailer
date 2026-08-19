<?php

declare(strict_types=1);

namespace Mailer\Ui\Controllers;

use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\MailService;
use Mailer\Message\MimeBuilder;
use Mailer\Message\MimeParser;
use Mailer\Queue\Queue;
use Mailer\Repository\EventRepository;
use Mailer\Repository\MessageRepository;
use Mailer\Repository\ProjectRepository;
use Mailer\Repository\TemplateRepository;
use Mailer\Repository\TransportRepository;
use Mailer\Repository\WebhookRepository;
use Mailer\Storage\Database;
use Mailer\Support\Config;
use Mailer\Ui\View;
use Throwable;

/**
 * Письма в панели: список, карточка, действия и форма ручной отправки.
 */
final class MessagesController
{
    private MessageRepository $messages;
    private EventRepository $events;
    private ProjectRepository $projects;
    private TransportRepository $transports;
    private TemplateRepository $templates;
    private WebhookRepository $webhooks;
    private Queue $queue;
    private MailService $service;

    public function __construct()
    {
        $this->messages   = new MessageRepository();
        $this->events     = new EventRepository();
        $this->projects   = new ProjectRepository();
        $this->transports = new TransportRepository();
        $this->templates  = new TemplateRepository();
        $this->webhooks   = new WebhookRepository();
        $this->queue      = new Queue();
        $this->service    = new MailService();
    }

    /**
     * Список писем с фильтрами.
     */
    public function index(Request $request): Response
    {
        $filters = [
            'status'       => (string) $request->query('status', ''),
            'project_id'   => (string) $request->query('project_id', ''),
            'transport_id' => (string) $request->query('transport_id', ''),
            'source'       => (string) $request->query('source', ''),
            'tag'          => (string) $request->query('tag', ''),
            'search'       => (string) $request->query('search', ''),
            'date_from'    => (string) $request->query('date_from', ''),
            'date_to'      => (string) $request->query('date_to', ''),
        ];

        $result = $this->messages->paginate(
            $filters,
            (int) $request->query('page', 1),
            (int) $request->query('per_page', (int) Config::get('ui.per_page', 30))
        );

        return Response::html(View::render('messages', [
            'active'     => 'messages',
            'result'     => $result,
            'filters'    => $filters,
            'projects'   => $this->projects->all(),
            'transports' => $this->transports->all(),
            'counts'     => $this->messages->countByStatus(),
        ], 'Письма'));
    }

    /**
     * Карточка письма: всё, что о нём известно.
     */
    public function show(Request $request, int $id): Response
    {
        $row = $this->messages->find($id);

        if ($row === null) {
            View::flash('Письмо не найдено', 'error');

            return Response::redirect(View::route('ui.messages'));
        }

        // Собираем письмо целиком — его удобно посмотреть глазами
        $mime = '';
        try {
            $mime = $row['raw_mime'] !== null && $row['raw_mime'] !== ''
                ? (string) $row['raw_mime']
                : (new MimeBuilder())->build($this->messages->toMessage($row));
        } catch (Throwable $e) {
            $mime = 'Не удалось собрать письмо: ' . $e->getMessage();
        }

        // У писем из sendmail и релея текст лежит внутри сырого MIME
        $preview = ['text' => (string) ($row['text_body'] ?? ''), 'html' => (string) ($row['html_body'] ?? '')];
        if ($preview['text'] === '' && $preview['html'] === '' && $row['raw_mime'] !== null) {
            $parsed          = MimeParser::parse((string) $row['raw_mime']);
            $preview['text'] = $parsed['text'];
            $preview['html'] = $parsed['html'];
        }

        return Response::html(View::render('message', [
            'active'      => 'messages',
            'message'     => $row,
            'to'          => $this->messages->decodeArray($row['to_json'] ?? null),
            'cc'          => $this->messages->decodeArray($row['cc_json'] ?? null),
            'bcc'         => $this->messages->decodeArray($row['bcc_json'] ?? null),
            'headers'     => $this->messages->decodeArray($row['headers_json'] ?? null),
            'meta'        => $this->messages->decodeArray($row['meta'] ?? null),
            'templateData' => $this->messages->decodeArray($row['template_data'] ?? null),
            'attachments' => $this->messages->decodeArray($row['attachments_json'] ?? null),
            'events'      => $this->events->forMessage($id),
            'project'     => $row['project_id'] !== null ? $this->projects->find((int) $row['project_id']) : null,
            'transport'   => $row['transport_id'] !== null ? $this->transports->find((int) $row['transport_id']) : null,
            'webhooks'    => $this->webhooks->paginate(['message_id' => $id], 1, 20)['items'],
            'mime'        => $mime,
            'preview'     => $preview,
        ], 'Письмо'));
    }

    /**
     * Действия над письмом: повторить, отменить, отправить сейчас, удалить.
     */
    public function action(Request $request, int $id, string $action): Response
    {
        if (!(bool) Config::get('ui.allow_actions', true)) {
            View::flash('Действия из панели отключены настройкой UI_ALLOW_ACTIONS', 'error');

            return Response::redirect(View::route('ui.messages.show', ['id' => $id]));
        }

        $row = $this->messages->find($id);
        if ($row === null) {
            View::flash('Письмо не найдено', 'error');

            return Response::redirect(View::route('ui.messages'));
        }

        switch ($action) {
            case 'retry':
                if ($this->queue->retry($id, 'Повтор из панели')) {
                    View::flash('Письмо возвращено в очередь');
                } else {
                    View::flash(
                        'Повторить нельзя: письмо уже отправлено. Чтобы отправить такое же — нажмите «Написать похожее».',
                        'error'
                    );
                }
                break;

            case 'cancel':
                if ($this->queue->cancel($id, 'Отмена из панели')) {
                    View::flash('Письмо отменено');
                } else {
                    View::flash('Отменить нельзя: письмо уже отправлено или отменено', 'error');
                }
                break;

            case 'send':
                try {
                    $result = $this->service->sendNow($id);
                    View::flash('Результат отправки: ' . $result['status'] . ' — ' . $result['info']);
                } catch (Throwable $e) {
                    View::flash('Не удалось отправить: ' . $e->getMessage(), 'error');
                }
                break;

            case 'delete':
                $this->messages->delete($id);
                View::flash('Письмо удалено');

                return Response::redirect(View::route('ui.messages'));

            default:
                View::flash('Неизвестное действие: ' . $action, 'error');
        }

        return Response::redirect(View::route('ui.messages.show', ['id' => $id]));
    }

    /**
     * Массовые действия из списка.
     */
    public function bulk(Request $request): Response
    {
        $action = (string) $request->input('action', '');
        $status = (string) $request->input('status', MessageRepository::FAILED);
        $count  = 0;

        $page = $this->messages->paginate(['status' => $status], 1, 500);

        // Пачка обрабатывается одной транзакцией: либо применилось ко всем письмам, либо ни к одному
        Database::instance()->transaction(function () use ($page, $action, &$count): void {
            foreach ($page['items'] as $row) {
                $id = (int) $row['id'];

                if ($action === 'retry') {
                    $count += $this->queue->retry($id, 'Массовый повтор из панели') ? 1 : 0;
                } elseif ($action === 'cancel') {
                    $count += $this->queue->cancel($id, 'Массовая отмена из панели') ? 1 : 0;
                } elseif ($action === 'delete') {
                    $this->messages->delete($id);
                    $count++;
                }
            }
        });

        View::flash('Обработано писем: ' . $count);

        return Response::redirect(View::route('ui.messages', ['status' => $status]));
    }

    /**
     * Скачать письмо целиком в формате .eml.
     */
    public function raw(Request $request, int $id): Response
    {
        $row = $this->messages->find($id);
        if ($row === null) {
            return Response::text('Письмо не найдено', 404);
        }

        $mime = $row['raw_mime'] !== null && $row['raw_mime'] !== ''
            ? (string) $row['raw_mime']
            : (new MimeBuilder())->build($this->messages->toMessage($row));

        return Response::download($mime, 'message-' . $row['uuid'] . '.eml', 'message/rfc822');
    }

    /**
     * Скачать вложение.
     */
    public function attachment(Request $request, int $id): Response
    {
        $row = $this->messages->find($id);
        if ($row === null) {
            return Response::text('Письмо не найдено', 404);
        }

        $index       = (int) $request->query('index', 0);
        $attachments = $this->messages->decodeArray($row['attachments_json'] ?? null);
        $attachment  = $attachments[$index] ?? null;

        if (!is_array($attachment)) {
            return Response::text('Вложение не найдено', 404);
        }

        $path = (string) ($attachment['path'] ?? '');
        if ($path === '' || !is_file($path)) {
            return Response::text('Файл вложения не найден на диске', 404);
        }

        return Response::download(
            (string) file_get_contents($path),
            (string) ($attachment['name'] ?? 'file'),
            (string) ($attachment['content_type'] ?? 'application/octet-stream')
        );
    }

    /**
     * Форма отправки письма прямо из панели.
     */
    public function composeForm(Request $request): Response
    {
        $prefill = [];

        // Можно открыть форму, подставив данные существующего письма
        $copyId = (int) $request->query('copy', 0);
        if ($copyId > 0) {
            $row = $this->messages->find($copyId);
            if ($row !== null) {
                $prefill = [
                    'to'        => implode(', ', array_column($this->messages->decodeArray($row['to_json'] ?? null), 'email')),
                    'subject'   => (string) $row['subject'],
                    'text'      => (string) ($row['text_body'] ?? ''),
                    'html'      => (string) ($row['html_body'] ?? ''),
                    'from'      => (string) ($row['from_email'] ?? ''),
                    'transport' => $row['transport_id'] !== null ? (string) $row['transport_id'] : '',
                ];
            }
        }

        return Response::html(View::render('compose', [
            'active'     => 'compose',
            'transports' => $this->transports->all(),
            'templates'  => $this->templates->all(),
            'projects'   => $this->projects->all(),
            'prefill'    => $prefill,
        ], 'Написать письмо'));
    }

    /**
     * Отправка письма из панели.
     */
    public function compose(Request $request): Response
    {
        $payload = [
            'to'      => (string) $request->input('to', ''),
            'cc'      => (string) $request->input('cc', ''),
            'bcc'     => (string) $request->input('bcc', ''),
            'subject' => (string) $request->input('subject', ''),
            'text'    => (string) $request->input('text', ''),
            'html'    => (string) $request->input('html', ''),
            'sync'    => $request->input('sync') !== null,
        ];

        foreach (['from', 'reply_to', 'tag', 'template'] as $field) {
            $value = trim((string) $request->input($field, ''));
            if ($value !== '') {
                $payload[$field] = $value;
            }
        }

        $templateData = trim((string) $request->input('template_data', ''));
        if ($templateData !== '') {
            $decoded = json_decode($templateData, true);
            if (is_array($decoded)) {
                $payload['template_data'] = $decoded;
            } else {
                View::flash('Данные шаблона должны быть корректным JSON', 'error');

                return Response::redirect(View::route('ui.compose'));
            }
        }

        $transportId = (int) $request->input('transport_id', 0);
        if ($transportId > 0) {
            $transport = $this->transports->find($transportId);
            if ($transport !== null) {
                $payload['transport'] = (string) $transport['name'];
            }
        }

        $project   = null;
        $projectId = (int) $request->input('project_id', 0);
        if ($projectId > 0) {
            $project = $this->projects->find($projectId);
        }

        try {
            $result = $this->service->accept($payload, $project, MessageRepository::SOURCE_UI);

            View::flash(
                'Письмо ' . $result['uuid'] . ': ' . $result['status']
                . (isset($result['info']) ? ' — ' . $result['info'] : '')
            );

            return Response::redirect(View::route('ui.messages.show', ['id' => $result['id']]));
        } catch (Throwable $e) {
            View::flash('Не получилось: ' . $e->getMessage(), 'error');

            return Response::redirect(View::route('ui.compose'));
        }
    }
}
