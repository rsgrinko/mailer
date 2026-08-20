<?php

declare(strict_types=1);

namespace Mailer\Ui\Controllers;

use Mailer\Domain\Scope;
use Mailer\Domain\Viewer;
use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Repository\TemplateRepository;
use Mailer\Support\Config;
use Mailer\Template\Renderer;
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

        // Предпросмотр: подставляем в шаблон присланные данные
        $preview = null;
        $sample  = (string) $request->query('sample', '');
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
                View::flash('Шаблон сохранён');
            } else {
                $id = $this->templates->create($data);
                View::flash('Шаблон создан');
            }
        } catch (Throwable $e) {
            View::flash('Не сохранилось: ' . $e->getMessage(), 'error');

            return Response::redirect($id > 0 ? View::route('ui.templates.show', ['id' => $id]) : View::route('ui.templates.new'));
        }

        return Response::redirect(View::route('ui.templates.show', ['id' => $id]));
    }

    public function action(Request $request, int $id, string $action, Scope $scope): Response
    {
        $template = $this->require($this->templates->find($id, $scope));

        if ($action === 'delete') {
            $this->templates->delete($id);
            View::flash('Шаблон удалён');

            return Response::redirect(View::route('ui.templates'));
        }

        View::flash('Неизвестное действие: ' . $action, 'error');

        return Response::redirect(View::route('ui.templates.show', ['id' => $id]));
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
