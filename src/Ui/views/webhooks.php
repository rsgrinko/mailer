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
    <form method="post" action="<?= View::e(View::url('/webhooks/process')) ?>">
        <button class="primary" type="submit">Разослать сейчас</button>
    </form>
</div>

<div class="card" style="margin-top:16px">
    <form method="get" action="<?= View::e(View::url('/webhooks')) ?>">
        <div class="filters">
            <label>
                <span>Статус</span>
                <select name="status">
                    <option value="">любой</option>
                    <?php foreach (['queued' => 'в очереди', 'delivered' => 'доставлен', 'failed' => 'не доставлен'] as $value => $label): ?>
                        <option value="<?= View::e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>>
                            <?= View::e($label) ?> (<?= (int) ($counts[$value] ?? 0) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Проект</span>
                <select name="project_id">
                    <option value="">любой</option>
                    <?php foreach ($projects as $project): ?>
                        <option value="<?= (int) $project['id'] ?>" <?= $filters['project_id'] === (string) $project['id'] ? 'selected' : '' ?>>
                            <?= View::e($project['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="row">
                <button class="primary" type="submit">Показать</button>
                <a class="btn" href="<?= View::e(View::url('/webhooks')) ?>">Сбросить</a>
            </div>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table>
            <tr>
                <th>Создан</th><th>Событие</th><th>Статус</th><th>Адрес</th>
                <th>Попытки</th><th>Ответ</th><th>Ошибка</th><th></th>
            </tr>

            <?php foreach ($result['items'] as $item): ?>
                <tr>
                    <td class="small nowrap"><?= View::e(View::date((string) $item['created_at'])) ?></td>
                    <td><?= View::e((string) $item['event']) ?></td>
                    <td><span class="badge <?= View::e((string) $item['status']) ?>"><?= View::e((string) $item['status']) ?></span></td>
                    <td class="small mono"><?= View::e(Str::limit((string) $item['url'], 45)) ?></td>
                    <td class="small"><?= (int) $item['attempts'] ?></td>
                    <td class="small"><?= View::e((string) ($item['response_code'] ?? '—')) ?></td>
                    <td class="small"><?= View::e(Str::limit((string) ($item['last_error'] ?? ''), 40)) ?></td>
                    <td class="nowrap">
                        <div class="row">
                            <?php if ($item['message_id'] !== null): ?>
                                <a href="<?= View::e(View::url('/messages/' . $item['message_id'])) ?>">письмо</a>
                            <?php endif; ?>
                            <form method="post" action="<?= View::e(View::url('/webhooks/' . $item['id'] . '/send')) ?>">
                                <button type="submit">отправить</button>
                            </form>
                            <form method="post" action="<?= View::e(View::url('/webhooks/' . $item['id'] . '/delete')) ?>">
                                <button class="danger" type="submit">удалить</button>
                            </form>
                        </div>
                        <details>
                            <summary class="muted small">данные</summary>
                            <pre><?= View::e((string) $item['payload']) ?></pre>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if ($result['items'] === []): ?>
                <tr><td colspan="8" class="muted">Вебхуков нет. Они появятся, если у проекта задан адрес вебхука.</td></tr>
            <?php endif; ?>
        </table>
    </div>

    <?php if ($result['pages'] > 1): ?>
        <div class="pagination">
            <?php for ($page = 1; $page <= $result['pages']; $page++): ?>
                <?php if ($page === $result['page']): ?>
                    <span class="current"><?= $page ?></span>
                <?php else: ?>
                    <a href="<?= View::e(View::url('/webhooks', array_merge($filters, ['page' => $page]))) ?>"><?= $page ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>
