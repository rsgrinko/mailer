<?php

declare(strict_types=1);

/**
 * Список «поле — значение». Им показываются карточки тем, кто может только смотреть:
 * поля, которые нельзя менять, незачем показывать полями ввода.
 *
 * @var array<string, mixed> $rows подпись => значение (null и пустое станут прочерком)
 */

use Mailer\Ui\View;
?>
<dl class="props">
    <?php foreach ($rows as $label => $value) { ?>
        <?php
        if (is_bool($value)) {
            $value = $value ? 'да' : 'нет';
        }

        $value = trim((string) $value);
        ?>
        <dt><?= View::e($label) ?></dt>
        <dd><?= $value === '' ? '<span class="muted">—</span>' : View::e($value) ?></dd>
    <?php } ?>
</dl>
