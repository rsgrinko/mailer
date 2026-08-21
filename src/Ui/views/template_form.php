<?php

declare(strict_types=1);

/**
 * Правка шаблона с предпросмотром.
 *
 * @var array<string, mixed>|null $template
 * @var array<int, string> $variables
 * @var array{subject: string, html: string, text: string}|null $preview
 * @var string $sample
 * @var bool $editable можно ли править: без права templates.manage шаблон только показываем
 */

use Mailer\Domain\Permission;
use Mailer\Ui\View;

$isNew = $template === null;
?>
<div class="row">
    <h1 style="margin:0"><?= $isNew ? 'Новый шаблон' : 'Шаблон «' . View::e((string) $template['name']) . '»' ?></h1>
    <div class="spacer"></div>
    <a class="btn" href="<?= View::e(View::route('ui.templates')) ?>">К списку</a>
</div>

<?php if ($editable) { ?>
<form method="post" action="<?= View::e(View::route('ui.templates.save')) ?>">
    <?= View::csrf() ?>
    <input type="hidden" name="id" value="<?= (int) ($template['id'] ?? 0) ?>">

    <div class="card" style="margin-top:16px">
        <div class="row">
            <label style="flex:1">
                <span>Имя (его указывают в API)</span>
                <input type="text" name="name" required value="<?= View::e((string) ($template['name'] ?? '')) ?>" placeholder="welcome">
            </label>
            <label style="flex:2">
                <span>Описание</span>
                <input type="text" name="description" value="<?= View::e((string) ($template['description'] ?? '')) ?>">
            </label>
        </div>

        <label>
            <span>Тема</span>
            <input type="text" name="subject" value="<?= View::e((string) ($template['subject'] ?? '')) ?>" placeholder="Здравствуйте, {{ name }}!">
        </label>

        <label>
            <span>HTML-версия</span>
            <textarea name="html" style="min-height:200px"><?= View::e((string) ($template['html'] ?? '')) ?></textarea>
        </label>

        <label>
            <span>Текстовая версия</span>
            <textarea name="text"><?= View::e((string) ($template['text'] ?? '')) ?></textarea>
        </label>

        <?php if ($variables !== []) { ?>
            <p class="small muted">В шаблоне используются переменные: <span class="mono"><?= View::e(implode(', ', $variables)) ?></span></p>
        <?php } ?>

        <div class="row">
            <button class="primary" type="submit">Сохранить</button>
            <?php if (!$isNew) { ?>
                <div class="spacer"></div>
            <?php } ?>
        </div>
    </div>
</form>
<?php } else { ?>
    <div class="card" style="margin-top:16px">
        <?= View::partial('props', ['rows' => [
            'Имя'         => $template['name'] ?? '',
            'Описание'    => $template['description'] ?? '',
            'Тема'        => $template['subject'] ?? '',
            'Переменные'  => implode(', ', $variables),
        ]]) ?>

        <?php if ((string) ($template['html'] ?? '') !== '') { ?>
            <div class="small muted" style="margin-top:12px">HTML-версия</div>
            <pre><?= View::e((string) $template['html']) ?></pre>
        <?php } ?>

        <?php if ((string) ($template['text'] ?? '') !== '') { ?>
            <div class="small muted" style="margin-top:12px">Текстовая версия</div>
            <pre><?= View::e((string) $template['text']) ?></pre>
        <?php } ?>

        <p class="muted small">Шаблон доступен вам только на просмотр: права на правку
            (<span class="mono">templates.manage</span>) у роли нет.</p>
    </div>
<?php } ?>

<?php if (!$isNew) { ?>
    <div class="card">
        <h2>Предпросмотр</h2>
        <form method="get" action="<?= View::e(View::route('ui.templates.show', ['id' => $template['id']])) ?>">
            <label>
                <span>Данные для подстановки (JSON)</span>
                <input type="text" name="sample" value="<?= View::e($sample) ?>" placeholder='{"name": "Иван", "site": "example.com"}'>
            </label>
            <div class="row">
                <button type="submit">Показать</button>
                <a class="btn" href="<?= View::e(View::route('ui.templates.show', ['id' => $template['id'], 'sample' => 'auto'])) ?>">Заполнить примером</a>
            </div>
        </form>

        <?php if ($preview !== null) { ?>
            <div style="margin-top:14px">
                <div class="small muted">Тема</div>
                <pre><?= View::e($preview['subject']) ?></pre>

                <?php if ($preview['html'] !== '') { ?>
                    <div class="small muted" style="margin-top:10px">HTML</div>
                    <iframe class="preview" sandbox srcdoc="<?= View::e($preview['html']) ?>"></iframe>
                <?php } ?>

                <?php if ($preview['text'] !== '') { ?>
                    <div class="small muted" style="margin-top:10px">Текст</div>
                    <pre><?= View::e($preview['text']) ?></pre>
                <?php } ?>
            </div>
        <?php } ?>
    </div>

    <?php if (View::can(Permission::MESSAGES_SEND)) { ?>
        <div class="card">
            <h2>Пробное письмо</h2>
            <p class="small muted">Уйдёт через транспорт по умолчанию, с теми же данными, что и в предпросмотре.</p>
            <form method="post" action="<?= View::e(View::route('ui.templates.action', ['id' => $template['id'], 'action' => 'send'])) ?>">
                <?= View::csrf() ?>
                <input type="hidden" name="sample" value="<?= View::e($sample) ?>">
                <div class="row">
                    <label style="flex:1; margin:0">
                        <span>Кому</span>
                        <input type="email" name="to" required placeholder="ivan@example.com">
                    </label>
                    <button class="primary" type="submit" style="align-self:end">Отправить</button>
                </div>
            </form>
        </div>
    <?php } ?>

    <?php if ($editable) { ?>
        <div class="card">
            <form method="post" action="<?= View::e(View::route('ui.templates.action', ['id' => $template['id'], 'action' => 'delete'])) ?>" onsubmit="return confirm('Удалить шаблон?')">
                <?= View::csrf() ?>
                <button class="danger" type="submit">Удалить шаблон</button>
            </form>
        </div>
    <?php } ?>
<?php } ?>
