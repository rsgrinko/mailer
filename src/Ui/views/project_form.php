<?php

declare(strict_types=1);

/**
 * Создание и правка проекта.
 *
 * @var array<string, mixed>|null $project
 * @var array<int, array<string, mixed>> $transports
 * @var array<int, array<string, mixed>> $owners пользователи для выбора владельца (только администратору)
 * @var array{hour: int, day: int} $usage
 * @var array<int, array<string, mixed>> $recent
 * @var bool $editable можно ли править: без права projects.manage проект только показываем
 */

use Mailer\Domain\Permission;
use Mailer\Security\ApiKey;
use Mailer\Support\Str;
use Mailer\Ui\View;

$isNew = $project === null;

$transportName = static function (?int $id) use ($transports): string {
    foreach ($transports as $transport) {
        if ((int) $transport['id'] === (int) $id) {
            return (string) $transport['name'];
        }
    }

    return '';
};
?>
<div class="row">
    <h1 style="margin:0"><?= $isNew ? 'Новый проект' : 'Проект «' . View::e((string) $project['name']) . '»' ?></h1>
    <div class="spacer"></div>
    <a class="btn" href="<?= View::e(View::route('ui.projects')) ?>">К списку</a>
</div>

<?php if ($editable) { ?>
<form method="post" action="<?= View::e(View::route('ui.projects.save')) ?>">
    <?= View::csrf() ?>
    <input type="hidden" name="id" value="<?= (int) ($project['id'] ?? 0) ?>">

    <div class="grid cols-2" style="margin-top:16px">
        <div class="card">
            <h2>Основное</h2>

            <label>
                <span>Название</span>
                <input type="text" name="name" required value="<?= View::e((string) ($project['name'] ?? '')) ?>" placeholder="интернет-магазин">
            </label>

            <label>
                <span>Описание</span>
                <input type="text" name="description" value="<?= View::e((string) ($project['description'] ?? '')) ?>">
            </label>

            <label>
                <span>Транспорт проекта</span>
                <select name="transport_id">
                    <option value="">использовать основной</option>
                    <?php foreach ($transports as $transport) { ?>
                        <option value="<?= (int) $transport['id'] ?>" <?= (int) ($project['transport_id'] ?? 0) === (int) $transport['id'] ? 'selected' : '' ?>>
                            <?= View::e($transport['name']) ?> (<?= View::e(View::transportType((string) $transport['type'])) ?>)
                        </option>
                    <?php } ?>
                </select>
            </label>

            <div class="row">
                <label style="flex:1">
                    <span>Отправитель по умолчанию</span>
                    <input type="text" name="default_from_email" value="<?= View::e((string) ($project['default_from_email'] ?? '')) ?>">
                </label>
                <label style="flex:1">
                    <span>Имя отправителя</span>
                    <input type="text" name="default_from_name" value="<?= View::e((string) ($project['default_from_name'] ?? '')) ?>">
                </label>
            </div>

            <label class="inline">
                <input type="checkbox" name="active" <?= $isNew || (int) $project['active'] === 1 ? 'checked' : '' ?>>
                <span>Проект активен</span>
            </label>

            <label class="inline">
                <input type="checkbox" name="unsubscribe" <?= !$isNew && (int) ($project['unsubscribe'] ?? 0) === 1 ? 'checked' : '' ?>>
                <span>Кнопка «отписаться» в письмах</span>
            </label>
            <p class="small muted">Почтовые клиенты покажут её сами (List-Unsubscribe). Нужна массовым
                рассылкам — Gmail и Mail.ru без неё складывают письма в спам. Служебным письмам
                вроде сброса пароля не нужна. Работает, если задан APP_URL и включён UNSUBSCRIBE_ENABLED.</p>

            <?php if ($owners !== []) { ?>
                <label>
                    <span>Владелец</span>
                    <select name="owner_id">
                        <option value="0">— ничей, виден только администраторам —</option>
                        <?php foreach ($owners as $owner) { ?>
                            <option value="<?= (int) $owner['id'] ?>" <?= (int) ($project['owner_id'] ?? 0) === (int) $owner['id'] ? 'selected' : '' ?>>
                                <?= View::e((string) $owner['login']) ?>
                            </option>
                        <?php } ?>
                    </select>
                </label>
                <p class="muted small">Письма проекта уедут к новому владельцу вместе с ним.</p>
            <?php } ?>
        </div>

        <div class="card">
            <h2>Лимиты и вебхук</h2>

            <div class="row">
                <label style="flex:1">
                    <span>Писем в час (0 — без ограничений)</span>
                    <input type="number" name="rate_limit_hour" value="<?= (int) ($project['rate_limit_hour'] ?? 0) ?>">
                </label>
                <label style="flex:1">
                    <span>Писем в сутки</span>
                    <input type="number" name="rate_limit_day" value="<?= (int) ($project['rate_limit_day'] ?? 0) ?>">
                </label>
            </div>

            <?php if (!$isNew) { ?>
                <p class="muted small">Использовано: <?= (int) $usage['hour'] ?> за час, <?= (int) $usage['day'] ?> за сутки.</p>
            <?php } ?>

            <label>
                <span>Адрес вебхука (туда придёт результат отправки)</span>
                <input type="text" name="webhook_url" value="<?= View::e((string) ($project['webhook_url'] ?? '')) ?>" placeholder="https://example.com/hooks/mail">
            </label>

            <label>
                <span>Секрет подписи вебхука<?= $isNew ? ' (пусто — сгенерируем сами)' : '' ?></span>
                <input type="text" name="webhook_secret" value="<?= View::e((string) ($project['webhook_secret'] ?? '')) ?>">
            </label>
        </div>
    </div>

    <div class="card">
        <button class="primary" type="submit">Сохранить</button>
    </div>
</form>
<?php } else { ?>
    <div class="grid cols-2" style="margin-top:16px">
        <div class="card">
            <h2>Основное</h2>
            <?= View::partial('props', ['rows' => [
                'Название'                  => $project['name'] ?? '',
                'Описание'                  => $project['description'] ?? '',
                'Транспорт проекта'         => $transportName($project['transport_id'] ?? null) ?: 'основной',
                'Отправитель по умолчанию'  => $project['default_from_email'] ?? '',
                'Имя отправителя'           => $project['default_from_name'] ?? '',
                'Проект активен'            => (int) ($project['active'] ?? 0) === 1,
                'Кнопка «отписаться»'       => (int) ($project['unsubscribe'] ?? 0) === 1,
            ]]) ?>
        </div>

        <div class="card">
            <h2>Лимиты и вебхук</h2>
            <?= View::partial('props', ['rows' => [
                'Писем в час'      => (int) ($project['rate_limit_hour'] ?? 0) ?: 'без ограничений',
                'Писем в сутки'    => (int) ($project['rate_limit_day'] ?? 0) ?: 'без ограничений',
                'Использовано'     => (int) $usage['hour'] . ' за час, ' . (int) $usage['day'] . ' за сутки',
                'Адрес вебхука'    => $project['webhook_url'] ?? '',
                'Секрет подписи'   => ((string) ($project['webhook_secret'] ?? '')) !== '' ? 'задан' : '',
            ]]) ?>
            <p class="muted small">Проект доступен вам только на просмотр: права на правку
                (<span class="mono">projects.manage</span>) у роли нет.</p>
        </div>
    </div>
<?php } ?>

<?php if (!$isNew) { ?>
    <div class="card">
        <h2>Ключ доступа</h2>
        <p>
            Текущий ключ: <span class="mono"><?= View::e(ApiKey::mask((string) $project['api_key_prefix'])) ?></span>.
            Полностью ключ хранится только у вас — в базе лежит его хеш.
        </p>
        <?php if ($editable) { ?>
        <div class="row">
            <form method="post" action="<?= View::e(View::route('ui.projects.action', ['id' => $project['id'], 'action' => 'key'])) ?>" onsubmit="return confirm('Выдать новый ключ? Старый сразу перестанет работать.')">
                <?= View::csrf() ?>
                <button type="submit">Выдать новый ключ</button>
            </form>
            <form method="post" action="<?= View::e(View::route('ui.projects.action', ['id' => $project['id'], 'action' => 'toggle'])) ?>">
                <?= View::csrf() ?>
                <button type="submit"><?= (int) $project['active'] === 1 ? 'Отключить проект' : 'Включить проект' ?></button>
            </form>
            <div class="spacer"></div>
            <form method="post" action="<?= View::e(View::route('ui.projects.action', ['id' => $project['id'], 'action' => 'delete'])) ?>" onsubmit="return confirm('Удалить проект? Письма останутся в истории.')">
                <?= View::csrf() ?>
                <button class="danger" type="submit">Удалить проект</button>
            </form>
        </div>
        <?php } ?>
    </div>

    <?php if (View::can(Permission::MESSAGES_VIEW)) { ?>
    <div class="card">
        <h2>Последние письма проекта</h2>
        <div class="table-wrap">
            <table class="list">
                <tr class="head"><th>Когда</th><th>Статус</th><th>Тема</th><th></th></tr>
                <?php foreach ($recent as $row) { ?>
                    <tr>
                        <td class="small nowrap"><?= View::e(View::date((string) $row['created_at'])) ?></td>
                        <td><span class="badge <?= View::e($row['status']) ?>"><?= View::e(View::status((string) $row['status'])) ?></span></td>
                        <td><?= View::e(Str::limit((string) $row['subject'], 50)) ?></td>
                        <td><a href="<?= View::e(View::route('ui.messages.show', ['id' => $row['id']])) ?>">открыть</a></td>
                    </tr>
                <?php } ?>
                <?php if ($recent === []) { ?>
                    <tr><td colspan="4" class="muted">Писем ещё не было</td></tr>
                <?php } ?>
            </table>
        </div>
        <div style="margin-top:10px">
            <a href="<?= View::e(View::route('ui.messages', ['project_id' => (int) $project['id']])) ?>">Все письма проекта</a>
        </div>
    </div>
    <?php } ?>
<?php } ?>
