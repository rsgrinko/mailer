<?php

declare(strict_types=1);

/**
 * Создание и правка проекта.
 *
 * @var array<string, mixed>|null $project
 * @var array<int, array<string, mixed>> $transports
 * @var array{hour: int, day: int} $usage
 * @var array<int, array<string, mixed>> $recent
 */

use Mailer\Security\ApiKey;
use Mailer\Support\Str;
use Mailer\Ui\View;

$isNew = $project === null;
?>
<div class="row">
    <h1 style="margin:0"><?= $isNew ? 'Новый проект' : 'Проект «' . View::e((string) $project['name']) . '»' ?></h1>
    <div class="spacer"></div>
    <a class="btn" href="<?= View::e(View::route('ui.projects')) ?>">К списку</a>
</div>

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

<?php if (!$isNew) { ?>
    <div class="card">
        <h2>Ключ доступа</h2>
        <p>
            Текущий ключ: <span class="mono"><?= View::e(ApiKey::mask((string) $project['api_key_prefix'])) ?></span>.
            Полностью ключ хранится только у вас — в базе лежит его хеш.
        </p>
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
    </div>

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
