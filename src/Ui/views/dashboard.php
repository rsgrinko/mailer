<?php

declare(strict_types=1);

/**
 * Обзорная страница: цифры, график за две недели, состояние воркера и последние письма.
 *
 * @var array<string, mixed> $stats
 * @var array<int, array{date: string, sent: int, failed: int, total: int}> $daily
 * @var array<string, mixed> $worker
 * @var array<int, array<string, mixed>> $recent
 * @var array<int, array<string, mixed>> $failed
 * @var array<int, array<string, mixed>> $events
 * @var array<int, array<string, mixed>> $transports
 * @var array<string, int> $webhooks
 */

use Mailer\Support\Str;
use Mailer\Ui\View;

$max = 1;
foreach ($daily as $day) {
    $max = max($max, $day['total']);
}
?>
<h1>Обзор</h1>

<div class="grid cols-4">
    <div class="card stat">
        <div class="label">В очереди</div>
        <div class="value"><?= (int) $stats['queue_ready'] ?></div>
        <div class="small muted">отложено: <?= (int) $stats['queue_delayed'] ?></div>
    </div>
    <div class="card stat">
        <div class="label">Отправлено сегодня</div>
        <div class="value"><?= (int) $stats['today_sent'] ?></div>
        <div class="small muted">всего: <?= (int) ($stats['by_status']['sent'] ?? 0) ?></div>
    </div>
    <div class="card stat">
        <div class="label">Ошибок сегодня</div>
        <div class="value"><?= (int) $stats['today_failed'] ?></div>
        <div class="small muted">всего: <?= (int) ($stats['by_status']['failed'] ?? 0) ?></div>
    </div>
    <div class="card stat">
        <div class="label">Воркер</div>
        <div class="value">
            <?php if (!$worker['known']): ?>
                <span class="badge muted">не запускался</span>
            <?php elseif ($worker['alive']): ?>
                <span class="badge sent">работает</span>
            <?php else: ?>
                <span class="badge failed">молчит</span>
            <?php endif; ?>
        </div>
        <div class="small muted">
            <?php if ($worker['known']): ?>
                отклик <?= View::e(View::ago((string) $worker['time'])) ?>, обработано <?= (int) $worker['processed'] ?>
            <?php else: ?>
                запустите: php bin/mailer worker
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="grid cols-2">
    <div class="card">
        <h2>Письма за две недели</h2>
        <div class="chart">
            <?php foreach ($daily as $day): ?>
                <div class="bar" title="<?= View::e($day['date']) ?>: всего <?= $day['total'] ?>, отправлено <?= $day['sent'] ?>, ошибок <?= $day['failed'] ?>">
                    <?php if ($day['failed'] > 0): ?>
                        <div class="failed" style="height: <?= max(2, (int) round($day['failed'] / $max * 110)) ?>px"></div>
                    <?php endif; ?>
                    <?php if ($day['sent'] > 0): ?>
                        <div class="sent" style="height: <?= max(2, (int) round($day['sent'] / $max * 110)) ?>px"></div>
                    <?php endif; ?>
                    <div class="day"><?= View::e(date('d.m', (int) strtotime($day['date']))) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <h2>Статусы</h2>
        <table>
            <?php foreach ($stats['by_status'] as $status => $count): ?>
                <tr>
                    <td><span class="badge <?= View::e($status) ?>"><?= View::e(View::status((string) $status)) ?></span></td>
                    <td><?= (int) $count ?></td>
                    <td style="text-align:right">
                        <a href="<?= View::e(View::url('/messages', ['status' => $status])) ?>">показать</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>

        <h2 style="margin-top:16px">Вебхуки</h2>
        <table>
            <?php foreach ($webhooks as $status => $count): ?>
                <tr>
                    <td><span class="badge <?= View::e($status) ?>"><?= View::e(View::webhookStatus((string) $status)) ?></span></td>
                    <td><?= (int) $count ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>

<?php if ($failed !== []): ?>
    <div class="card">
        <h2>Последние неудачные письма</h2>
        <div class="table-wrap">
            <table>
                <tr><th>Когда</th><th>Тема</th><th>Кому</th><th>Ошибка</th><th></th></tr>
                <?php foreach ($failed as $row): ?>
                    <tr>
                        <td class="nowrap"><?= View::e(View::ago((string) $row['updated_at'])) ?></td>
                        <td><?= View::e(Str::limit((string) $row['subject'], 50)) ?></td>
                        <td class="mono"><?= View::e(implode(', ', array_column(json_decode((string) ($row['to_json'] ?? '[]'), true) ?: [], 'email'))) ?></td>
                        <td class="small"><?= View::e(Str::limit((string) $row['last_error'], 90)) ?></td>
                        <td><a href="<?= View::e(View::url('/messages/' . $row['id'])) ?>">открыть</a></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="grid cols-2">
    <div class="card">
        <h2>Последние письма</h2>
        <div class="table-wrap">
            <table>
                <tr><th>Когда</th><th>Статус</th><th>Тема</th><th></th></tr>
                <?php foreach ($recent as $row): ?>
                    <tr>
                        <td class="nowrap small"><?= View::e(View::ago((string) $row['created_at'])) ?></td>
                        <td><span class="badge <?= View::e($row['status']) ?>"><?= View::e(View::status((string) $row['status'])) ?></span></td>
                        <td><?= View::e(Str::limit((string) $row['subject'], 40)) ?></td>
                        <td><a href="<?= View::e(View::url('/messages/' . $row['id'])) ?>">открыть</a></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <div class="card">
        <h2>Что происходило</h2>
        <div class="table-wrap">
            <table>
                <?php foreach ($events as $event): ?>
                    <tr>
                        <td class="nowrap small"><?= View::e(View::ago((string) $event['created_at'])) ?></td>
                        <td class="nowrap"><?= View::e(View::event((string) $event['type'])) ?></td>
                        <td class="small"><?= View::e(Str::limit((string) $event['message'], 70)) ?></td>
                        <td><?php if ($event['message_id'] !== null): ?><a href="<?= View::e(View::url('/messages/' . $event['message_id'])) ?>">письмо</a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <h2>Транспорты</h2>
    <div class="table-wrap">
        <table>
            <tr><th>Имя</th><th>Тип</th><th>Признаки</th><th>Последняя отправка</th><th>Последняя ошибка</th></tr>
            <?php foreach ($transports as $transport): ?>
                <tr>
                    <td><a href="<?= View::e(View::url('/transports/' . $transport['id'])) ?>"><?= View::e($transport['name']) ?></a></td>
                    <td><?= View::e(View::transportType((string) $transport['type'])) ?></td>
                    <td>
                        <?php if ((int) $transport['is_default'] === 1): ?><span class="badge sent">основной</span><?php endif; ?>
                        <?php if ((int) $transport['active'] !== 1): ?><span class="badge muted">выключен</span><?php endif; ?>
                    </td>
                    <td class="small"><?= View::e(View::ago($transport['last_used_at'])) ?></td>
                    <td class="small"><?= View::e(Str::limit((string) ($transport['last_error'] ?? ''), 60)) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
