<?php

declare(strict_types=1);

/**
 * Страница отписки. Открывается по ссылке из письма, поэтому она сама по себе —
 * без меню панели, стилей layout и всего, что требует входа.
 *
 * @var string $title
 * @var string $text
 * @var string|null $token есть — показываем кнопку, нет — просто сообщение
 */

use Mailer\Http\Router;
use Mailer\Ui\View;
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($title) ?></title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f5f6f8;
            color: #1f2430;
            font: 16px/1.5 -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
        }
        .card {
            max-width: 460px;
            margin: 20px;
            padding: 28px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, .07);
            text-align: center;
        }
        h1 { margin: 0 0 12px; font-size: 22px; }
        p { margin: 0 0 20px; color: #55606f; }
        button {
            padding: 12px 24px;
            border: 0;
            border-radius: 8px;
            background: #2f6fed;
            color: #fff;
            font-size: 16px;
            cursor: pointer;
        }
        button:hover { background: #2559c4; }
    </style>
</head>
<body>
    <div class="card">
        <h1><?= View::e($title) ?></h1>
        <p><?= View::e($text) ?></p>

        <?php if ($token !== null) { ?>
            <form method="post" action="<?= View::e(Router::url('unsubscribe.submit', ['token' => $token])) ?>">
                <button type="submit">Отписаться</button>
            </form>
        <?php } ?>
    </div>
</body>
</html>
