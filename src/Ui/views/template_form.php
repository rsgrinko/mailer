<?php

declare(strict_types=1);

/**
 * Правка шаблона с предпросмотром.
 *
 * @var array<string, mixed>|null $template
 * @var array<int, string> $variables
 * @var array{subject: string, html: string, text: string}|null $preview
 * @var string $sample
 */

use Mailer\Ui\View;

$isNew = $template === null;
?>
<div class="row">
    <h1 style="margin:0"><?= $isNew ? 'Новый шаблон' : 'Шаблон «' . View::e((string) $template['name']) . '»' ?></h1>
    <div class="spacer"></div>
    <a class="btn" href="<?= View::e(View::url('/templates')) ?>">К списку</a>
</div>

<form method="post" action="<?= View::e(View::url('/templates/save')) ?>">
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

        <?php if ($variables !== []): ?>
            <p class="small muted">В шаблоне используются переменные: <span class="mono"><?= View::e(implode(', ', $variables)) ?></span></p>
        <?php endif; ?>

        <div class="row">
            <button class="primary" type="submit">Сохранить</button>
            <?php if (!$isNew): ?>
                <div class="spacer"></div>
            <?php endif; ?>
        </div>
    </div>
</form>

<?php if (!$isNew): ?>
    <div class="card">
        <h2>Предпросмотр</h2>
        <form method="get" action="<?= View::e(View::url('/templates/' . $template['id'])) ?>">
            <label>
                <span>Данные для подстановки (JSON)</span>
                <input type="text" name="sample" value="<?= View::e($sample) ?>" placeholder='{"name": "Иван", "site": "example.com"}'>
            </label>
            <button type="submit">Показать</button>
        </form>

        <?php if ($preview !== null): ?>
            <div style="margin-top:14px">
                <div class="small muted">Тема</div>
                <pre><?= View::e($preview['subject']) ?></pre>

                <?php if ($preview['html'] !== ''): ?>
                    <div class="small muted" style="margin-top:10px">HTML</div>
                    <iframe class="preview" sandbox srcdoc="<?= View::e($preview['html']) ?>"></iframe>
                <?php endif; ?>

                <?php if ($preview['text'] !== ''): ?>
                    <div class="small muted" style="margin-top:10px">Текст</div>
                    <pre><?= View::e($preview['text']) ?></pre>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <form method="post" action="<?= View::e(View::url('/templates/' . $template['id'] . '/delete')) ?>" onsubmit="return confirm('Удалить шаблон?')">
            <button class="danger" type="submit">Удалить шаблон</button>
        </form>
    </div>
<?php endif; ?>
