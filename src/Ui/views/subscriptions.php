<?php

declare(strict_types=1);

/**
 * Вебхуки проектов: кому и о чём сервис сообщает.
 *
 * @var array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int} $result
 * @var array<int, array<string, mixed>> $projects
 * @var array<string, string> $filters
 */

use Mailer\Domain\Permission;
use Mailer\Support\Str;
use Mailer\Ui\View;
use Mailer\Webhook\Event as WebhookEvent;

$projectName = static function (int $id) use ($projects): string {
    foreach ($projects as $project) {
        if ((int) $project['id'] === $id) {
            return (string) $project['name'];
        }
    }

    return '';
};
?>
<div class="row">
    <h1 style="margin:0">Вебхуки проектов <span class="muted small">всего <?= (int) $result['total'] ?></span></h1>
    <div class="spacer"></div>
    <a class="btn" href="<?= View::e(View::route('ui.webhooks')) ?>">Доставки</a>
    <?php if (View::can(Permission::WEBHOOKS_MANAGE)) { ?>
        <a class="btn primary" href="<?= View::e(View::route('ui.subscriptions.new')) ?>">Новый вебхук</a>
    <?php } ?>
</div>

<div class="card" style="margin-top:16px">
    <form method="get" action="<?= View::e(View::route('ui.subscriptions')) ?>">
        <div class="filters">
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
            <a class="btn" href="<?= View::e(View::route('ui.subscriptions')) ?>">Сбросить</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="list">
            <tr class="head">
                <th>Адрес</th><th class="hide-sm">Проект</th><th>События</th>
                <th class="hide-sm">Последняя доставка</th><th>Состояние</th><th></th>
            </tr>

            <?php foreach ($result['items'] as $item) { ?>
                <tr>
                    <td>
                        <a class="mono small" href="<?= View::e(View::route('ui.subscriptions.show', ['id' => $item['id']])) ?>"><?= View::e(Str::limit((string) $item['url'], 45)) ?></a>
                        <?php if (($item['name'] ?? null) !== null) { ?>
                            <div class="muted small"><?= View::e((string) $item['name']) ?></div>
                        <?php } ?>
                    </td>
                    <td class="small hide-sm"><?= View::e($projectName((int) $item['project_id']) ?: '—') ?></td>
                    <td class="small"><?= View::e($item['events'] === [] ? 'все' : count($item['events']) . ' из ' . count(WebhookEvent::all())) ?></td>
                    <td class="small hide-sm">
                        <?php if (($item['last_delivery_at'] ?? null) === null) { ?>
                            <span class="muted">не было</span>
                        <?php } else { ?>
                            <?= View::e(View::ago((string) $item['last_delivery_at'])) ?>
                            <span class="badge <?= View::e((string) ($item['last_status'] ?? '')) ?>"><?= View::e(View::webhookStatus((string) ($item['last_status'] ?? ''))) ?></span>
                        <?php } ?>
                    </td>
                    <td class="small">
                        <?php if ((int) $item['active'] === 1) { ?>
                            <span class="badge sent">включён</span>
                        <?php } else { ?>
                            <span class="badge canceled">выключен</span>
                        <?php } ?>
                        <?php if ((int) $item['failures'] > 0) { ?>
                            <div class="muted small">неудач подряд: <?= (int) $item['failures'] ?></div>
                        <?php } ?>
                    </td>
                    <td class="nowrap">
                        <a href="<?= View::e(View::route('ui.webhooks', ['subscription_id' => (int) $item['id']])) ?>">доставки</a>
                    </td>
                </tr>
            <?php } ?>

            <?php if ($result['items'] === []) { ?>
                <tr><td colspan="6" class="muted">Вебхуков нет. Заведите первый — и проект узнает о судьбе своих писем.</td></tr>
            <?php } ?>
        </table>
    </div>

    <?= View::partial('pagination', [
        'route'  => 'ui.subscriptions',
        'page'   => $result['page'],
        'pages'  => $result['pages'],
        'params' => $filters,
    ]) ?>
</div>
