<?php

declare(strict_types=1);

/**
 * Карточка доставки: что мы отправили и что нам ответили.
 *
 * @var array<string, mixed> $item
 * @var array<string, mixed>|null $subscription
 * @var array<string, mixed>|null $project
 */

use Mailer\Domain\Permission;
use Mailer\Ui\View;
use Mailer\Webhook\Event as WebhookEvent;

// Тело показываем с отступами: в базе оно лежит одной строкой ради размера
$payload = json_decode((string) $item['payload'], true);
$pretty  = is_array($payload)
    ? (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    : (string) $item['payload'];
?>
<div class="row">
    <h1 style="margin:0">Доставка вебхука</h1>
    <div class="spacer"></div>
    <a class="btn" href="<?= View::e(View::route('ui.webhooks')) ?>">К списку</a>
</div>

<div class="grid cols-2" style="margin-top:16px">
    <div class="card">
        <h2>Событие</h2>
        <?= View::partial('props', ['rows' => [
            'Событие'      => WebhookEvent::label((string) $item['event']) . ' (' . (string) $item['event'] . ')',
            'Статус'       => View::webhookStatus((string) $item['status']),
            'Идентификатор' => (string) ($item['uuid'] ?? ''),
            'Проект'       => $project === null ? '' : (string) $project['name'],
            'Адрес'        => (string) $item['url'],
            'Создана'      => View::date((string) $item['created_at']),
            'Доставлена'   => View::date((string) ($item['delivered_at'] ?? '')),
        ]]) ?>
    </div>

    <div class="card">
        <h2>Ответ сервера</h2>
        <?= View::partial('props', ['rows' => [
            'Код ответа'   => (string) ($item['response_code'] ?? ''),
            'Времени ушло' => ($item['duration_ms'] ?? null) === null ? '' : (int) $item['duration_ms'] . ' мс',
            'Попыток'      => (string) (int) $item['attempts'],
            'Следующая'    => (string) $item['status'] === 'queued' ? View::date((string) ($item['available_at'] ?? '')) : '',
            'Ошибка'       => (string) ($item['last_error'] ?? ''),
        ]]) ?>

        <?php if (View::can(Permission::WEBHOOKS_MANAGE)) { ?>
            <div class="row" style="margin-top:10px">
                <form method="post" action="<?= View::e(View::route('ui.webhooks.action', ['id' => $item['id'], 'action' => 'send'])) ?>">
                    <?= View::csrf() ?>
                    <button class="primary" type="submit">Отправить сейчас</button>
                </form>
                <form method="post" action="<?= View::e(View::route('ui.webhooks.action', ['id' => $item['id'], 'action' => 'retry'])) ?>">
                    <?= View::csrf() ?>
                    <button type="submit">Вернуть в очередь</button>
                </form>
                <div class="spacer"></div>
                <form method="post" action="<?= View::e(View::route('ui.webhooks.action', ['id' => $item['id'], 'action' => 'delete'])) ?>" onsubmit="return confirm('Удалить запись о доставке?')">
                    <?= View::csrf() ?>
                    <button class="danger" type="submit">Удалить</button>
                </form>
            </div>
        <?php } ?>
    </div>
</div>

<div class="card">
    <h2>Запрос</h2>
    <?php if (($item['request_headers'] ?? null) !== null) { ?>
        <pre><?= View::e((string) $item['request_headers']) ?></pre>
    <?php } else { ?>
        <p class="muted small">Запрос ещё не уходил — заголовки появятся после первой попытки.</p>
    <?php } ?>
    <pre><?= View::e($pretty) ?></pre>
</div>

<div class="card">
    <h2>Что ответили</h2>
    <?php if (($item['response_headers'] ?? null) !== null && (string) $item['response_headers'] !== '') { ?>
        <pre><?= View::e((string) $item['response_headers']) ?></pre>
    <?php } ?>
    <?php if (($item['response_body'] ?? null) !== null && (string) $item['response_body'] !== '') { ?>
        <pre><?= View::e((string) $item['response_body']) ?></pre>
    <?php } else { ?>
        <p class="muted small">Тела ответа нет.</p>
    <?php } ?>
</div>

<div class="card">
    <h2>Откуда это</h2>
    <div class="row">
        <?php if ($subscription !== null) { ?>
            <a href="<?= View::e(View::route('ui.subscriptions.show', ['id' => $subscription['id']])) ?>">Вебхук проекта</a>
        <?php } else { ?>
            <span class="muted small">Вебхук, по которому ушла доставка, уже удалён.</span>
        <?php } ?>
        <?php if ($item['message_id'] !== null && View::can(Permission::MESSAGES_VIEW)) { ?>
            <a href="<?= View::e(View::route('ui.messages.show', ['id' => $item['message_id']])) ?>">Письмо</a>
        <?php } ?>
    </div>
</div>
