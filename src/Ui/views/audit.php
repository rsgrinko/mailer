<?php

declare(strict_types=1);

/**
 * Журнал действий панели.
 *
 * @var array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int} $result
 * @var array<string, string> $filters
 * @var array<int, string> $entities
 * @var array<int, array{user_id: int, user_login: string}> $users
 */

use Mailer\Repository\AuditRepository;
use Mailer\Ui\View;

$actions = [
    AuditRepository::CREATED => 'создание',
    AuditRepository::UPDATED => 'изменение',
    AuditRepository::DELETED => 'удаление',
    AuditRepository::ACTION  => 'действие',
    AuditRepository::LOGIN   => 'вход',
    AuditRepository::LOGOUT  => 'выход',
];
?>
<div class="row">
    <h1 style="margin:0">Журнал действий</h1>
    <div class="spacer"></div>
    <span class="muted small">записей: <?= (int) $result['total'] ?></span>
</div>

<div class="card" style="margin-top:16px">
    <form method="get" action="<?= View::e(View::route('ui.audit')) ?>">
        <div class="filters">
            <label>
                <span>Пользователь</span>
                <select name="user_id">
                    <option value="">любой</option>
                    <?php foreach ($users as $item) { ?>
                        <option value="<?= (int) $item['user_id'] ?>" <?= $filters['user_id'] === (string) $item['user_id'] ? 'selected' : '' ?>>
                            <?= View::e($item['user_login'] !== '' ? $item['user_login'] : 'без входа') ?>
                        </option>
                    <?php } ?>
                </select>
            </label>
            <label>
                <span>Раздел</span>
                <select name="entity">
                    <option value="">любой</option>
                    <?php foreach ($entities as $entity) { ?>
                        <option value="<?= View::e($entity) ?>" <?= $filters['entity'] === $entity ? 'selected' : '' ?>>
                            <?= View::e(View::auditEntity($entity)) ?>
                        </option>
                    <?php } ?>
                </select>
            </label>
            <label>
                <span>Что сделали</span>
                <select name="action">
                    <option value="">любое</option>
                    <?php foreach ($actions as $value => $label) { ?>
                        <option value="<?= View::e($value) ?>" <?= $filters['action'] === $value ? 'selected' : '' ?>>
                            <?= View::e($label) ?>
                        </option>
                    <?php } ?>
                </select>
            </label>
            <label>
                <span>Поиск</span>
                <input type="text" name="search" value="<?= View::e($filters['search']) ?>" placeholder="по описанию или логину">
            </label>
        </div>

        <div class="row filter-actions">
            <button class="primary" type="submit">Показать</button>
            <a class="btn" href="<?= View::e(View::route('ui.audit')) ?>">Сбросить</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="list">
            <tr class="head">
                <th>Когда</th><th>Кто</th><th>Что сделали</th><th>Раздел</th><th>Описание</th><th class="hide-sm">Адрес</th>
            </tr>

            <?php foreach ($result['items'] as $item) { ?>
                <tr>
                    <td class="small nowrap"><?= View::e(View::date((string) $item['created_at'])) ?></td>
                    <td><?= View::e((string) ($item['user_login'] ?? '') !== '' ? (string) $item['user_login'] : 'без входа') ?></td>
                    <td class="small"><?= View::e($actions[(string) $item['action']] ?? (string) $item['action']) ?></td>
                    <td class="small">
                        <?= View::e(View::auditEntity((string) $item['entity'])) ?>
                        <?php if ($item['entity_id'] !== null) { ?>
                            <span class="muted">#<?= (int) $item['entity_id'] ?></span>
                        <?php } ?>
                    </td>
                    <td class="small"><?= View::e((string) ($item['summary'] ?? '—')) ?></td>
                    <td class="small mono hide-sm"><?= View::e((string) ($item['ip'] ?? '—')) ?></td>
                </tr>
            <?php } ?>

            <?php if ($result['items'] === []) { ?>
                <tr><td colspan="6" class="muted">Записей нет. Здесь появится всё, что меняют в панели.</td></tr>
            <?php } ?>
        </table>
    </div>

    <?= View::partial('pagination', [
        'route'  => 'ui.audit',
        'page'   => $result['page'],
        'pages'  => $result['pages'],
        'params' => $filters,
    ]) ?>
</div>
