<?php

declare(strict_types=1);

/**
 * Вебхук проекта: адрес, секрет подписи и события, о которых сообщать.
 *
 * @var array<string, mixed>|null $subscription
 * @var array<int, array<string, mixed>> $projects
 * @var array<int, array<string, mixed>> $deliveries последние доставки по этому вебхуку
 * @var int $projectId проект, из карточки которого сюда пришли
 * @var bool $editable можно ли править: без права webhooks.manage только показываем
 * @var bool $hasKey задан ли APP_KEY — без него секрет ляжет в базу открытым
 */

use Mailer\Domain\Permission;
use Mailer\Support\Str;
use Mailer\Ui\View;
use Mailer\Webhook\Event as WebhookEvent;
use Mailer\Webhook\Payload;

$isNew    = $subscription === null;
$selected = $isNew ? [] : (array) $subscription['events'];
$current  = $isNew ? $projectId : (int) $subscription['project_id'];

$projectName = static function (int $id) use ($projects): string {
    foreach ($projects as $project) {
        if ((int) $project['id'] === $id) {
            return (string) $project['name'];
        }
    }

    return '';
};
?>
<div class="row">
    <h1 style="margin:0"><?= $isNew ? 'Новый вебхук' : 'Вебхук проекта' ?></h1>
    <div class="spacer"></div>
    <a class="btn" href="<?= View::e(View::route('ui.subscriptions')) ?>">К списку</a>
</div>

<?php if ($editable) { ?>
<form method="post" action="<?= View::e(View::route('ui.subscriptions.save')) ?>">
    <?= View::csrf() ?>
    <input type="hidden" name="id" value="<?= (int) ($subscription['id'] ?? 0) ?>">

    <div class="grid cols-2" style="margin-top:16px">
        <div class="card">
            <h2>Куда слать</h2>

            <label>
                <span>Проект</span>
                <select name="project_id" required>
                    <option value="">— выберите проект —</option>
                    <?php foreach ($projects as $project) { ?>
                        <option value="<?= (int) $project['id'] ?>" <?= $current === (int) $project['id'] ? 'selected' : '' ?>>
                            <?= View::e($project['name']) ?>
                        </option>
                    <?php } ?>
                </select>
            </label>

            <label>
                <span>Адрес</span>
                <input type="text" name="url" required value="<?= View::e((string) ($subscription['url'] ?? '')) ?>" placeholder="https://example.com/hooks/mail">
            </label>

            <label>
                <span>Название (для себя)</span>
                <input type="text" name="name" value="<?= View::e((string) ($subscription['name'] ?? '')) ?>" placeholder="боевой приёмник">
            </label>

            <label>
                <span>Секрет подписи<?= $isNew ? ' (пусто — сгенерируем сами)' : ' (пусто — оставить прежний)' ?></span>
                <input type="text" name="secret" value="" autocomplete="off">
            </label>
            <p class="muted small">
                Им подписывается тело запроса, заголовок <span class="mono">X-Mailer-Signature</span>.
                В базе секрет лежит зашифрованным и обратно не показывается.
                <?php if (!$hasKey) { ?>
                    <br><b>APP_KEY не задан</b> — секрет сохранится открытым текстом.
                <?php } ?>
            </p>

            <label class="inline">
                <input type="checkbox" name="active" <?= $isNew || (int) $subscription['active'] === 1 ? 'checked' : '' ?>>
                <span>Вебхук включён</span>
            </label>

            <label>
                <span>Формат тела</span>
                <select name="payload_version">
                    <option value="<?= Payload::V2 ?>" <?= $isNew || (int) $subscription['payload_version'] === Payload::V2 ? 'selected' : '' ?>>
                        конверт (id, event, occurred_at, data)
                    </option>
                    <option value="<?= Payload::V1 ?>" <?= !$isNew && (int) $subscription['payload_version'] === Payload::V1 ? 'selected' : '' ?>>
                        старый плоский формат, только sent и failed
                    </option>
                </select>
            </label>
            <p class="muted small">Старый формат остался для приёмников, написанных до конверта.
                Новым он не нужен: событий в нём два, а времени с часовым поясом нет вовсе.</p>
        </div>

        <div class="card">
            <h2>О чём сообщать</h2>
            <p class="muted small">Ничего не отмечено — придут все события, в том числе те, что появятся позже.</p>

            <?php foreach (WebhookEvent::all() as $code) { ?>
                <label class="inline">
                    <input type="checkbox" name="events[]" value="<?= View::e($code) ?>" <?= in_array($code, $selected, true) ? 'checked' : '' ?>>
                    <span><?= View::e(WebhookEvent::label($code)) ?> <span class="mono muted small"><?= View::e($code) ?></span></span>
                </label>
            <?php } ?>
        </div>
    </div>

    <div class="card">
        <button class="primary" type="submit">Сохранить</button>
    </div>
</form>
<?php } else { ?>
    <div class="card" style="margin-top:16px">
        <h2>Куда слать</h2>
        <?= View::partial('props', ['rows' => [
            'Проект'        => $projectName($current),
            'Адрес'         => $subscription['url'] ?? '',
            'Название'      => $subscription['name'] ?? '',
            'Формат тела'   => (int) $subscription['payload_version'] === Payload::V1 ? 'старый плоский' : 'конверт',
            'События'       => $selected === [] ? 'все' : implode(', ', $selected),
            'Включён'       => (int) $subscription['active'] === 1,
        ]]) ?>
        <p class="muted small">Вебхук доступен вам только на просмотр: права на правку
            (<span class="mono">webhooks.manage</span>) у роли нет.</p>
    </div>
<?php } ?>

<?php if (!$isNew) { ?>
    <div class="card">
        <h2>Состояние</h2>
        <?= View::partial('props', ['rows' => [
            'Последняя доставка' => View::date((string) ($subscription['last_delivery_at'] ?? '')),
            'Чем кончилась'      => ($subscription['last_status'] ?? null) === null ? '' : View::webhookStatus((string) $subscription['last_status']),
            'Неудач подряд'      => (int) $subscription['failures'] ?: '',
            'Последняя ошибка'   => $subscription['last_error'] ?? '',
        ]]) ?>

        <?php if ($editable) { ?>
            <div class="row" style="margin-top:10px">
                <form method="post" action="<?= View::e(View::route('ui.subscriptions.action', ['id' => $subscription['id'], 'action' => 'test'])) ?>">
                    <?= View::csrf() ?>
                    <button class="primary" type="submit">Проверить связь</button>
                </form>
                <form method="post" action="<?= View::e(View::route('ui.subscriptions.action', ['id' => $subscription['id'], 'action' => 'toggle'])) ?>">
                    <?= View::csrf() ?>
                    <button type="submit"><?= (int) $subscription['active'] === 1 ? 'Выключить' : 'Включить' ?></button>
                </form>
                <div class="spacer"></div>
                <form method="post" action="<?= View::e(View::route('ui.subscriptions.action', ['id' => $subscription['id'], 'action' => 'delete'])) ?>" onsubmit="return confirm('Удалить вебхук? Доставки по нему останутся в истории.')">
                    <?= View::csrf() ?>
                    <button class="danger" type="submit">Удалить</button>
                </form>
            </div>
            <p class="muted small">Проверка шлёт событие <span class="mono">ping</span> прямо сейчас и показывает ответ вашего сервера.</p>
        <?php } ?>
    </div>

    <div class="card">
        <h2>Последние доставки</h2>
        <div class="table-wrap">
            <table class="list">
                <tr class="head"><th>Когда</th><th>Событие</th><th>Статус</th><th class="hide-sm">Ответ</th><th></th></tr>
                <?php foreach ($deliveries as $delivery) { ?>
                    <tr>
                        <td class="small nowrap"><?= View::e(View::date((string) $delivery['created_at'])) ?></td>
                        <td class="small"><?= View::e(WebhookEvent::label((string) $delivery['event'])) ?></td>
                        <td><span class="badge <?= View::e((string) $delivery['status']) ?>"><?= View::e(View::webhookStatus((string) $delivery['status'])) ?></span></td>
                        <td class="small hide-sm">
                            <?= View::e((string) ($delivery['response_code'] ?? '—')) ?>
                            <?php if (($delivery['last_error'] ?? null) !== null) { ?>
                                <div class="muted"><?= View::e(Str::limit((string) $delivery['last_error'], 40)) ?></div>
                            <?php } ?>
                        </td>
                        <td><a href="<?= View::e(View::route('ui.webhooks.show', ['id' => $delivery['id']])) ?>">открыть</a></td>
                    </tr>
                <?php } ?>
                <?php if ($deliveries === []) { ?>
                    <tr><td colspan="5" class="muted">Доставок ещё не было</td></tr>
                <?php } ?>
            </table>
        </div>
        <?php if (View::can(Permission::WEBHOOKS_VIEW)) { ?>
            <div style="margin-top:10px">
                <a href="<?= View::e(View::route('ui.webhooks', ['subscription_id' => (int) $subscription['id']])) ?>">Все доставки этого вебхука</a>
            </div>
        <?php } ?>
    </div>
<?php } ?>
