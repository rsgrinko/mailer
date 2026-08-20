<?php

declare(strict_types=1);

/**
 * Страница ошибки панели.
 *
 * @var string $message
 * @var string $heading заголовок: у отказа в доступе он не про поломку
 */

use Mailer\Ui\View;

$heading = $heading ?? 'Что-то пошло не так';
?>
<h1><?= View::e($heading) ?></h1>

<div class="card">
    <p><?= View::e($message) ?></p>
    <a class="btn" href="<?= View::e(View::route('ui.dashboard')) ?>">На главную</a>
</div>
