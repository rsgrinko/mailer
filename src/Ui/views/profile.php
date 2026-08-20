<?php

declare(strict_types=1);

/**
 * Свой профиль: имя, пароль и что доступно по роли.
 *
 * @var array<string, mixed> $person вошедший пользователь
 */

use Mailer\Domain\Permission;
use Mailer\Security\Password;
use Mailer\Ui\View;

$permissions = (array) ($person['permissions'] ?? []);
$editable    = (int) ($person['id'] ?? 0) > 0;
?>
<div class="row">
    <h1 style="margin:0">Мой профиль</h1>
</div>

<?php if ($editable) { ?>
<form method="post" action="<?= View::e(View::route('ui.profile')) ?>">
    <?= View::csrf() ?>

    <div class="grid cols-2" style="margin-top:16px">
        <div class="card">
            <h2>Кто я</h2>

            <p class="muted small" style="margin-top:0">
                Логин <span class="mono"><?= View::e((string) $person['login']) ?></span>,
                роль <?= View::e((string) ($person['role_name'] ?? 'не выдана')) ?>.
            </p>

            <label>
                <span>Имя</span>
                <input type="text" name="name" value="<?= View::e((string) ($person['name'] ?? '')) ?>">
            </label>
        </div>

        <div class="card">
            <h2>Смена пароля</h2>
            <p class="muted small" style="margin-top:0">Оставьте поля пустыми, если пароль менять не нужно.</p>

            <label>
                <span>Новый пароль (от <?= Password::MIN_LENGTH ?> символов)</span>
                <input type="password" name="password" autocomplete="new-password" minlength="<?= Password::MIN_LENGTH ?>">
            </label>

            <label>
                <span>Пароль ещё раз</span>
                <input type="password" name="password_repeat" autocomplete="new-password" minlength="<?= Password::MIN_LENGTH ?>">
            </label>
        </div>
    </div>

    <div class="card">
        <button class="primary" type="submit">Сохранить</button>
    </div>
</form>
<?php } else { ?>
    <div class="card" style="margin-top:16px">
        <p class="muted small" style="margin:0">
            Авторизация панели выключена настройкой UI_AUTH: пользователей нет, доступно всё.
        </p>
    </div>
<?php } ?>

<div class="card">
    <h2>Что мне доступно</h2>

    <?php if ($permissions === []) { ?>
        <p class="muted small">Прав нет: роль не выдана или в ней ничего не отмечено. Попросите администратора.</p>
    <?php } else { ?>
        <div class="counts">
            <?php foreach ($permissions as $code) { ?>
                <div class="item">
                    <span class="badge"><?= View::e(Permission::label($code)) ?></span>
                    <span class="mono small muted"><?= View::e($code) ?></span>
                </div>
            <?php } ?>
        </div>
    <?php } ?>
</div>
