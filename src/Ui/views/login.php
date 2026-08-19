<?php

declare(strict_types=1);

/**
 * Форма входа в панель.
 *
 * @var string $login
 * @var string $error
 * @var string $next
 */

use Mailer\Ui\View;
?>
<div class="auth">
    <div class="card">
        <h1>Вход в панель</h1>

        <?php if ($error !== ''): ?>
            <div class="flash error"><?= View::e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= View::e(View::url('/login')) ?>">
            <input type="hidden" name="next" value="<?= View::e($next) ?>">

            <label>
                <span>Логин</span>
                <input type="text" name="login" value="<?= View::e($login) ?>" autofocus required autocomplete="username">
            </label>

            <label>
                <span>Пароль</span>
                <input type="password" name="password" required autocomplete="current-password">
            </label>

            <button class="primary" type="submit" style="width:100%">Войти</button>
        </form>
    </div>
</div>
