<?php

declare(strict_types=1);

/**
 * Стоп-лист адресов.
 *
 * @var array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int} $result
 * @var array<string, string> $filters
 * @var array<string, int> $counts
 * @var array<int, array<string, mixed>> $projects
 */

use Mailer\Domain\Permission;
use Mailer\Repository\SuppressionRepository;
use Mailer\Ui\View;

$reasons = [
    SuppressionRepository::BOUNCE      => 'отказ сервера',
    SuppressionRepository::COMPLAINT   => 'жалоба на спам',
    SuppressionRepository::UNSUBSCRIBE => 'отписка',
    SuppressionRepository::MANUAL      => 'закрыт вручную',
];

$projectNames = [];
foreach ($projects as $project) {
    $projectNames[(int) $project['id']] = (string) $project['name'];
}
?>
<div class="row">
    <h1 style="margin:0">Стоп-лист</h1>
    <div class="spacer"></div>
    <span class="muted small">адресов: <?= (int) $result['total'] ?></span>
</div>

<p class="muted small">Письма этим адресам не отправляются: они отсеиваются ещё на приёме.
Адрес попадает сюда сам, если сервер получателя ответил «нет такого ящика», или вручную.</p>

<?php if (View::can(Permission::SUPPRESSIONS_MANAGE)) { ?>
    <div class="card" style="margin-top:16px">
        <h2>Закрыть адрес</h2>
        <form method="post" action="<?= View::e(View::route('ui.suppressions.store')) ?>">
            <?= View::csrf() ?>
            <div class="row">
                <label style="flex:2">
                    <span>Адрес</span>
                    <input type="email" name="email" required placeholder="ivan@example.com">
                </label>
                <label style="flex:1">
                    <span>Причина</span>
                    <select name="reason">
                        <?php foreach ($reasons as $value => $label) { ?>
                            <option value="<?= View::e($value) ?>" <?= $value === SuppressionRepository::MANUAL ? 'selected' : '' ?>>
                                <?= View::e($label) ?>
                            </option>
                        <?php } ?>
                    </select>
                </label>
                <label style="flex:1">
                    <span>Проект</span>
                    <select name="project_id">
                        <option value="">все проекты</option>
                        <?php foreach ($projects as $project) { ?>
                            <option value="<?= (int) $project['id'] ?>"><?= View::e($project['name']) ?></option>
                        <?php } ?>
                    </select>
                </label>
            </div>
            <label>
                <span>Заметка</span>
                <input type="text" name="note" placeholder="почему закрыли">
            </label>
            <button class="primary" type="submit">Закрыть адрес</button>
        </form>
    </div>
<?php } ?>

<div class="card">
    <form method="get" action="<?= View::e(View::route('ui.suppressions')) ?>">
        <div class="filters">
            <label>
                <span>Причина</span>
                <select name="reason">
                    <option value="">любая</option>
                    <?php foreach ($reasons as $value => $label) { ?>
                        <option value="<?= View::e($value) ?>" <?= $filters['reason'] === $value ? 'selected' : '' ?>>
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
            <label>
                <span>Поиск</span>
                <input type="text" name="search" value="<?= View::e($filters['search']) ?>" placeholder="часть адреса">
            </label>
        </div>

        <div class="row filter-actions">
            <button class="primary" type="submit">Показать</button>
            <a class="btn" href="<?= View::e(View::route('ui.suppressions')) ?>">Сбросить</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="list">
            <tr class="head">
                <th>Адрес</th><th>Причина</th><th>Охват</th><th class="hide-sm">Откуда</th>
                <th class="hide-sm">Заметка</th><th>Когда</th><th></th>
            </tr>

            <?php foreach ($result['items'] as $item) { ?>
                <tr>
                    <td class="mono"><?= View::e((string) $item['email']) ?></td>
                    <td><span class="badge muted"><?= View::e($reasons[(string) $item['reason']] ?? (string) $item['reason']) ?></span></td>
                    <td class="small">
                        <?php if ($item['project_id'] === null) { ?>
                            все проекты
                        <?php } else { ?>
                            <?= View::e($projectNames[(int) $item['project_id']] ?? ('проект #' . (int) $item['project_id'])) ?>
                        <?php } ?>
                    </td>
                    <td class="small hide-sm"><?= View::e((string) $item['source']) ?></td>
                    <td class="small hide-sm"><?= View::e((string) ($item['note'] ?? '—')) ?></td>
                    <td class="small nowrap"><?= View::e(View::date((string) $item['created_at'])) ?></td>
                    <td class="nowrap">
                        <div class="row">
                            <?php if ($item['message_id'] !== null) { ?>
                                <a href="<?= View::e(View::route('ui.messages.show', ['id' => $item['message_id']])) ?>">письмо</a>
                            <?php } ?>
                            <?php if (View::can(Permission::SUPPRESSIONS_MANAGE)) { ?>
                                <form method="post" action="<?= View::e(View::route('ui.suppressions.delete', ['id' => $item['id']])) ?>">
                                    <?= View::csrf() ?>
                                    <button type="submit">открыть</button>
                                </form>
                            <?php } ?>
                        </div>
                    </td>
                </tr>
            <?php } ?>

            <?php if ($result['items'] === []) { ?>
                <tr><td colspan="7" class="muted">Стоп-лист пуст — все адреса открыты.</td></tr>
            <?php } ?>
        </table>
    </div>

    <?= View::partial('pagination', [
        'route'  => 'ui.suppressions',
        'page'   => $result['page'],
        'pages'  => $result['pages'],
        'params' => $filters,
    ]) ?>
</div>
