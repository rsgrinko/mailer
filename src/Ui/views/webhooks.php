<?php

declare(strict_types=1);

/**
 * Очередь и история вебхуков.
 *
 * @var array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int} $result
 * @var array<string, int> $counts
 * @var array<int, array<string, mixed>> $projects
 * @var array<string, string> $filters
 */

use Mailer\Support\Str;
use Mailer\Ui\View;
?>
<div class="row">
    <h1 style="margin:0">Вебхуки</h1>
    <div class="spacer"></div>
    <form method="post" action="<?= View::e(View::route('ui.webhooks.process')) ?>">
        <?= View::csrf() ?>
        <button class="primary" type="submit">Разослать сейчас</button>
    </form>
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
                    <td><?= View::e((string) $item['event']) ?></td>
                    <td><span class="badge <?= View::e((string) $item['status']) ?>"><?= View::e(View::webhookStatus((string) $item['status'])) ?></span></td>
                    <td class="small mono hide-sm"><?= View::e(Str::limit((string) $item['url'], 45)) ?></td>
                    <td class="small hide-sm"><?= (int) $item['attempts'] ?></td>
                    <td class="small hide-sm"><?= View::e((string) ($item['response_code'] ?? '—')) ?></td>
                    <td class="small"><?= View::e(Str::limit((string) ($item['last_error'] ?? ''), 40)) ?></td>
                    <td class="nowrap">
                        <div class="row">
                            <?php if ($item['message_id'] !== null) { ?>
                                <a href="<?= View::e(View::route('ui.messages.show', ['id' => $item['message_id']])) ?>">письмо</a>
                            <?php } ?>
                            <form method="post" action="<?= View::e(View::route('ui.webhooks.action', ['id' => $item['id'], 'action' => 'send'])) ?>">
                                <?= View::csrf() ?>
                                <button type="submit">отправить</button>
                            </form>
                            <form method="post" action="<?= View::e(View::route('ui.webhooks.action', ['id' => $item['id'], 'action' => 'delete'])) ?>">
                                <?= View::csrf() ?>
                                <button class="danger" type="submit">удалить</button>
                            </form>
                        </div>
                        <details>
                            <summary class="muted small">данные</summary>
                            <pre><?= View::e((string) $item['payload']) ?></pre>
                        </details>
                    </td>
                </tr>
            <?php } ?>

            <?php if ($result['items'] === []) { ?>
                <tr><td colspan="8" class="muted">Вебхуков нет. Они появятся, если у проекта задан адрес вебхука.</td></tr>
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
