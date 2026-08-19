<?php

declare(strict_types=1);

/**
 * Пользователи панели.
 *
 * @var array<int, array<string, mixed>> $items
 * @var array<string, mixed>|null $current
 * @var array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int} $result
 */

use Mailer\Ui\View;
?>
<div class="row">
    <h1 style="margin:0">Пользователи <span class="muted small">всего <?= (int) $result['total'] ?></span></h1>
    <div class="spacer"></div>
    <a class="btn primary" href="<?= View::e(View::route('ui.users.new')) ?>">Добавить пользователя</a>
</div>

<div class="card" style="margin-top:16px">
    <p class="muted small" style="margin-top:0">
        Права у всех одинаковые: вошедший в панель может всё, включая управление пользователями.
    </p>

    <div class="table-wrap">
        <table class="list">
            <tr class="head">
                <th>Логин</th>
                <th class="hide-sm">Имя</th>
                <th class="hide-sm">Последний вход</th>
                <th>Состояние</th>
                <th></th>
            </tr>

            <?php foreach ($items as $item) { ?>
                <?php $isSelf = $current !== null && (int) $current['id'] === (int) $item['id']; ?>
                <tr>
                    <td>
                        <a href="<?= View::e(View::route('ui.users.show', ['id' => $item['id']])) ?>"><?= View::e((string) $item['login']) ?></a>
                        <?php if ($isSelf) { ?>
                            <span class="muted small">— это вы</span>
                        <?php } ?>
                    </td>
                    <td class="small hide-sm"><?= View::e((string) ($item['name'] ?? '—')) ?></td>
                    <td class="small nowrap hide-sm">
                        <?= View::e(View::date($item['last_login_at'] === null ? null : (string) $item['last_login_at'])) ?>
                        <?php if (($item['last_login_ip'] ?? null) !== null) { ?>
                            <div class="muted mono small"><?= View::e((string) $item['last_login_ip']) ?></div>
                        <?php } ?>
                    </td>
                    <td>
                        <?php if ((int) $item['active'] === 1) { ?>
                            <span class="badge sent">активен</span>
                        <?php } else { ?>
                            <span class="badge muted">отключён</span>
                        <?php } ?>
                    </td>
                    <td>
                        <?php if (!$isSelf) { ?>
                            <div class="row">
                                <form method="post" action="<?= View::e(View::route('ui.users.action', ['id' => $item['id'], 'action' => (int) $item['active'] === 1 ? 'disable' : 'enable'])) ?>">
                                    <?= View::csrf() ?>
                                    <button type="submit"><?= (int) $item['active'] === 1 ? 'Отключить' : 'Включить' ?></button>
                                </form>
                                <form method="post" action="<?= View::e(View::route('ui.users.action', ['id' => $item['id'], 'action' => 'delete'])) ?>" onsubmit="return confirm('Удалить пользователя?')">
                                    <?= View::csrf() ?>
                                    <button class="danger" type="submit">Удалить</button>
                                </form>
                            </div>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
        </table>
    </div>

    <?= View::partial('pagination', ['route' => 'ui.users', 'page' => $result['page'], 'pages' => $result['pages']]) ?>
</div>
