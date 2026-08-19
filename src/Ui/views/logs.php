<?php

declare(strict_types=1);

/**
 * Просмотр файлов логов.
 *
 * @var array<int, array{name: string, path: string, size: int, mtime: int}> $files
 * @var string $current
 * @var int $lines
 * @var string $content
 */

use Mailer\Support\Str;
use Mailer\Ui\View;
?>
<h1>Логи</h1>

<div class="card">
    <form method="get" action="<?= View::e(View::url('/logs')) ?>">
        <div class="row">
            <label style="margin:0; flex:2">
                <span>Файл</span>
                <select name="file">
                    <?php foreach ($files as $file) { ?>
                        <option value="<?= View::e($file['name']) ?>" <?= $current === $file['name'] ? 'selected' : '' ?>>
                            <?= View::e($file['name']) ?> — <?= View::e(Str::bytes($file['size'])) ?>
                        </option>
                    <?php } ?>
                </select>
            </label>
            <label style="margin:0; flex:1">
                <span>Строк с конца</span>
                <input type="number" name="lines" value="<?= (int) $lines ?>">
            </label>
            <button class="primary" type="submit" style="align-self:end">Показать</button>
        </div>
    </form>
</div>

<div class="card">
    <?php if ($files === []) { ?>
        <p class="muted">Логов пока нет.</p>
    <?php } else { ?>
        <pre style="max-height:70vh; overflow:auto"><?= View::e($content !== '' ? $content : 'Файл пуст') ?></pre>
    <?php } ?>
</div>
