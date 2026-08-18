<?php

declare(strict_types=1);

/**
 * Список писем с фильтрами и массовыми действиями.
 *
 * @var array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int} $result
 * @var array<string, string> $filters
 * @var array<int, array<string, mixed>> $projects
 * @var array<int, array<string, mixed>> $transports
 * @var array<string, int> $counts
 */

use Mailer\Support\Str;
use Mailer\Ui\View;

$statuses = ['queued' => 'в очереди', 'sending' => 'отправляется', 'sent' => 'отправлено', 'failed' => 'ошибка', 'canceled' => 'отменено'];
$sources  = ['api' => 'API', 'sendmail' => 'sendmail', 'smtpd' => 'SMTP-релей', 'cli' => 'CLI', 'ui' => 'панель'];
?>
<h1>Письма <span class="muted small">всего <?= (int) $result['total'] ?></span></h1>

<div class="card">
    <form method="get" action="<?= View::e(View::url('/messages')) ?>">
        <div class="filters">
            <label>
                <span>Статус</span>
                <select name="status">
                    <option value="">любой</option>
                    <?php foreach ($statuses as $value => $label): ?>
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
                        <option value="<?= (int) $project['id'] ?>" <?= (string) $filters['project_id'] === (string) $project['id'] ? 'selected' : '' ?>>
                            <?= View::e($project['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span>Транспорт</span>
                <select name="transport_id">
                    <option value="">любой</option>
                    <?php foreach ($transports as $transport): ?>
                        <option value="<?= (int) $transport['id'] ?>" <?= (string) $filters['transport_id'] === (string) $transport['id'] ? 'selected' : '' ?>>
                            <?= View::e($transport['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span>Источник</span>
                <select name="source">
                    <option value="">любой</option>
                    <?php foreach ($sources as $value => $label): ?>
                        <option value="<?= View::e($value) ?>" <?= $filters['source'] === $value ? 'selected' : '' ?>><?= View::e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span>Метка</span>
                <input type="text" name="tag" value="<?= View::e($filters['tag']) ?>" placeholder="tag">
            </label>

            <label>
                <span>Поиск</span>
                <input type="text" name="search" value="<?= View::e($filters['search']) ?>" placeholder="тема, адрес, id">
            </label>

            <label>
                <span>С даты</span>
                <input type="date" name="date_from" value="<?= View::e(substr($filters['date_from'], 0, 10)) ?>">
            </label>

            <label>
                <span>По дату</span>
                <input type="date" name="date_to" value="<?= View::e(substr($filters['date_to'], 0, 10)) ?>">
            </label>

            <div class="row">
                <button class="primary" type="submit">Показать</button>
                <a class="btn" href="<?= View::e(View::url('/messages')) ?>">Сбросить</a>
            </div>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table>
            <tr>
                <th>Создано</th>
                <th>Статус</th>
                <th>Тема</th>
                <th>Кому</th>
                <th>Транспорт</th>
                <th>Попытки</th>
                <th>Размер</th>
                <th></th>
            </tr>

            <?php foreach ($result['items'] as $row): ?>
                <?php $to = array_column(json_decode((string) ($row['to_json'] ?? '[]'), true) ?: [], 'email'); ?>
                <tr>
                    <td class="nowrap small">
                        <?= View::e(View::date((string) $row['created_at'])) ?><br>
                        <span class="muted"><?= View::e(View::ago((string) $row['created_at'])) ?></span>
                    </td>
                    <td>
                        <span class="badge <?= View::e($row['status']) ?>"><?= View::e($row['status']) ?></span>
                        <?php if (($row['last_error'] ?? null) !== null && $row['status'] !== 'sent'): ?>
                            <div class="small muted" title="<?= View::e((string) $row['last_error']) ?>">
                                <?= View::e(Str::limit((string) $row['last_error'], 40)) ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?= View::e(View::url('/messages/' . $row['id'])) ?>"><?= View::e(Str::limit((string) $row['subject'], 55) ?: '(без темы)') ?></a>
                        <?php if (($row['tag'] ?? null) !== null): ?>
                            <span class="badge muted"><?= View::e((string) $row['tag']) ?></span>
                        <?php endif; ?>
                        <div class="mono muted small"><?= View::e((string) $row['uuid']) ?></div>
                    </td>
                    <td class="mono small"><?= View::e(Str::limit(implode(', ', $to), 40)) ?></td>
                    <td class="small">
                        <?= View::e((string) ($row['transport_used'] ?? '—')) ?><br>
                        <span class="muted"><?= View::e($sources[(string) $row['source']] ?? (string) $row['source']) ?></span>
                    </td>
                    <td class="small"><?= (int) $row['attempts'] ?> / <?= (int) $row['max_attempts'] ?></td>
                    <td class="small nowrap"><?= View::e(Str::bytes((int) $row['size'])) ?></td>
                    <td class="nowrap">
                        <a href="<?= View::e(View::url('/messages/' . $row['id'])) ?>">открыть</a>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if ($result['items'] === []): ?>
                <tr><td colspan="8" class="muted">Ничего не нашлось</td></tr>
            <?php endif; ?>
        </table>
    </div>

    <?php if ($result['pages'] > 1): ?>
        <div class="pagination">
            <?php for ($page = 1; $page <= $result['pages']; $page++): ?>
                <?php if ($page > 3 && $page < $result['pages'] - 2 && abs($page - $result['page']) > 2) {
                    if ($page === 4) {
                        echo '<span class="muted">…</span>';
                    }
                    continue;
                } ?>
                <?php if ($page === $result['page']): ?>
                    <span class="current"><?= $page ?></span>
                <?php else: ?>
                    <a href="<?= View::e(View::url('/messages', array_merge($filters, ['page' => $page]))) ?>"><?= $page ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Массовые действия</h2>
    <form method="post" action="<?= View::e(View::url('/messages/bulk')) ?>" onsubmit="return confirm('Точно применить действие ко всем письмам выбранного статуса?')">
        <div class="row">
            <label style="margin:0">
                <span>Статус</span>
                <select name="status">
                    <?php foreach ($statuses as $value => $label): ?>
                        <option value="<?= View::e($value) ?>" <?= $value === 'failed' ? 'selected' : '' ?>><?= View::e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="margin:0">
                <span>Действие</span>
                <select name="action">
                    <option value="retry">повторить</option>
                    <option value="cancel">отменить</option>
                    <option value="delete">удалить</option>
                </select>
            </label>
            <button class="primary" type="submit" style="align-self:end">Применить</button>
            <span class="muted small" style="align-self:end">за один раз обрабатывается до 500 писем</span>
        </div>
    </form>
</div>
