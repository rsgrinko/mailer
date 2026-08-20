<?php

declare(strict_types=1);

/**
 * Создание роли и правка её прав.
 *
 * @var array<string, mixed>|null $role
 * @var array<string, array<string, string>> $groups права по разделам
 * @var int $usage сколько пользователей с этой ролью
 */

use Mailer\Ui\View;

$isNew    = $role === null;
$isSystem = !$isNew && (int) $role['is_system'] === 1;
$granted  = $isNew ? [] : (array) $role['permissions'];
?>
<div class="row">
    <h1 style="margin:0"><?= $isNew ? 'Новая роль' : 'Роль «' . View::e((string) $role['name']) . '»' ?></h1>
    <div class="spacer"></div>
    <a class="btn" href="<?= View::e(View::route('ui.roles')) ?>">К списку</a>
</div>

<form method="post" action="<?= View::e(View::route('ui.roles.save')) ?>">
    <?= View::csrf() ?>
    <input type="hidden" name="id" value="<?= (int) ($role['id'] ?? 0) ?>">

    <div class="card" style="margin-top:16px">
        <h2>Название</h2>

        <label>
            <span>Как называется роль</span>
            <input type="text" name="name" required value="<?= View::e((string) ($role['name'] ?? '')) ?>" placeholder="Менеджер рассылок">
        </label>

        <label>
            <span>Описание (необязательно)</span>
            <input type="text" name="description" value="<?= View::e((string) ($role['description'] ?? '')) ?>">
        </label>

        <?php if (!$isNew) { ?>
            <p class="muted small">Роль выдана пользователям: <?= (int) $usage ?></p>
        <?php } ?>

        <?php if ($isSystem) { ?>
            <p class="muted small">
                Встроенная роль: права у неё менять нельзя и удалить её тоже нельзя —
                иначе панель рискует остаться без хозяина.
            </p>
        <?php } ?>
    </div>

    <div class="card">
        <h2>Права</h2>
        <p class="muted small" style="margin-top:0">
            Право открывает раздел. Какие записи в нём видно, решает владелец: свои проекты,
            транспорты, шаблоны и письма. Право «доступ к чужим данным» снимает это ограничение.
        </p>

        <div class="grid cols-2">
            <?php foreach ($groups as $group => $permissions) { ?>
                <div>
                    <h3><?= View::e($group) ?></h3>
                    <?php foreach ($permissions as $code => $label) { ?>
                        <label class="inline">
                            <input type="checkbox" name="permissions[]" value="<?= View::e($code) ?>"
                                   <?= in_array($code, $granted, true) ? 'checked' : '' ?>
                                   <?= $isSystem ? 'disabled' : '' ?>>
                            <span><?= View::e($label) ?> <span class="muted mono small"><?= View::e($code) ?></span></span>
                        </label>
                    <?php } ?>
                </div>
            <?php } ?>
        </div>
    </div>

    <div class="card">
        <button class="primary" type="submit">Сохранить</button>
    </div>
</form>
