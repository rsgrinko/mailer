<?php

declare(strict_types=1);

/**
 * Создание пользователя и правка его данных (в том числе смена пароля).
 *
 * @var array<string, mixed>|null $user
 * @var array<string, mixed>|null $current
 */

use Mailer\Security\Password;
use Mailer\Ui\View;

$isNew  = $user === null;
$isSelf = !$isNew && $current !== null && (int) $current['id'] === (int) $user['id'];
?>
<div class="row">
    <h1 style="margin:0"><?= $isNew ? 'Новый пользователь' : 'Пользователь «' . View::e((string) $user['login']) . '»' ?></h1>
    <div class="spacer"></div>
    <a class="btn" href="<?= View::e(View::url('/users')) ?>">К списку</a>
</div>

<form method="post" action="<?= View::e(View::url('/users/save')) ?>">
    <input type="hidden" name="id" value="<?= (int) ($user['id'] ?? 0) ?>">

    <div class="grid cols-2" style="margin-top:16px">
        <div class="card">
            <h2>Кто это</h2>

            <label>
                <span>Логин</span>
                <input type="text" name="login" required autocomplete="username"
                       value="<?= View::e((string) ($user['login'] ?? '')) ?>" placeholder="ivan">
            </label>

            <label>
                <span>Имя (необязательно)</span>
                <input type="text" name="name" value="<?= View::e((string) ($user['name'] ?? '')) ?>">
            </label>

            <label class="inline">
                <input type="checkbox" name="active" <?= $isNew || (int) $user['active'] === 1 ? 'checked' : '' ?> <?= $isSelf ? 'disabled' : '' ?>>
                <span>Может входить в панель</span>
            </label>

            <?php if ($isSelf): ?>
                <input type="hidden" name="active" value="1">
                <p class="muted small">Себя отключить нельзя — попросите об этом коллегу.</p>
            <?php endif; ?>

            <?php if (!$isNew): ?>
                <p class="muted small">
                    Последний вход: <?= View::e(View::date($user['last_login_at'] === null ? null : (string) $user['last_login_at'])) ?>
                    <?php if (($user['last_login_ip'] ?? null) !== null): ?>
                        с адреса <span class="mono"><?= View::e((string) $user['last_login_ip']) ?></span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2><?= $isNew ? 'Пароль' : 'Смена пароля' ?></h2>

            <?php if (!$isNew): ?>
                <p class="muted small" style="margin-top:0">Оставьте поля пустыми, если пароль менять не нужно.</p>
            <?php endif; ?>

            <label>
                <span>Пароль (от <?= Password::MIN_LENGTH ?> символов)</span>
                <input type="password" name="password" autocomplete="new-password" minlength="<?= Password::MIN_LENGTH ?>" <?= $isNew ? 'required' : '' ?>>
            </label>

            <label>
                <span>Пароль ещё раз</span>
                <input type="password" name="password_repeat" autocomplete="new-password" minlength="<?= Password::MIN_LENGTH ?>" <?= $isNew ? 'required' : '' ?>>
            </label>
        </div>
    </div>

    <div class="card">
        <button class="primary" type="submit">Сохранить</button>
    </div>
</form>

<?php if (!$isNew && !$isSelf): ?>
    <div class="card">
        <h2>Действия</h2>
        <div class="row">
            <form method="post" action="<?= View::e(View::url('/users/' . $user['id'] . '/password')) ?>"
                  onsubmit="return confirm('Сбросить пароль? Новый покажется один раз.')">
                <button type="submit">Сбросить пароль</button>
            </form>
            <form method="post" action="<?= View::e(View::url('/users/' . $user['id'] . '/' . ((int) $user['active'] === 1 ? 'disable' : 'enable'))) ?>">
                <button type="submit"><?= (int) $user['active'] === 1 ? 'Отключить' : 'Включить' ?></button>
            </form>
            <div class="spacer"></div>
            <form method="post" action="<?= View::e(View::url('/users/' . $user['id'] . '/delete')) ?>" onsubmit="return confirm('Удалить пользователя?')">
                <button class="danger" type="submit">Удалить</button>
            </form>
        </div>
    </div>
<?php endif; ?>
