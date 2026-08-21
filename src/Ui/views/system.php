<?php

declare(strict_types=1);

/**
 * Состояние сервиса: база, миграции, настройки, счётчики и служебные кнопки.
 *
 * @var string $driver
 * @var array<string, string> $dbInfo
 * @var array<int, string> $pending
 * @var array<int, string> $unknownMigrations
 * @var bool $hasKey
 * @var array<string, mixed> $config
 * @var array<int, array<string, mixed>> $counters
 * @var array<string, array{value: string, updated_at: string}> $settings
 * @var array<string, mixed> $worker
 * @var array<string, int> $tables
 * @var array<string, mixed> $php
 */

use Mailer\Domain\Permission;
use Mailer\Ui\View;
?>
<h1>Состояние сервиса</h1>

<div class="grid cols-2">
    <div class="card">
        <h2>База данных</h2>
        <dl class="props">
            <dt>Драйвер</dt><dd><?= View::e($driver) ?></dd>
            <?php foreach ($dbInfo as $key => $value) { ?>
                <dt><?= View::e($key) ?></dt><dd class="mono"><?= View::e($value) ?></dd>
            <?php } ?>
            <dt>Миграции</dt>
            <dd>
                <?php if ($pending === []) { ?>
                    <span class="badge sent">все применены</span>
                <?php } else { ?>
                    <span class="badge failed">ждут: <?= View::e(implode(', ', $pending)) ?></span>
                <?php } ?>
                <?php if ($unknownMigrations !== []) { ?>
                    <br><span class="badge failed">в базе есть, а в коде нет: <?= View::e(implode(', ', $unknownMigrations)) ?></span>
                <?php } ?>
            </dd>
            <dt>Шифрование паролей</dt>
            <dd>
                <?php if ($hasKey) { ?>
                    <span class="badge sent">APP_KEY задан</span>
                <?php } else { ?>
                    <span class="badge failed">APP_KEY не задан — пароли лежат открытым текстом</span>
                <?php } ?>
            </dd>
        </dl>

        <h2 style="margin-top:16px">Записи в таблицах</h2>
        <div class="counts">
            <?php foreach ($tables as $table => $count) { ?>
                <div class="item">
                    <span class="mono"><?= View::e($table) ?></span>
                    <span class="num"><?= (int) $count ?></span>
                </div>
            <?php } ?>
        </div>
    </div>

    <div class="card">
        <h2>Воркер</h2>
        <?php if (!$worker['known']) { ?>
            <p>Воркер ещё не запускался. Запустите его командой <span class="mono">php bin/mailer worker</span>
                или через systemd (см. deploy/mailer-worker.service).</p>
        <?php } else { ?>
            <dl class="props">
                <dt>Состояние</dt>
                <dd>
                    <?php if ($worker['alive']) { ?>
                        <span class="badge sent">работает</span>
                    <?php } else { ?>
                        <span class="badge failed">не отвечает</span>
                    <?php } ?>
                </dd>
                <dt>Последний отклик</dt><dd><?= View::e(View::date((string) $worker['time'])) ?> (<?= View::e(View::ago((string) $worker['time'])) ?>)</dd>
                <dt>Обработано писем</dt><dd><?= (int) $worker['processed'] ?></dd>
                <dt>Идентификатор</dt><dd class="mono"><?= View::e((string) ($worker['worker'] ?? '')) ?></dd>
            </dl>
        <?php } ?>

        <h2 style="margin-top:16px">PHP</h2>
        <dl class="props">
            <dt>Версия</dt><dd><?= View::e((string) $php['version']) ?> (<?= View::e((string) $php['sapi']) ?>)</dd>
            <dt>Расширения веб-процесса</dt>
            <dd>
                <?php foreach ((array) $php['extensions'] as $name => $loaded) { ?>
                    <span class="badge <?= $loaded ? 'sent' : 'muted' ?>"><?= View::e($name) ?></span>
                <?php } ?>
            </dd>
            <?php if (($worker['php'] ?? null) !== null) { ?>
                <dt>Версия в CLI</dt><dd><?= View::e((string) $worker['php']) ?></dd>
            <?php } ?>
            <dt>Сигналы воркера (pcntl)</dt>
            <dd>
                <?php if (($worker['pcntl'] ?? null) === null) { ?>
                    <span class="badge muted">воркер ещё не отчитывался</span>
                <?php } elseif ($worker['pcntl']) { ?>
                    <span class="badge sent">есть в CLI</span>
                <?php } else { ?>
                    <span class="badge muted">нет — воркер не поймает SIGTERM, остановка будет жёсткой</span>
                <?php } ?>
            </dd>
        </dl>
    </div>
</div>

<?php if (View::can(Permission::SYSTEM_MANAGE)) { ?>
<div class="card">
    <h2>Обслуживание</h2>
    <div class="row">
        <form method="post" action="<?= View::e(View::route('ui.system.action', ['action' => 'migrate'])) ?>">
            <?= View::csrf() ?>
            <button type="submit">Применить миграции</button>
        </form>
        <form method="post" action="<?= View::e(View::route('ui.system.action', ['action' => 'worker-once'])) ?>">
            <?= View::csrf() ?>
            <button type="submit">Разовый проход воркера</button>
        </form>
        <form method="post" action="<?= View::e(View::route('ui.system.action', ['action' => 'restart-worker'])) ?>"
              onsubmit="return confirm('Перезапустить воркер? Он доработает текущую пачку и выйдет, systemd поднимет его снова.')">
            <?= View::csrf() ?>
            <button type="submit">Перезапустить воркер</button>
        </form>
        <form method="post" action="<?= View::e(View::route('ui.system.action', ['action' => 'requeue'])) ?>">
            <?= View::csrf() ?>
            <button type="submit">Вернуть зависшие письма</button>
        </form>
        <form method="post" action="<?= View::e(View::route('ui.system.action', ['action' => 'cleanup-counters'])) ?>">
            <?= View::csrf() ?>
            <button type="submit">Убрать старые счётчики</button>
        </form>
        <form method="post" action="<?= View::e(View::route('ui.system.action', ['action' => 'reset-counters'])) ?>" onsubmit="return confirm('Сбросить все счётчики лимитов?')">
            <?= View::csrf() ?>
            <button class="danger" type="submit">Сбросить лимиты</button>
        </form>
    </div>

    <form method="post" action="<?= View::e(View::route('ui.system.action', ['action' => 'purge'])) ?>" style="margin-top:14px" onsubmit="return confirm('Удалить письма? Отменить это будет нельзя.')">
        <?= View::csrf() ?>
        <div class="row">
            <label style="margin:0">
                <span>Удалить письма в статусе</span>
                <select name="status">
                    <option value="sent">отправленные</option>
                    <option value="failed">неудачные</option>
                    <option value="canceled">отменённые</option>
                </select>
            </label>
            <label style="margin:0">
                <span>старше, дней</span>
                <input type="number" name="days" value="30" style="width:100px">
            </label>
            <button class="danger" type="submit" style="align-self:end">Удалить</button>
        </div>
    </form>
</div>
<?php } ?>

<div class="grid cols-2">
    <div class="card">
        <h2>Счётчики лимитов</h2>
        <div class="table-wrap">
            <table class="list">
                <tr class="head"><th>Ключ</th><th>Значение</th><th>Сбросится</th></tr>
                <?php foreach ($counters as $counter) { ?>
                    <tr>
                        <td class="mono small"><?= View::e((string) $counter['counter_key']) ?></td>
                        <td><?= (int) $counter['value'] ?></td>
                        <td class="small"><?= View::e(View::date($counter['expires_at'])) ?></td>
                    </tr>
                <?php } ?>
                <?php if ($counters === []) { ?>
                    <tr><td colspan="3" class="muted">Пока пусто</td></tr>
                <?php } ?>
            </table>
        </div>
    </div>

    <div class="card">
        <h2>Служебные значения</h2>
        <div class="table-wrap">
            <table class="list">
                <tr class="head"><th>Ключ</th><th>Значение</th><th>Обновлено</th></tr>
                <?php foreach ($settings as $key => $item) { ?>
                    <tr>
                        <td class="mono small"><?= View::e($key) ?></td>
                        <td class="mono small break"><?= View::e(mb_strimwidth($item['value'], 0, 120, '…')) ?></td>
                        <td class="small"><?= View::e(View::date($item['updated_at'])) ?></td>
                    </tr>
                <?php } ?>
                <?php if ($settings === []) { ?>
                    <tr><td colspan="3" class="muted">Пока пусто</td></tr>
                <?php } ?>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <h2>Настройки сервиса</h2>
    <p class="muted small">Значения из config/config.php и .env. Пароли и ключи скрыты.</p>
    <pre><?= View::e((string) json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) ?></pre>
</div>
