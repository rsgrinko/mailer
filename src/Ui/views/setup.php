<?php

declare(strict_types=1);

/**
 * Первый запуск: пользователей ещё нет, заводим первого.
 *
 * @var string $login
 * @var string $error
 */

use Mailer\Security\Password;
use Mailer\Ui\View;
?>
<div class="auth">
    <div class="card">
        <h1>Первый вход</h1>
        <p class="muted">
            В панели ещё нет ни одного пользователя. Создайте себе учётную запись —
            дальше вход будет по логину и паролю.
        </p>

        <?php if ($error !== ''): ?>
            <div class="flash error"><?= View::e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= View::e(View::url('/setup')) ?>">
            <label>
                <span>Логин</span>
                <input type="text" name="login" value="<?= View::e($login) ?>" autofocus required autocomplete="username">
            </label>

            <label>
                <span>Имя (необязательно)</span>
                <input type="text" name="name" autocomplete="name">
            </label>

            <label>
                <span>Пароль (от <?= Password::MIN_LENGTH ?> символов)</span>
                <input type="password" name="password" required minlength="<?= Password::MIN_LENGTH ?>" autocomplete="new-password">
            </label>

            <label>
                <span>Пароль ещё раз</span>
                <input type="password" name="password_repeat" required minlength="<?= Password::MIN_LENGTH ?>" autocomplete="new-password">
            </label>

            <button class="primary" type="submit" style="width:100%">Создать и войти</button>
        </form>
    </div>
</div>
