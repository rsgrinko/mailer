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

use Mailer\Domain\Permission;
use Mailer\Support\Str;
use Mailer\Ui\View;

// Имена проектов берём из того же списка, что и фильтр — лишний запрос не нужен
$projectNames = array_column($projects, 'name', 'id');

$statuses = [
    'queued'     => 'в очереди',
    'sending'    => 'отправляется',
    'sent'       => 'отправлено',
    'failed'     => 'ошибка',
    'canceled'   => 'отменено',
    'suppressed' => 'в стоп-листе',
];
$sources  = ['api' => 'API', 'sendmail' => 'sendmail', 'smtpd' => 'SMTP-релей', 'cli' => 'CLI', 'ui' => 'панель'];
?>
<h1>Письма <span class="muted small">всего <?= (int) $result['total'] ?></span></h1>

<div class="card">
    <form method="get" action="<?= View::e(View::route('ui.messages')) ?>">
        <div class="filters">
            <label>
                <span>Статус</span>
                <select name="status">
                    <option value="">любой</option>
                    <?php foreach ($statuses as $value => $label) { ?>
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
                        <option value="<?= (int) $project['id'] ?>" <?= (string) $filters['project_id'] === (string) $project['id'] ? 'selected' : '' ?>>
                            <?= View::e($project['name']) ?>
                        </option>
                    <?php } ?>
                </select>
            </label>

            <label>
                <span>Транспорт</span>
                <select name="transport_id">
                    <option value="">любой</option>
                    <?php foreach ($transports as $transport) { ?>
                        <option value="<?= (int) $transport['id'] ?>" <?= (string) $filters['transport_id'] === (string) $transport['id'] ? 'selected' : '' ?>>
                            <?= View::e($transport['name']) ?>
                        </option>
                    <?php } ?>
                </select>
            </label>

            <label>
                <span>Источник</span>
                <select name="source">
                    <option value="">любой</option>
                    <?php foreach ($sources as $value => $label) { ?>
                        <option value="<?= View::e($value) ?>" <?= $filters['source'] === $value ? 'selected' : '' ?>><?= View::e($label) ?></option>
                    <?php } ?>
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
        </div>

        <div class="row filter-actions">
            <button class="primary" type="submit">Показать</button>
            <a class="btn" href="<?= View::e(View::route('ui.messages')) ?>">Сбросить</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="list">
            <tr class="head">
                <th>Создано</th>
                <th>Статус</th>
                <th>Тема</th>
                <th>Кому</th>
                <th class="hide-sm">Проект</th>
                <th class="hide-sm">Транспорт</th>
                <th class="hide-sm">Попытки</th>
                <th class="hide-sm">Размер</th>
                <th></th>
            </tr>

            <?php foreach ($result['items'] as $row) { ?>
                <?php $to = array_column(json_decode((string) ($row['to_json'] ?? '[]'), true) ?: [], 'email'); ?>
                <tr>
                    <td class="nowrap small">
                        <?= View::e(View::date((string) $row['created_at'])) ?><br>
                        <span class="muted"><?= View::e(View::ago((string) $row['created_at'])) ?></span>
                    </td>
                    <td>
                        <span class="badge <?= View::e($row['status']) ?>"><?= View::e(View::status((string) $row['status'])) ?></span>
                        <?php if (($row['last_error'] ?? null) !== null && $row['status'] !== 'sent') { ?>
                            <div class="small muted" title="<?= View::e((string) $row['last_error']) ?>">
                                <?= View::e(Str::limit((string) $row['last_error'], 40)) ?>
                            </div>
                        <?php } ?>
                    </td>
                    <td>
                        <a href="<?= View::e(View::route('ui.messages.show', ['id' => $row['id']])) ?>"><?= View::e(Str::limit((string) $row['subject'], 55) ?: '(без темы)') ?></a>
                        <?php if (($row['tag'] ?? null) !== null) { ?>
                            <span class="badge muted"><?= View::e((string) $row['tag']) ?></span>
                        <?php } ?>
                        <div class="mono muted small hide-sm"><?= View::e((string) $row['uuid']) ?></div>
                    </td>
                    <td class="mono small"><?= View::e(Str::limit(implode(', ', $to), 40)) ?></td>
                    <td class="small hide-sm">
                        <?php $projectId = (int) ($row['project_id'] ?? 0); ?>
                        <?php if (isset($projectNames[$projectId]) && View::can(Permission::PROJECTS_VIEW)) { ?>
                            <a href="<?= View::e(View::route('ui.projects.show', ['id' => $projectId])) ?>"><?= View::e($projectNames[$projectId]) ?></a>
                        <?php } elseif (isset($projectNames[$projectId])) { ?>
                            <?= View::e($projectNames[$projectId]) ?>
                        <?php } else { ?>
                            <span class="muted">—</span>
                        <?php } ?>
                    </td>
                    <td class="small hide-sm">
                        <?= View::e((string) ($row['transport_used'] ?? '—')) ?><br>
                        <span class="muted"><?= View::e(View::source((string) $row['source'])) ?></span>
                    </td>
                    <td class="small hide-sm"><?= (int) $row['attempts'] ?> / <?= (int) $row['max_attempts'] ?></td>
                    <td class="small nowrap hide-sm"><?= View::e(Str::bytes((int) $row['size'])) ?></td>
                    <td class="nowrap hide-sm">
                        <a href="<?= View::e(View::route('ui.messages.show', ['id' => $row['id']])) ?>">открыть</a>
                    </td>
                </tr>
            <?php } ?>

            <?php if ($result['items'] === []) { ?>
                <tr><td colspan="9" class="muted">Ничего не нашлось</td></tr>
            <?php } ?>
        </table>
    </div>

    <?= View::partial('pagination', [
        'route'  => 'ui.messages',
        'page'   => $result['page'],
        'pages'  => $result['pages'],
        'params' => $filters,
    ]) ?>
</div>

<?php if (View::can(Permission::MESSAGES_MANAGE) && View::actionsAllowed()) { ?>
<div class="card">
    <h2>Массовые действия</h2>
    <form method="post" action="<?= View::e(View::route('ui.messages.bulk')) ?>" onsubmit="return confirm('Точно применить действие ко всем письмам выбранного статуса?')">
        <?= View::csrf() ?>
        <div class="row">
            <label style="margin:0">
                <span>Статус</span>
                <select name="status">
                    <?php foreach ($statuses as $value => $label) { ?>
                        <option value="<?= View::e($value) ?>" <?= $value === 'failed' ? 'selected' : '' ?>><?= View::e($label) ?></option>
                    <?php } ?>
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
<?php } ?>
