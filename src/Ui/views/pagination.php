<?php

declare(strict_types=1);

/**
 * Постраничная навигация. Общая для всех списков панели.
 *
 * @var string $route имя маршрута раздела, например 'ui.messages'
 * @var int $page текущая страница
 * @var int $pages сколько всего страниц
 * @var array<string, mixed> $params что дописать в ссылку (фильтры)
 */

use Mailer\Ui\View;

$params = $params ?? [];

if ($pages <= 1) {
    return;
}
?>
<div class="pagination">
    <?php for ($number = 1; $number <= $pages; $number++) { ?>
        <?php
        // Середину длинного списка сворачиваем в многоточие
        if ($number > 3 && $number < $pages - 2 && abs($number - $page) > 2) {
            if ($number === 4) {
                echo '<span class="muted">…</span>';
            }

            continue;
        }
        ?>
        <?php if ($number === $page) { ?>
            <span class="current"><?= $number ?></span>
        <?php } else { ?>
            <a href="<?= View::e(View::route($route, array_merge($params, ['page' => $number]))) ?>"><?= $number ?></a>
        <?php } ?>
    <?php } ?>
</div>
