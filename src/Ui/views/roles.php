<?php

declare(strict_types=1);

/**
 * Роли панели: кому что доступно.
 *
 * @var array<int, array<string, mixed>> $items
 * @var array<int, int> $usage сколько пользователей с этой ролью
 * @var array<string, array<string, string>> $groups права по разделам
 * @var array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int} $result
 */

use Mailer\Domain\Permission;
use Mailer\Ui\View;
?>
<div class="row">
    <h1 style="margin:0">Роли <span class="muted small">всего <?= (int) $result['total'] ?></span></h1>
    <div class="spacer"></div>
    <a class="btn primary" href="<?= View::e(View::route('ui.roles.new')) ?>">Добавить роль</a>
</div>

<div class="card" style="margin-top:16px">
    <p class="muted small" style="margin-top:0">
        Роль — это набор прав. Пользователю выдаётся одна роль, своих галочек у него нет:
        поменяли роль — поменялось у всех, кому она выдана.
    </p>

    <div class="table-wrap">
        <table class="list">
            <tr class="head">
                <th>Название</th>
                <th class="hide-sm">Описание</th>
                <th>Права</th>
                <th>Людей</th>
                <th></th>
            </tr>

            <?php foreach ($items as $item) { ?>
                <?php $permissions = (array) $item['permissions']; ?>
                <tr>
                    <td>
                        <a href="<?= View::e(View::route('ui.roles.show', ['id' => $item['id']])) ?>"><?= View::e((string) $item['name']) ?></a>
                        <?php if ((int) $item['is_system'] === 1) { ?>
                            <span class="badge muted">встроенная</span>
                        <?php } ?>
                    </td>
                    <td class="small hide-sm"><?= View::e((string) ($item['description'] ?? '—')) ?></td>
                    <td class="small">
                        <?php if (in_array(Permission::DATA_ALL, $permissions, true)) { ?>
                            <span class="badge sent">все данные</span>
                        <?php } ?>
                        <?= count($permissions) ?> из <?= count(Permission::all()) ?>
                    </td>
                    <td class="small"><?= (int) ($usage[(int) $item['id']] ?? 0) ?></td>
                    <td>
                        <?php if ((int) $item['is_system'] !== 1 && (int) ($usage[(int) $item['id']] ?? 0) === 0) { ?>
                            <form method="post" action="<?= View::e(View::route('ui.roles.action', ['id' => $item['id'], 'action' => 'delete'])) ?>" onsubmit="return confirm('Удалить роль?')">
                                <?= View::csrf() ?>
                                <button class="danger" type="submit">Удалить</button>
                            </form>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
        </table>
    </div>

    <?= View::partial('pagination', ['route' => 'ui.roles', 'page' => $result['page'], 'pages' => $result['pages']]) ?>
</div>
