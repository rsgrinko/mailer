<?php

declare(strict_types=1);

namespace Mailer\Http\Controllers;

use Mailer\Bounce\Unsubscribe;
use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Repository\ProjectRepository;
use Mailer\Repository\SuppressionRepository;
use Mailer\Ui\View;
use Mailer\Webhook\Dispatcher;
use Mailer\Webhook\Event as WebhookEvent;

/**
 * Отписка по ссылке из письма.
 *
 * Адрес публичный: сюда приходит и человек, нажавший ссылку, и почтовый клиент,
 * который отписывает в один шаг (RFC 8058 — POST без открытия страницы). Ключей и
 * сессий здесь нет, всё решает подпись в токене.
 */
final class UnsubscribeController
{
    private SuppressionRepository $suppressions;
    private ProjectRepository $projects;

    public function __construct(SuppressionRepository $suppressions, ProjectRepository $projects)
    {
        $this->suppressions = $suppressions;
        $this->projects     = $projects;
    }

    /**
     * GET /unsubscribe/{token} — страница с кнопкой.
     *
     * Отписывать прямо на GET нельзя: почтовые клиенты открывают ссылки заранее,
     * чтобы показать превью, и человек отпишется, ничего не нажимая.
     */
    public function form(Request $request, string $token): Response
    {
        $data = Unsubscribe::parse($token);

        if ($data === null) {
            return $this->page('Ссылка не подошла', 'Похоже, она устарела или испорчена. Напишите отправителю — он отпишет вас руками.', null, 410);
        }

        return $this->page(
            'Отписка от писем',
            'Больше не хотите получать письма на ' . $data['email'] . '?',
            $token
        );
    }

    /**
     * POST /unsubscribe/{token} — собственно отписка.
     */
    public function submit(Request $request, string $token): Response
    {
        $data = Unsubscribe::parse($token);

        if ($data === null) {
            return $this->page('Ссылка не подошла', 'Похоже, она устарела или испорчена. Напишите отправителю — он отпишет вас руками.', null, 410);
        }

        $project = $data['project_id'] > 0 ? $this->projects->find($data['project_id']) : null;

        $this->suppressions->block($data['email'], SuppressionRepository::UNSUBSCRIBE, 'unsubscribe', [
            'project_id' => $data['project_id'],
            'owner_id'   => (int) ($project['owner_id'] ?? 0),
            'note'       => 'отписка по ссылке из письма',
        ]);

        // Проекту стоит узнать об отписке сразу, а не при следующей выгрузке стоп-листа
        if ($data['project_id'] > 0) {
            (new Dispatcher())->recipient(WebhookEvent::RECIPIENT_UNSUBSCRIBED, $data['project_id'], [
                'email'  => $data['email'],
                'source' => 'link',
            ]);
        }

        return $this->page(
            'Готово',
            'Адрес ' . $data['email'] . ' отписан'
            . ($project === null ? '' : ' от писем проекта «' . $project['name'] . '»') . '.'
        );
    }

    /**
     * Простая страница без панели: сюда попадают чужие люди, а не сотрудники.
     */
    private function page(string $title, string $text, ?string $token = null, int $status = 200): Response
    {
        return Response::html(
            View::partial('unsubscribe', [
                'title' => $title,
                'text'  => $text,
                'token' => $token,
            ]),
            $status
        );
    }
}
