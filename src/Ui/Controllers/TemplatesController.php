<?php

declare(strict_types=1);

namespace Mailer\Ui\Controllers;

use Mailer\Domain\Permission;
use Mailer\Domain\Scope;
use Mailer\Domain\Viewer;
use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\MailService;
use Mailer\Repository\MessageRepository;
use Mailer\Repository\TemplateRepository;
use Mailer\Support\Config;
use Mailer\Template\Renderer;
use Mailer\Ui\Audit;
use Mailer\Ui\View;
use Throwable;

/**
 * Шаблоны писем: список, правка и предпросмотр с подстановкой переменных.
 */
final class TemplatesController extends ResourceController
{
    private TemplateRepository $templates;
    private Renderer $renderer;

    public function __construct(
        TemplateRepository $templates,
        Renderer $renderer
    ) {
        $this->templates = $templates;
        $this->renderer  = $renderer;
    }

    public function index(Request $request, Scope $scope): Response
    {
        $result = $this->templates->paginate(
            (int) $request->query('page', 1),
            (int) Config::get('ui.per_page', 30),
            $scope
        );

        $items = $result['items'];

        foreach ($items as $index => $item) {
            $items[$index]['variables'] = $this->renderer->variables(
                (string) ($item['subject'] ?? ''),
                (string) ($item['html'] ?? ''),
                (string) ($item['text'] ?? '')
            );
        }

        return Response::html(View::render('templates', [
            'active' => 'templates',
            'items'  => $items,
            'result' => $result,
        ], 'Шаблоны'));
    }

    public function form(Request $request, ?int $id, Scope $scope): Response
    {
        $template = $this->requireIfEditing($id, $id === null ? null : $this->templates->find($id, $scope));

        $variables = $template === null ? [] : $this->renderer->variables(
            (string) ($template['subject'] ?? ''),
            (string) ($template['html'] ?? ''),
            (string) ($template['text'] ?? '')
        );

        // Предпросмотр: подставляем в шаблон присланные данные.
        // sample=auto — заполнить пример самим, чтобы не собирать JSON руками
        $preview = null;
        $sample  = (string) $request->query('sample', '');
        if ($sample === 'auto') {
            $sample = $template === null ? '' : self::sample($variables);
        }

        if ($template !== null && $sample !== '') {
            $data    = json_decode($sample, true);
            $preview = $this->renderer->renderTemplate($template, is_array($data) ? $data : []);
        }

        return Response::html(View::render('template_form', [
            'active'    => 'templates',
            'template'  => $template,
            'variables' => $variables,
            'preview'   => $preview,
            'sample'    => $sample,
        ], $template === null ? 'Новый шаблон' : 'Шаблон «' . $template['name'] . '»'));
    }

    public function save(Request $request, Scope $scope, Viewer $viewer): Response
    {
        $id = (int) $request->input('id', 0);

        // Чужой шаблон не правится: для пользователя его просто нет
        if ($id > 0) {
            $this->require($this->templates->find($id, $scope));
        }

        $data = [
            'name'        => trim((string) $request->input('name', '')),
            'description' => trim((string) $request->input('description', '')),
            'subject'     => (string) $request->input('subject', ''),
            'html'        => (string) $request->input('html', ''),
            'text'        => (string) $request->input('text', ''),
        ];

        if ($id === 0) {
            $data['owner_id'] = $viewer->id();
        }

        try {
            if ($id > 0) {
                $this->templates->update($id, $data);
                Audit::updated('template', $id, 'шаблон «' . $data['name'] . '»');
                View::flash('Шаблон сохранён');
            } else {
                $id = $this->templates->create($data);
                Audit::created('template', $id, 'шаблон «' . $data['name'] . '»');
                View::flash('Шаблон создан');
            }
        } catch (Throwable $e) {
            View::flash('Не сохранилось: ' . $e->getMessage(), 'error');

            return Response::redirect($id > 0 ? View::route('ui.templates.show', ['id' => $id]) : View::route('ui.templates.new'));
        }

        return Response::redirect(View::route('ui.templates.show', ['id' => $id]));
    }

    public function action(Request $request, int $id, string $action, Scope $scope, Viewer $viewer): Response
    {
        $template = $this->require($this->templates->find($id, $scope));

        if ($action === 'send') {
            return $this->sendPreview($request, $template, $viewer);
        }

        if ($action === 'delete') {
            $this->templates->delete($id);
            Audit::deleted('template', $id, 'шаблон «' . $template['name'] . '»');
            View::flash('Шаблон удалён');

            return Response::redirect(View::route('ui.templates'));
        }

        View::flash('Неизвестное действие: ' . $action, 'error');

        return Response::redirect(View::route('ui.templates.show', ['id' => $id]));
    }
    /**
     * Пробное письмо по шаблону: то же, что увидит получатель, но себе.
     * Правка шаблона и отправка писем — разные права, поэтому сверяем оба.
     *
     * @param array<string, mixed> $template
     */
    private function sendPreview(Request $request, array $template, Viewer $viewer): Response
    {
        $id   = (int) $template['id'];
        $to   = trim((string) $request->input('to', ''));
        $back = View::route('ui.templates.show', ['id' => $id]);

        if (!$viewer->can(Permission::MESSAGES_SEND)) {
            View::flash('Нет прав на отправку писем', 'error');

            return Response::redirect($back);
        }

        if ($to === '') {
            View::flash('Укажите адрес, куда отправить', 'error');

            return Response::redirect($back);
        }

        $data = [];
        $raw  = trim((string) $request->input('sample', ''));
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                View::flash('Данные для подстановки должны быть корректным JSON', 'error');

                return Response::redirect($back);
            }
            $data = $decoded;
        }

        try {
            $result = (new MailService())->accept([
                'to'            => $to,
                'template'      => (string) $template['name'],
                'template_data' => $data,
                'sync'          => true,
            ], null, MessageRepository::SOURCE_UI, $viewer->id());

            Audit::action('template', $id, 'пробное письмо по шаблону «' . $template['name'] . '» на ' . $to);
            View::flash('Письмо ' . $result['uuid'] . ': ' . $result['status']
                . (isset($result['info']) ? ' — ' . $result['info'] : ''));
        } catch (Throwable $e) {
            View::flash('Не отправилось: ' . $e->getMessage(), 'error');
        }

        return Response::redirect($back);
    }

    /**
     * Пример данных по переменным шаблона: {{ user.name }} превращается в
     * {"user": {"name": "Иван"}}. Значение подбираем по имени — так предпросмотр
     * сразу похож на настоящее письмо, а не на набор заглушек.
     *
     * @param array<int, string> $variables
     */
    private static function sample(array $variables): string
    {
        $data = [];

        foreach ($variables as $variable) {
            $path = explode('.', $variable);
            $ref  = &$data;

            foreach ($path as $index => $key) {
                if ($index === count($path) - 1) {
                    $ref[$key] = self::sampleValue($key);
                    continue;
                }

                if (!isset($ref[$key]) || !is_array($ref[$key])) {
                    $ref[$key] = [];
                }

                $ref = &$ref[$key];
            }

            unset($ref);
        }

        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function sampleValue(string $name): string
    {
        $name = mb_strtolower($name);

        return match (true) {
            str_contains($name, 'mail')                                => 'ivan@example.com',
            str_contains($name, 'phone') || str_contains($name, 'tel') => '+7 900 000-00-00',
            str_contains($name, 'url') || str_contains($name, 'link')  => 'https://example.com/',
            str_contains($name, 'site') || str_contains($name, 'host') => 'example.com',
            str_contains($name, 'date') || str_contains($name, 'day')  => date('d.m.Y'),
            str_contains($name, 'time')                                => date('H:i'),
            str_contains($name, 'code') || str_contains($name, 'pass') => '123456',
            str_contains($name, 'sum') || str_contains($name, 'price') => '1 000',
            str_contains($name, 'name') || str_contains($name, 'user') => 'Иван',
            default                                                    => 'значение ' . $name,
        };
    }

    protected function listRoute(): string
    {
        return 'ui.templates';
    }

    protected function notFoundMessage(): string
    {
        return 'Шаблон не найден';
    }
}
