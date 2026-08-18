<?php

declare(strict_types=1);

/**
 * Карточка письма: свойства, содержимое, вложения, события и вебхуки.
 *
 * @var array<string, mixed> $message
 * @var array<int, array<string, mixed>> $to
 * @var array<int, array<string, mixed>> $cc
 * @var array<int, array<string, mixed>> $bcc
 * @var array<string, mixed> $headers
 * @var array<string, mixed> $meta
 * @var array<string, mixed> $templateData
 * @var array<int, array<string, mixed>> $attachments
 * @var array<int, array<string, mixed>> $events
 * @var array<string, mixed>|null $project
 * @var array<string, mixed>|null $transport
 * @var array<int, array<string, mixed>> $webhooks
 * @var string $mime
 * @var array{text: string, html: string} $preview
 */

use Mailer\Support\Str;
use Mailer\Ui\View;

$id        = (int) $message['id'];
$addresses = static fn (array $list): string => implode(', ', array_map(
    static fn (array $a): string => trim(($a['name'] ?? '') . ' <' . ($a['email'] ?? '') . '>'),
    $list
));
?>
<div class="row">
    <h1 style="margin:0"><?= View::e((string) $message['subject'] ?: '(без темы)') ?></h1>
    <span class="badge <?= View::e($message['status']) ?>"><?= View::e($message['status']) ?></span>
    <div class="spacer"></div>
    <a class="btn" href="<?= View::e(View::url('/messages')) ?>">К списку</a>
</div>

<div class="card" style="margin-top:16px">
    <div class="row">
        <form method="post" action="<?= View::e(View::url('/messages/' . $id . '/send')) ?>">
            <button class="primary" type="submit">Отправить сейчас</button>
        </form>
        <form method="post" action="<?= View::e(View::url('/messages/' . $id . '/retry')) ?>">
            <button type="submit">Вернуть в очередь</button>
        </form>
        <form method="post" action="<?= View::e(View::url('/messages/' . $id . '/cancel')) ?>">
            <button type="submit">Отменить</button>
        </form>
        <a class="btn" href="<?= View::e(View::url('/compose', ['copy' => $id])) ?>">Написать похожее</a>
        <a class="btn" href="<?= View::e(View::url('/messages/' . $id . '/raw')) ?>">Скачать .eml</a>
        <div class="spacer"></div>
        <form method="post" action="<?= View::e(View::url('/messages/' . $id . '/delete')) ?>" onsubmit="return confirm('Удалить письмо вместе с историей и вложениями?')">
            <button class="danger" type="submit">Удалить</button>
        </form>
    </div>
</div>

<div class="grid cols-2">
    <div class="card">
        <h2>Свойства</h2>
        <dl class="props">
            <dt>Идентификатор</dt><dd class="mono"><?= View::e((string) $message['uuid']) ?></dd>
            <dt>От кого</dt><dd><?= View::e(trim((string) ($message['from_name'] ?? '') . ' <' . (string) ($message['from_email'] ?? '') . '>')) ?></dd>
            <dt>Кому</dt><dd><?= View::e($addresses($to)) ?></dd>
            <?php if ($cc !== []): ?><dt>Копия</dt><dd><?= View::e($addresses($cc)) ?></dd><?php endif; ?>
            <?php if ($bcc !== []): ?><dt>Скрытая копия</dt><dd><?= View::e($addresses($bcc)) ?></dd><?php endif; ?>
            <?php if (($message['reply_to'] ?? null) !== null): ?><dt>Ответ на</dt><dd><?= View::e((string) $message['reply_to']) ?></dd><?php endif; ?>
            <dt>Источник</dt><dd><?= View::e((string) $message['source']) ?></dd>
            <dt>Проект</dt>
            <dd>
                <?php if ($project !== null): ?>
                    <a href="<?= View::e(View::url('/projects/' . $project['id'])) ?>"><?= View::e($project['name']) ?></a>
                <?php else: ?>
                    <span class="muted">не задан</span>
                <?php endif; ?>
            </dd>
            <dt>Транспорт</dt>
            <dd>
                <?php if ($transport !== null): ?>
                    <a href="<?= View::e(View::url('/transports/' . $transport['id'])) ?>"><?= View::e($transport['name']) ?></a>
                <?php else: ?>
                    <span class="muted">выбирается при отправке</span>
                <?php endif; ?>
                <?php if (($message['transport_used'] ?? null) !== null): ?>
                    <span class="muted">(отправлено через <?= View::e((string) $message['transport_used']) ?>)</span>
                <?php endif; ?>
            </dd>
            <?php if (($message['template'] ?? null) !== null): ?>
                <dt>Шаблон</dt><dd><?= View::e((string) $message['template']) ?></dd>
            <?php endif; ?>
            <?php if (($message['tag'] ?? null) !== null): ?>
                <dt>Метка</dt><dd><?= View::e((string) $message['tag']) ?></dd>
            <?php endif; ?>
            <dt>Попытки</dt><dd><?= (int) $message['attempts'] ?> из <?= (int) $message['max_attempts'] ?></dd>
            <dt>Размер</dt><dd><?= View::e(Str::bytes((int) $message['size'])) ?></dd>
            <dt>Создано</dt><dd><?= View::e(View::date((string) $message['created_at'])) ?></dd>
            <dt>Готово к отправке</dt><dd><?= View::e(View::date($message['available_at'])) ?></dd>
            <dt>Отправлено</dt><dd><?= View::e(View::date($message['sent_at'])) ?></dd>
            <?php if (($message['locked_by'] ?? null) !== null): ?>
                <dt>Занято воркером</dt><dd class="mono"><?= View::e((string) $message['locked_by']) ?></dd>
            <?php endif; ?>
        </dl>

        <?php if (($message['last_error'] ?? null) !== null && $message['last_error'] !== ''): ?>
            <h2 style="margin-top:16px">Последняя ошибка</h2>
            <pre><?= View::e((string) $message['last_error']) ?></pre>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>История</h2>
        <div class="table-wrap">
            <table>
                <tr><th>Когда</th><th>Событие</th><th>Подробности</th></tr>
                <?php foreach ($events as $event): ?>
                    <tr>
                        <td class="nowrap small"><?= View::e(View::date((string) $event['created_at'])) ?></td>
                        <td class="nowrap"><?= View::e((string) $event['type']) ?></td>
                        <td class="small"><?= View::e((string) $event['message']) ?>
                            <?php if (!empty($event['meta'])): ?>
                                <details>
                                    <summary class="muted">подробнее</summary>
                                    <pre><?= View::e((string) json_encode($event['meta'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <?php if ($headers !== [] || $meta !== [] || $templateData !== []): ?>
            <h2 style="margin-top:16px">Дополнительно</h2>
            <?php if ($headers !== []): ?>
                <div class="small muted">Заголовки</div>
                <pre><?= View::e((string) json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
            <?php endif; ?>
            <?php if ($templateData !== []): ?>
                <div class="small muted" style="margin-top:8px">Данные шаблона</div>
                <pre><?= View::e((string) json_encode($templateData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
            <?php endif; ?>
            <?php if ($meta !== []): ?>
                <div class="small muted" style="margin-top:8px">Метаданные</div>
                <pre><?= View::e((string) json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($attachments !== []): ?>
    <div class="card">
        <h2>Вложения</h2>
        <table>
            <tr><th>Имя</th><th>Тип</th><th>Размер</th><th>Встроено</th><th></th></tr>
            <?php foreach ($attachments as $index => $attachment): ?>
                <tr>
                    <td><?= View::e((string) ($attachment['name'] ?? '')) ?></td>
                    <td class="small"><?= View::e((string) ($attachment['content_type'] ?? '')) ?></td>
                    <td class="small"><?= View::e(Str::bytes((int) ($attachment['size'] ?? 0))) ?></td>
                    <td class="small"><?= !empty($attachment['inline']) ? 'cid:' . View::e((string) ($attachment['cid'] ?? '')) : '—' ?></td>
                    <td><a href="<?= View::e(View::url('/messages/' . $id . '/attachment', ['index' => $index])) ?>">скачать</a></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
<?php endif; ?>

<div class="card">
    <h2>Содержимое</h2>

    <?php if ($preview['html'] !== ''): ?>
        <div class="small muted" style="margin-bottom:6px">HTML-версия</div>
        <iframe class="preview" sandbox srcdoc="<?= View::e($preview['html']) ?>"></iframe>
    <?php endif; ?>

    <?php if ($preview['text'] !== ''): ?>
        <div class="small muted" style="margin:12px 0 6px">Текстовая версия</div>
        <pre><?= View::e($preview['text']) ?></pre>
    <?php endif; ?>

    <details style="margin-top:12px">
        <summary>Письмо целиком (MIME)</summary>
        <pre style="margin-top:8px"><?= View::e($mime) ?></pre>
    </details>
</div>

<?php if ($webhooks !== []): ?>
    <div class="card">
        <h2>Вебхуки по этому письму</h2>
        <table>
            <tr><th>Событие</th><th>Статус</th><th>Адрес</th><th>Попытки</th><th>Ответ</th><th>Когда</th></tr>
            <?php foreach ($webhooks as $hook): ?>
                <tr>
                    <td><?= View::e((string) $hook['event']) ?></td>
                    <td><span class="badge <?= View::e((string) $hook['status']) ?>"><?= View::e((string) $hook['status']) ?></span></td>
                    <td class="small mono"><?= View::e(Str::limit((string) $hook['url'], 50)) ?></td>
                    <td class="small"><?= (int) $hook['attempts'] ?></td>
                    <td class="small"><?= View::e((string) ($hook['response_code'] ?? '—')) ?></td>
                    <td class="small"><?= View::e(View::date((string) $hook['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
<?php endif; ?>
