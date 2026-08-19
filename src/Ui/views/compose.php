<?php

declare(strict_types=1);

/**
 * Форма отправки письма прямо из панели.
 *
 * @var array<int, array<string, mixed>> $transports
 * @var array<int, array<string, mixed>> $templates
 * @var array<int, array<string, mixed>> $projects
 * @var array<string, string> $prefill
 */

use Mailer\Ui\View;

$value = static fn (string $key): string => View::e($prefill[$key] ?? '');
?>
<h1>Написать письмо</h1>

<form method="post" action="<?= View::e(View::route('ui.compose')) ?>">
    <div class="grid cols-2">
        <div class="card">
            <h2>Кому и от кого</h2>

            <label>
                <span>Кому (через запятую)</span>
                <input type="text" name="to" required value="<?= $value('to') ?>" placeholder="user@example.com, Иван &lt;ivan@example.com&gt;">
            </label>

            <label>
                <span>Копия</span>
                <input type="text" name="cc" placeholder="необязательно">
            </label>

            <label>
                <span>Скрытая копия</span>
                <input type="text" name="bcc" placeholder="необязательно">
            </label>

            <label>
                <span>От кого (пусто — возьмём из настроек транспорта)</span>
                <input type="text" name="from" value="<?= $value('from') ?>" placeholder="Сервис &lt;noreply@example.com&gt;">
            </label>

            <label>
                <span>Обратный адрес</span>
                <input type="text" name="reply_to" placeholder="необязательно">
            </label>

            <label>
                <span>Метка</span>
                <input type="text" name="tag" placeholder="например, ручная отправка">
            </label>
        </div>

        <div class="card">
            <h2>Куда отправлять</h2>

            <label>
                <span>Транспорт</span>
                <select name="transport_id">
                    <option value="">по умолчанию</option>
                    <?php foreach ($transports as $transport) { ?>
                        <option value="<?= (int) $transport['id'] ?>" <?= ($prefill['transport'] ?? '') === (string) $transport['id'] ? 'selected' : '' ?>>
                            <?= View::e($transport['name']) ?> (<?= View::e(View::transportType((string) $transport['type'])) ?>)<?= (int) $transport['is_default'] === 1 ? ' — основной' : '' ?>
                        </option>
                    <?php } ?>
                </select>
            </label>

            <label>
                <span>От имени проекта (для статистики и лимитов)</span>
                <select name="project_id">
                    <option value="">без проекта</option>
                    <?php foreach ($projects as $project) { ?>
                        <option value="<?= (int) $project['id'] ?>"><?= View::e($project['name']) ?></option>
                    <?php } ?>
                </select>
            </label>

            <label>
                <span>Шаблон (тема и тело возьмутся из него, если оставить поля пустыми)</span>
                <select name="template">
                    <option value="">не использовать</option>
                    <?php foreach ($templates as $template) { ?>
                        <option value="<?= View::e($template['name']) ?>"><?= View::e($template['name']) ?></option>
                    <?php } ?>
                </select>
            </label>

            <label>
                <span>Данные шаблона (JSON)</span>
                <textarea name="template_data" style="min-height:80px" placeholder='{"name": "Иван", "site": "example.com"}'></textarea>
            </label>

            <label class="inline">
                <input type="checkbox" name="sync" checked>
                <span>Отправить сразу, не дожидаясь воркера</span>
            </label>
        </div>
    </div>

    <div class="card">
        <h2>Письмо</h2>

        <label>
            <span>Тема</span>
            <input type="text" name="subject" value="<?= $value('subject') ?>">
        </label>

        <label>
            <span>Текстовая версия</span>
            <textarea name="text"><?= $value('text') ?></textarea>
        </label>

        <label>
            <span>HTML-версия</span>
            <textarea name="html"><?= $value('html') ?></textarea>
        </label>

        <div class="row">
            <button class="primary" type="submit">Отправить</button>
            <span class="muted small">вложения удобнее прикреплять через API или SDK</span>
        </div>
    </div>
</form>
