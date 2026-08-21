<?php

declare(strict_types=1);

/**
 * Создание и правка транспорта.
 *
 * @var array<string, mixed>|null $transport
 * @var array<int, array<string, mixed>> $all
 * @var array<int, array<string, mixed>> $owners пользователи для выбора владельца (только администратору)
 * @var bool $canShare можно ли делать транспорт общим и основным
 * @var bool $readOnly чужой или общий транспорт: смотреть можно, менять нельзя
 */

use Mailer\Ui\View;

$settings = (array) ($transport['settings'] ?? []);
$dkim     = (array) ($settings['dkim'] ?? []);
$type     = (string) ($transport['type'] ?? 'smtp');
$isNew    = $transport === null;
?>
<div class="row">
    <h1 style="margin:0"><?= $isNew ? 'Новый транспорт' : 'Транспорт «' . View::e((string) $transport['name']) . '»' ?></h1>
    <div class="spacer"></div>
    <a class="btn" href="<?= View::e(View::route('ui.transports')) ?>">К списку</a>
</div>

<form method="post" action="<?= View::e(View::route('ui.transports.save')) ?>">
    <?= View::csrf() ?>
    <input type="hidden" name="id" value="<?= (int) ($transport['id'] ?? 0) ?>">

    <div class="grid cols-2" style="margin-top:16px">
        <div class="card">
            <h2>Основное</h2>

            <label>
                <span>Имя (по нему транспорт выбирается в API)</span>
                <input type="text" name="name" required value="<?= View::e((string) ($transport['name'] ?? '')) ?>" placeholder="yandex">
            </label>

            <label>
                <span>Тип</span>
                <select name="type">
                    <?php foreach ([
                        'smtp'       => 'SMTP — внешний почтовый сервер',
                        'sendmail'   => 'sendmail — локальная почтовая система',
                        'log'        => 'log — складывать письма в файлы',
                        'null'       => 'null — ничего не отправлять',
                        'failover'   => 'цепочка — пробовать по очереди',
                        'roundrobin' => 'по кругу — чередовать транспорты',
                    ] as $value => $label) { ?>
                        <option value="<?= View::e($value) ?>" <?= $type === $value ? 'selected' : '' ?>><?= View::e($label) ?></option>
                    <?php } ?>
                </select>
            </label>

            <label>
                <span>Отправитель по умолчанию (адрес)</span>
                <input type="text" name="from_email" value="<?= View::e((string) ($transport['from_email'] ?? '')) ?>" placeholder="noreply@example.com">
            </label>

            <label class="inline">
                <input type="checkbox" name="force_from" <?= ($settings['force_from'] ?? false) ? 'checked' : '' ?>>
                <span>Всегда отправлять с этого адреса</span>
            </label>
            <p class="muted small" style="margin:4px 0 16px 26px">Адрес отправителя в письме заменяется
                на указанный выше, прежний уходит в Reply-To, имя остаётся своё. Нужно для Яндекса и
                подобных: они шлют только от имени своего аккаунта и отвергают чужой адрес ответом
                «553 Sender address rejected».</p>

            <label>
                <span>Отправитель по умолчанию (имя)</span>
                <input type="text" name="from_name" value="<?= View::e((string) ($transport['from_name'] ?? '')) ?>">
            </label>

            <div class="row">
                <label style="flex:1">
                    <span>Приоритет (меньше — раньше)</span>
                    <input type="number" name="priority" value="<?= (int) ($transport['priority'] ?? 100) ?>">
                </label>
                <label style="flex:1">
                    <span>Суточный лимит (0 — без ограничений)</span>
                    <input type="number" name="daily_limit" value="<?= (int) ($transport['daily_limit'] ?? 0) ?>">
                </label>
            </div>

            <label class="inline">
                <input type="checkbox" name="active" <?= $isNew || (int) $transport['active'] === 1 ? 'checked' : '' ?>>
                <span>Включён</span>
            </label>

            <?php if ($canShare) { ?>
                <br>
                <label class="inline">
                    <input type="checkbox" name="is_default" <?= !$isNew && (int) $transport['is_default'] === 1 ? 'checked' : '' ?>>
                    <span>Использовать по умолчанию</span>
                </label>
                <br>
                <label class="inline">
                    <input type="checkbox" name="shared" <?= !$isNew && (int) ($transport['shared'] ?? 0) === 1 ? 'checked' : '' ?>>
                    <span>Общий: виден всем пользователям</span>
                </label>

                <label>
                    <span>Владелец</span>
                    <select name="owner_id">
                        <option value="0">— общий, без владельца —</option>
                        <?php foreach ($owners as $owner) { ?>
                            <option value="<?= (int) $owner['id'] ?>" <?= (int) ($transport['owner_id'] ?? 0) === (int) $owner['id'] ? 'selected' : '' ?>>
                                <?= View::e((string) $owner['login']) ?>
                            </option>
                        <?php } ?>
                    </select>
                </label>
            <?php } ?>
        </div>

        <div class="card">
            <h2>Настройки подключения</h2>
            <p class="muted small">Заполняйте блок, который соответствует выбранному типу — остальные поля не сохранятся.</p>

            <h2 style="font-size:14px">SMTP</h2>
            <div class="row">
                <label style="flex:2">
                    <span>Сервер</span>
                    <input type="text" name="host" value="<?= View::e((string) ($settings['host'] ?? 'smtp.yandex.ru')) ?>">
                </label>
                <label style="flex:1">
                    <span>Порт</span>
                    <input type="number" name="port" value="<?= (int) ($settings['port'] ?? 465) ?>">
                </label>
                <label style="flex:1">
                    <span>Шифрование</span>
                    <select name="encryption">
                        <?php foreach (['ssl' => 'SSL (465)', 'tls' => 'STARTTLS (587)', 'none' => 'без шифрования'] as $value => $label) { ?>
                            <option value="<?= View::e($value) ?>" <?= (string) ($settings['encryption'] ?? 'ssl') === $value ? 'selected' : '' ?>><?= View::e($label) ?></option>
                        <?php } ?>
                    </select>
                </label>
            </div>

            <label>
                <span>Логин</span>
                <input type="text" name="username" value="<?= View::e((string) ($settings['username'] ?? '')) ?>" placeholder="user@yandex.ru">
            </label>

            <label>
                <span>Пароль <?= isset($settings['password']) && $settings['password'] !== '' ? '(сохранён, оставьте пустым чтобы не менять)' : '' ?></span>
                <input type="password" name="password" autocomplete="new-password" placeholder="<?= isset($settings['password']) && $settings['password'] !== '' ? '••••••••' : 'пароль приложения' ?>">
            </label>

            <div class="row">
                <label style="flex:1">
                    <span>Способ авторизации</span>
                    <select name="auth_mode">
                        <?php foreach (['auto' => 'выбрать самому', 'login' => 'LOGIN', 'plain' => 'PLAIN', 'cram-md5' => 'CRAM-MD5'] as $value => $label) { ?>
                            <option value="<?= View::e($value) ?>" <?= (string) ($settings['auth_mode'] ?? 'auto') === $value ? 'selected' : '' ?>><?= View::e($label) ?></option>
                        <?php } ?>
                    </select>
                </label>
                <label style="flex:1">
                    <span>Таймаут, с</span>
                    <input type="number" name="timeout" value="<?= (int) ($settings['timeout'] ?? 30) ?>">
                </label>
            </div>

            <label class="inline">
                <input type="checkbox" name="verify_peer" <?= ($settings['verify_peer'] ?? true) ? 'checked' : '' ?>>
                <span>Проверять сертификат сервера</span>
            </label>

            <label class="inline">
                <input type="checkbox" name="keepalive" <?= ($settings['keepalive'] ?? true) ? 'checked' : '' ?>>
                <span>Не рвать соединение между письмами</span>
            </label>
            <p class="small muted">Подключение с TLS и авторизацией стоит дороже самого письма,
                поэтому очередь уходит в одну сессию. Выключать стоит, только если сервер этого
                не любит.</p>

            <label>
                <span>Писем в одной сессии</span>
                <input type="number" name="session_limit" value="<?= (int) ($settings['session_limit'] ?? 100) ?>">
            </label>
            <p class="small muted">Дальше сервис переподключится сам: 0 — не считать.</p>

            <h2 style="font-size:14px; margin-top:16px">sendmail</h2>
            <label>
                <span>Путь к бинарнику</span>
                <input type="text" name="path" value="<?= View::e((string) ($settings['path'] ?? '/usr/sbin/sendmail')) ?>">
            </label>

            <h2 style="font-size:14px">log</h2>
            <label>
                <span>Каталог для .eml-файлов</span>
                <input type="text" name="dir" value="<?= View::e((string) ($settings['dir'] ?? MAILER_ROOT . '/var/spool/sent')) ?>">
            </label>

            <h2 style="font-size:14px">Цепочка и чередование</h2>
            <label>
                <span>Имена транспортов через запятую</span>
                <input type="text" name="transports" value="<?= View::e(implode(', ', (array) ($settings['transports'] ?? []))) ?>" placeholder="yandex, backup, log">
                <?php if ($all !== []) { ?>
                    <span class="muted small">доступны: <?= View::e(implode(', ', array_column($all, 'name'))) ?></span>
                <?php } ?>
            </label>
        </div>
    </div>

    <div class="card">
        <h2>DKIM-подпись</h2>
        <p class="muted small">
            Нужна, когда письма уходят напрямую от вашего домена (через sendmail). При отправке
            через SMTP Яндекса подпись ставит сам Яндекс.
        </p>

        <label class="inline">
            <input type="checkbox" name="dkim_enabled" <?= ($dkim['enabled'] ?? false) ? 'checked' : '' ?>>
            <span>Подписывать письма</span>
        </label>

        <div class="row" style="margin-top:12px">
            <label style="flex:1">
                <span>Домен</span>
                <input type="text" name="dkim_domain" value="<?= View::e((string) ($dkim['domain'] ?? '')) ?>" placeholder="example.com">
            </label>
            <label style="flex:1">
                <span>Селектор</span>
                <input type="text" name="dkim_selector" value="<?= View::e((string) ($dkim['selector'] ?? 'mail')) ?>">
            </label>
        </div>

        <label>
            <span>Приватный ключ в формате PEM или путь к файлу<?= ($dkim['private_key'] ?? '') !== '' ? ' (сохранён, оставьте пустым чтобы не менять)' : '' ?></span>
            <textarea name="dkim_key" placeholder="-----BEGIN PRIVATE KEY-----"></textarea>
        </label>
    </div>

    <div class="card">
        <?php if ($readOnly) { ?>
            <p class="muted small" style="margin:0">
                Транспорт заведён администратором и доступен вам только на просмотр:
                отправлять через него можно, менять настройки — нет.
            </p>
        <?php } else { ?>
            <div class="row">
                <button class="primary" type="submit">Сохранить</button>

                <?php if (!$isNew) { ?>
                    <a class="btn" href="<?= View::e(View::route('ui.transports')) ?>">Отмена</a>
                    <div class="spacer"></div>
                <?php } ?>
            </div>
        <?php } ?>
    </div>
</form>

<?php if (!$isNew) { ?>
    <div class="card">
        <div class="row">
            <form method="post" action="<?= View::e(View::route('ui.transports.action', ['id' => $transport['id'], 'action' => 'test'])) ?>">
                <?= View::csrf() ?>
                <button type="submit">Проверить подключение</button>
            </form>
            <div class="spacer"></div>
            <?php if (!$readOnly) { ?>
                <form method="post" action="<?= View::e(View::route('ui.transports.action', ['id' => $transport['id'], 'action' => 'delete'])) ?>" onsubmit="return confirm('Удалить транспорт?')">
                    <?= View::csrf() ?>
                    <button class="danger" type="submit">Удалить транспорт</button>
                </form>
            <?php } ?>
        </div>
    </div>
<?php } ?>
