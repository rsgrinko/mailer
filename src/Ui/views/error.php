<?php

declare(strict_types=1);

/**
 * Страница ошибки панели.
 *
 * @var string $message
 */

use Mailer\Ui\View;
?>
<h1>Что-то пошло не так</h1>

<div class="card">
    <p><?= View::e($message) ?></p>
    <a class="btn" href="<?= View::e(View::route('ui.dashboard')) ?>">На главную</a>
</div>
