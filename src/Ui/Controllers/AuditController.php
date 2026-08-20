<?php

declare(strict_types=1);

namespace Mailer\Ui\Controllers;

use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Repository\AuditRepository;
use Mailer\Storage\Database;
use Mailer\Support\Config;
use Mailer\Ui\View;

/**
 * Журнал действий панели: кто что менял.
 *
 * Раздел не делится по владельцам — его и так видно только по праву audit.view.
 * Смысл журнала в том, чтобы администратор видел все руки, а не свои.
 */
final class AuditController
{
    private AuditRepository $audit;

    public function __construct(AuditRepository $audit)
    {
        $this->audit = $audit;
    }

    public function index(Request $request): Response
    {
        // Раздел может открыться раньше, чем накатят миграцию: лучше сказать об этом,
        // чем показать пятисотую
        if (!Database::instance()->hasTable('audit_log')) {
            View::flash('Журнал появится после миграции: php bin/mailer migrate', 'error');

            return Response::redirect(View::route('ui.dashboard'));
        }

        $filters = [
            'user_id' => (string) $request->query('user_id', ''),
            'entity'  => (string) $request->query('entity', ''),
            'action'  => (string) $request->query('action', ''),
            'search'  => trim((string) $request->query('search', '')),
        ];

        $result = $this->audit->paginate(
            $filters,
            (int) $request->query('page', 1),
            (int) Config::get('ui.per_page', 30)
        );

        return Response::html(View::render('audit', [
            'active'   => 'audit',
            'result'   => $result,
            'filters'  => $filters,
            'entities' => $this->audit->entities(),
            'users'    => $this->audit->users(),
        ], 'Журнал действий'));
    }
}
