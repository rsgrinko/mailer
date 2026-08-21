<?php

declare(strict_types=1);

/**
 * Форма входа в панель.
 *
 * @var string $login
 * @var string $error
 * @var string $next
 * @var bool   $remember галка «запомнить меня», какой её оставил прошлый заход
 * @var int    $days     на сколько дней запоминаем; 0 — галку не показываем вовсе
 */

use Mailer\Ui\View;
?>
<div class="auth">
    <div class="card">
        <h1>Вход в панель</h1>

        <?php if ($error !== '') { ?>
            <div class="flash error"><?= View::e($error) ?></div>
        <?php } ?>

        <form method="post" action="<?= View::e(View::route('ui.login')) ?>">
            <?= View::csrf() ?>
            <input type="hidden" name="next" value="<?= View::e($next) ?>">

            <label>
                <span>Логин</span>
                <input type="text" name="login" value="<?= View::e($login) ?>" autofocus required autocomplete="username">
            </label>

            <label>
                <span>Пароль</span>
                <input type="password" name="password" required autocomplete="current-password">
            </label>

            <?php if ($days > 0) { ?>
                <label class="inline">
                    <input type="checkbox" name="remember" <?= $remember ? 'checked' : '' ?>>
                    <span>Запомнить меня на <?= (int) $days ?> дней</span>
                </label>
                <p class="small muted">На чужом компьютере не ставьте: браузер будет пускать в панель
                    без пароля, пока вы не нажмёте «Выйти».</p>
            <?php } ?>

            <button class="primary" type="submit" style="width:100%">Войти</button>
        </form>
    </div>
</div>
