<?php

declare(strict_types=1);

namespace Mailer\Http\Controllers;

use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Repository\TemplateRepository;
use Mailer\Support\Config;
use Mailer\Template\Renderer;

/**
 * Шаблоны писем: список с перечнем переменных, которые в них используются.
 */
final class TemplatesController
{
    private TemplateRepository $templates;
    private Renderer $renderer;

    public function __construct()
    {
        $this->templates = new TemplateRepository();
        $this->renderer  = new Renderer();
    }

    /**
     * GET /api/v1/templates
     */
    public function index(Request $request): Response
    {
        $result = $this->templates->paginate(
            (int) $request->query('page', 1),
            (int) $request->query('per_page', (int) Config::get('ui.per_page', 30))
        );

        $items = [];

        foreach ($result['items'] as $template) {
            $items[] = [
                'name'        => $template['name'],
                'description' => $template['description'],
                'subject'     => $template['subject'],
                'variables'   => $this->renderer->variables(
                    (string) ($template['subject'] ?? ''),
                    (string) ($template['html'] ?? ''),
                    (string) ($template['text'] ?? '')
                ),
                'updated_at'  => $template['updated_at'],
            ];
        }

        return Response::json([
            'items' => $items,
            'total' => $result['total'],
            'page'  => $result['page'],
            'pages' => $result['pages'],
        ]);
    }
}
