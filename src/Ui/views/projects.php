<?php

declare(strict_types=1);

/**
 * Список проектов (клиентов API).
 *
 * @var array<int, array<string, mixed>> $items
 * @var array<int, array{hour: int, day: int}> $usage
 * @var array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int} $result
 */

use Mailer\Domain\Permission;
use Mailer\Security\ApiKey;
use Mailer\Ui\View;
?>
<div class="row">
    <h1 style="margin:0">Проекты <span class="muted small">всего <?= (int) $result['total'] ?></span></h1>
    <div class="spacer"></div>
    <?php if (View::can(Permission::PROJECTS_MANAGE)) { ?>
        <a class="btn primary" href="<?= View::e(View::route('ui.projects.new')) ?>">Создать проект</a>
    <?php } ?>
</div>

<div class="card" style="margin-top:16px">
    <div class="table-wrap">
        <table class="list">
            <tr class="head">
                <th>Проект</th>
                <th>Ключ</th>
                <th class="hide-sm">Отправитель</th>
                <th class="hide-sm">За час</th>
                <th class="hide-sm">За сутки</th>
                <th class="hide-sm">Вебхук</th>
                <th>Состояние</th>
            </tr>

            <?php foreach ($items as $item) { ?>
                <tr>
                    <td>
                        <a href="<?= View::e(View::route('ui.projects.show', ['id' => $item['id']])) ?>"><?= View::e($item['name']) ?></a>
                        <?php if (($item['description'] ?? null) !== null) { ?>
                            <div class="muted small"><?= View::e((string) $item['description']) ?></div>
                        <?php } ?>
                    </td>
                    <td class="mono small"><?= View::e(ApiKey::mask((string) $item['api_key_prefix'])) ?></td>
                    <td class="small hide-sm"><?= View::e((string) ($item['default_from_email'] ?? '—')) ?></td>
                    <td class="small hide-sm">
                        <?= (int) ($usage[(int) $item['id']]['hour'] ?? 0) ?><?= (int) $item['rate_limit_hour'] > 0 ? ' / ' . (int) $item['rate_limit_hour'] : '' ?>
                    </td>
                    <td class="small hide-sm">
                        <?= (int) ($usage[(int) $item['id']]['day'] ?? 0) ?><?= (int) $item['rate_limit_day'] > 0 ? ' / ' . (int) $item['rate_limit_day'] : '' ?>
                    </td>
                    <td class="small mono hide-sm"><?= View::e((string) ($item['webhook_url'] ?? '—')) ?></td>
                    <td>
                        <?php if ((int) $item['active'] === 1) { ?>
                            <span class="badge sent">активен</span>
                        <?php } else { ?>
                            <span class="badge muted">отключён</span>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>

            <?php if ($items === []) { ?>
                <tr><td colspan="7" class="muted">Проектов нет. Создайте первый — вместе с ним выдастся API-ключ.</td></tr>
            <?php } ?>
        </table>
    </div>

    <?= View::partial('pagination', ['route' => 'ui.projects', 'page' => $result['page'], 'pages' => $result['pages']]) ?>
</div>
