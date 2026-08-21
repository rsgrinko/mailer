<?php

declare(strict_types=1);

/**
 * Доставки вебхуков: очередь и история.
 *
 * @var array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int} $result
 * @var array<string, int> $counts
 * @var array<int, array<string, mixed>> $projects
 * @var array<string, string> $filters
 */

use Mailer\Domain\Permission;
use Mailer\Support\Str;
use Mailer\Ui\View;
use Mailer\Webhook\Event as WebhookEvent;
?>
<div class="row">
    <h1 style="margin:0">Доставки <span class="muted small">всего <?= (int) $result['total'] ?></span></h1>
    <div class="spacer"></div>
    <a class="btn" href="<?= View::e(View::route('ui.subscriptions')) ?>">Вебхуки проектов</a>
    <?php if (View::can(Permission::WEBHOOKS_MANAGE)) { ?>
        <form method="post" action="<?= View::e(View::route('ui.webhooks.process')) ?>">
            <?= View::csrf() ?>
            <button class="primary" type="submit">Разослать сейчас</button>
        </form>
    <?php } ?>
</div>

<div class="card" style="margin-top:16px">
    <form method="get" action="<?= View::e(View::route('ui.webhooks')) ?>">
        <div class="filters">
            <label>
                <span>Статус</span>
                <select name="status">
                    <option value="">любой</option>
                    <?php foreach (['queued' => 'в очереди', 'delivered' => 'доставлен', 'failed' => 'не доставлен'] as $value => $label) { ?>
                        <option value="<?= View::e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>>
                            <?= View::e($label) ?> (<?= (int) ($counts[$value] ?? 0) ?>)
                        </option>
                    <?php } ?>
                </select>
            </label>
            <label>
                <span>Событие</span>
                <select name="event">
                    <option value="">любое</option>
                    <?php foreach (WebhookEvent::LABELS as $code => $label) { ?>
                        <option value="<?= View::e($code) ?>" <?= $filters['event'] === $code ? 'selected' : '' ?>>
                            <?= View::e($label) ?>
                        </option>
                    <?php } ?>
                </select>
            </label>
            <label>
                <span>Проект</span>
                <select name="project_id">
                    <option value="">любой</option>
                    <?php foreach ($projects as $project) { ?>
                        <option value="<?= (int) $project['id'] ?>" <?= $filters['project_id'] === (string) $project['id'] ? 'selected' : '' ?>>
                            <?= View::e($project['name']) ?>
                        </option>
                    <?php } ?>
                </select>
            </label>
        </div>

        <div class="row filter-actions">
            <button class="primary" type="submit">Показать</button>
            <a class="btn" href="<?= View::e(View::route('ui.webhooks')) ?>">Сбросить</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="list">
            <tr class="head">
                <th>Создан</th><th>Событие</th><th>Статус</th><th class="hide-sm">Адрес</th>
                <th class="hide-sm">Попытки</th><th class="hide-sm">Ответ</th><th>Ошибка</th><th></th>
            </tr>

            <?php foreach ($result['items'] as $item) { ?>
                <tr>
                    <td class="small nowrap"><?= View::e(View::date((string) $item['created_at'])) ?></td>
                    <td class="small"><?= View::e(WebhookEvent::label((string) $item['event'])) ?></td>
                    <td><span class="badge <?= View::e((string) $item['status']) ?>"><?= View::e(View::webhookStatus((string) $item['status'])) ?></span></td>
                    <td class="small mono hide-sm"><?= View::e(Str::limit((string) $item['url'], 45)) ?></td>
                    <td class="small hide-sm"><?= (int) $item['attempts'] ?></td>
                    <td class="small hide-sm">
                        <?= View::e((string) ($item['response_code'] ?? '—')) ?>
                        <?php if (($item['duration_ms'] ?? null) !== null) { ?>
                            <span class="muted">/ <?= (int) $item['duration_ms'] ?> мс</span>
                        <?php } ?>
                    </td>
                    <td class="small"><?= View::e(Str::limit((string) ($item['last_error'] ?? ''), 40)) ?></td>
                    <td class="nowrap">
                        <a href="<?= View::e(View::route('ui.webhooks.show', ['id' => $item['id']])) ?>">открыть</a>
                    </td>
                </tr>
            <?php } ?>

            <?php if ($result['items'] === []) { ?>
                <tr><td colspan="8" class="muted">Доставок нет. Они появятся, когда у проекта будет хотя бы один вебхук.</td></tr>
            <?php } ?>
        </table>
    </div>

    <?= View::partial('pagination', [
        'route'  => 'ui.webhooks',
        'page'   => $result['page'],
        'pages'  => $result['pages'],
        'params' => $filters,
    ]) ?>
</div>
