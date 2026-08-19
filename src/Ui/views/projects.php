<?php

declare(strict_types=1);

/**
 * Список проектов (клиентов API).
 *
 * @var array<int, array<string, mixed>> $items
 * @var array<int, array{hour: int, day: int}> $usage
 */

use Mailer\Security\ApiKey;
use Mailer\Ui\View;
?>
<div class="row">
    <h1 style="margin:0">Проекты</h1>
    <div class="spacer"></div>
    <a class="btn primary" href="<?= View::e(View::url('/projects/new')) ?>">Создать проект</a>
</div>

<div class="card" style="margin-top:16px">
    <div class="table-wrap">
        <table>
            <tr>
                <th>Проект</th>
                <th>Ключ</th>
                <th>Отправитель</th>
                <th>За час</th>
                <th>За сутки</th>
                <th>Вебхук</th>
                <th>Состояние</th>
            </tr>

            <?php foreach ($items as $item) { ?>
                <tr>
                    <td>
                        <a href="<?= View::e(View::url('/projects/' . $item['id'])) ?>"><?= View::e($item['name']) ?></a>
                        <?php if (($item['description'] ?? null) !== null) { ?>
                            <div class="muted small"><?= View::e((string) $item['description']) ?></div>
                        <?php } ?>
                    </td>
                    <td class="mono small"><?= View::e(ApiKey::mask((string) $item['api_key_prefix'])) ?></td>
                    <td class="small"><?= View::e((string) ($item['default_from_email'] ?? '—')) ?></td>
                    <td class="small">
                        <?= (int) ($usage[(int) $item['id']]['hour'] ?? 0) ?><?= (int) $item['rate_limit_hour'] > 0 ? ' / ' . (int) $item['rate_limit_hour'] : '' ?>
                    </td>
                    <td class="small">
                        <?= (int) ($usage[(int) $item['id']]['day'] ?? 0) ?><?= (int) $item['rate_limit_day'] > 0 ? ' / ' . (int) $item['rate_limit_day'] : '' ?>
                    </td>
                    <td class="small mono"><?= View::e((string) ($item['webhook_url'] ?? '—')) ?></td>
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
</div>
