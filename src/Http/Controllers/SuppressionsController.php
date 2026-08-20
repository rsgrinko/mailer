<?php

declare(strict_types=1);

namespace Mailer\Http\Controllers;

use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Repository\SuppressionRepository;
use Mailer\Support\Config;
use Mailer\Support\MailerException;
use Mailer\Support\Validator;

/**
 * Стоп-лист через API: посмотреть, закрыть адрес, открыть обратно.
 *
 * Проект видит только свои записи и общие (те, что закрыты для всех проектов) —
 * чужой стоп-лист его не касается.
 */
final class SuppressionsController
{
    private SuppressionRepository $suppressions;

    public function __construct(SuppressionRepository $suppressions)
    {
        $this->suppressions = $suppressions;
    }

    /**
     * GET /api/v1/suppressions
     *
     * @param array<string, mixed> $project
     */
    public function index(Request $request, array $project): Response
    {
        $result = $this->suppressions->paginate(
            [
                'reason'      => (string) $request->query('reason', ''),
                'search'      => trim((string) $request->query('search', '')),
                'for_project' => (int) $project['id'],
            ],
            (int) $request->query('page', 1),
            (int) $request->query('per_page', (int) Config::get('ui.per_page', 30))
        );

        return Response::json([
            'items' => array_map([$this, 'present'], $result['items']),
            'total' => $result['total'],
            'page'  => $result['page'],
            'pages' => $result['pages'],
        ]);
    }

    /**
     * POST /api/v1/suppressions
     *
     * @param array<string, mixed> $project
     */
    public function create(Request $request, array $project): Response
    {
        $email = trim((string) $request->input('email', ''));

        if (!Validator::isEmail($email)) {
            throw new MailerException('Некорректный адрес: ' . $email, [], 422);
        }

        $reason = (string) $request->input('reason', SuppressionRepository::MANUAL);
        if (!in_array($reason, SuppressionRepository::REASONS, true)) {
            throw new MailerException(
                'Неизвестная причина: ' . $reason . '. Допустимые: ' . implode(', ', SuppressionRepository::REASONS),
                [],
                422
            );
        }

        // Ключ проекта закрывает адрес только для себя: чужие письма это не касается
        $id = $this->suppressions->block($email, $reason, 'api', [
            'project_id' => (int) $project['id'],
            'owner_id'   => (int) ($project['owner_id'] ?? 0),
            'note'       => trim((string) $request->input('note', '')),
        ]);

        $row = $this->suppressions->find($id);

        return Response::json($row === null ? ['id' => $id] : $this->present($row), 201);
    }

    /**
     * DELETE /api/v1/suppressions/{email}
     *
     * @param array<string, mixed> $project
     */
    public function delete(Request $request, string $email, array $project): Response
    {
        $removed = $this->suppressions->unblock($email, (int) $project['id']);

        if ($removed === 0) {
            // Закрытый для всех проектов адрес снимает только администратор в панели
            $global = $this->suppressions->isBlocked($email);

            throw new MailerException(
                $global
                    ? 'Адрес ' . $email . ' закрыт для всех проектов, снять его через API нельзя'
                    : 'Адрес не найден в стоп-листе: ' . $email,
                [],
                $global ? 403 : 404
            );
        }

        return Response::json(['email' => SuppressionRepository::normalize($email), 'removed' => $removed]);
    }

    /**
     * Наружу отдаём только то, что нужно клиенту.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'email'      => (string) $row['email'],
            'reason'     => (string) $row['reason'],
            'source'     => (string) $row['source'],
            'project_id' => $row['project_id'] === null ? null : (int) $row['project_id'],
            'note'       => $row['note'] === null ? null : (string) $row['note'],
            'expires_at' => $row['expires_at'] === null ? null : (string) $row['expires_at'],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
