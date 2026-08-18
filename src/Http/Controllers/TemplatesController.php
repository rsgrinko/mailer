<?php

declare(strict_types=1);

namespace Mailer\Http\Controllers;

use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Repository\TemplateRepository;
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
        $items = [];

        foreach ($this->templates->all() as $template) {
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

        return Response::json(['items' => $items, 'total' => count($items)]);
    }
}
