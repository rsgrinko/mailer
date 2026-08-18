<?php

declare(strict_types=1);

/**
 * Список транспортов.
 *
 * @var array<int, array<string, mixed>> $items
 * @var array<int, int> $usage
 */

use Mailer\Support\Str;
use Mailer\Ui\View;
?>
<div class="row">
    <h1 style="margin:0">Транспорты</h1>
    <div class="spacer"></div>
    <a class="btn primary" href="<?= View::e(View::url('/transports/new')) ?>">Добавить транспорт</a>
</div>

<div class="card" style="margin-top:16px">
    <div class="table-wrap">
        <table>
            <tr>
                <th>Имя</th>
                <th>Тип</th>
                <th>Куда отправляет</th>
                <th>Отправитель</th>
                <th>Сегодня</th>
                <th>Состояние</th>
                <th></th>
            </tr>

            <?php foreach ($items as $item): ?>
                <?php
                $settings = (array) $item['settings'];
                $target   = match ($item['type']) {
                    'smtp'     => ($settings['host'] ?? '') . ':' . ($settings['port'] ?? '') . ' (' . ($settings['encryption'] ?? '') . ')',
                    'sendmail' => (string) ($settings['path'] ?? ''),
                    'log'      => (string) ($settings['dir'] ?? ''),
                    'null'     => 'никуда, письма отбрасываются',
                    default    => implode(' → ', (array) ($settings['transports'] ?? [])),
                };
                ?>
                <tr>
                    <td>
                        <a href="<?= View::e(View::url('/transports/' . $item['id'])) ?>"><?= View::e($item['name']) ?></a>
                        <?php if ((int) $item['is_default'] === 1): ?><span class="badge sent">основной</span><?php endif; ?>
                    </td>
                    <td><?= View::e($item['type']) ?></td>
                    <td class="small mono"><?= View::e(Str::limit($target, 50)) ?></td>
                    <td class="small"><?= View::e((string) ($item['from_email'] ?? '—')) ?></td>
                    <td class="small">
                        <?= (int) ($usage[(int) $item['id']] ?? 0) ?><?= (int) $item['daily_limit'] > 0 ? ' / ' . (int) $item['daily_limit'] : '' ?>
                    </td>
                    <td class="small">
                        <?php if ((int) $item['active'] === 1): ?>
                            <span class="badge sent">включён</span>
                        <?php else: ?>
                            <span class="badge muted">выключен</span>
                        <?php endif; ?>
                        <?php if (($item['last_error'] ?? null) !== null && $item['last_error'] !== ''): ?>
                            <div class="muted" title="<?= View::e((string) $item['last_error']) ?>">
                                ошибка: <?= View::e(Str::limit((string) $item['last_error'], 40)) ?>
                            </div>
                        <?php endif; ?>
                        <div class="muted">использован <?= View::e(View::ago($item['last_used_at'])) ?></div>
                    </td>
                    <td class="nowrap">
                        <div class="row">
                            <form method="post" action="<?= View::e(View::url('/transports/' . $item['id'] . '/test')) ?>">
                                <button type="submit">проверить</button>
                            </form>
                            <form method="post" action="<?= View::e(View::url('/transports/' . $item['id'] . '/toggle')) ?>">
                                <button type="submit"><?= (int) $item['active'] === 1 ? 'выключить' : 'включить' ?></button>
                            </form>
                            <?php if ((int) $item['is_default'] !== 1): ?>
                                <form method="post" action="<?= View::e(View::url('/transports/' . $item['id'] . '/default')) ?>">
                                    <button type="submit">сделать основным</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if ($items === []): ?>
                <tr><td colspan="7" class="muted">Транспортов пока нет. Добавьте первый — например, SMTP Яндекса.</td></tr>
            <?php endif; ?>
        </table>
    </div>
</div>
