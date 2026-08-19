<?php

declare(strict_types=1);

/**
 * Список шаблонов писем.
 *
 * @var array<int, array<string, mixed>> $items
 */

use Mailer\Support\Str;
use Mailer\Ui\View;
?>
<div class="row">
    <h1 style="margin:0">Шаблоны</h1>
    <div class="spacer"></div>
    <a class="btn primary" href="<?= View::e(View::url('/templates/new')) ?>">Создать шаблон</a>
</div>

<div class="card" style="margin-top:16px">
    <div class="table-wrap">
        <table>
            <tr><th>Имя</th><th>Тема</th><th>Переменные</th><th>Изменён</th></tr>

            <?php foreach ($items as $item) { ?>
                <tr>
                    <td>
                        <a href="<?= View::e(View::url('/templates/' . $item['id'])) ?>"><?= View::e($item['name']) ?></a>
                        <?php if (($item['description'] ?? null) !== null) { ?>
                            <div class="muted small"><?= View::e((string) $item['description']) ?></div>
                        <?php } ?>
                    </td>
                    <td><?= View::e(Str::limit((string) ($item['subject'] ?? ''), 50)) ?></td>
                    <td class="mono small"><?= View::e(implode(', ', (array) $item['variables'])) ?></td>
                    <td class="small"><?= View::e(View::date((string) $item['updated_at'])) ?></td>
                </tr>
            <?php } ?>

            <?php if ($items === []) { ?>
                <tr><td colspan="4" class="muted">Шаблонов нет</td></tr>
            <?php } ?>
        </table>
    </div>
</div>

<div class="card">
    <h2>Как пользоваться</h2>
    <p class="small">
        В теме и телах письма можно ставить переменные: <span class="mono">{{ name }}</span> —
        с экранированием HTML, <span class="mono">{{{ html_block }}}</span> — как есть.
        Доступны вложенные значения: <span class="mono">{{ user.email }}</span>.
    </p>
    <p class="small">
        При отправке через API укажите <span class="mono">"template": "имя"</span> и
        <span class="mono">"template_data": { … }</span>.
    </p>
</div>
