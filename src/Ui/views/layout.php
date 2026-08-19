<?php

declare(strict_types=1);

/**
 * Каркас всех страниц панели: шапка, меню, сообщения и стили.
 *
 * @var string $content
 * @var string $title
 * @var array<int, array{message: string, type: string}> $flash
 * @var string $active
 * @var array<string, mixed>|null $user вошедший пользователь, если авторизация включена
 * @var bool $bare страница входа — без шапки и меню
 */

use Mailer\Ui\View;

$user = $user ?? null;
$bare = $bare ?? false;

$menu = [
    'dashboard'  => ['Обзор', '/'],
    'messages'   => ['Письма', '/messages'],
    'compose'    => ['Написать', '/compose'],
    'transports' => ['Транспорты', '/transports'],
    'projects'   => ['Проекты', '/projects'],
    'templates'  => ['Шаблоны', '/templates'],
    'webhooks'   => ['Вебхуки', '/webhooks'],
    'users'      => ['Пользователи', '/users'],
    'logs'       => ['Логи', '/logs'],
    'system'     => ['Состояние', '/system'],
];
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($title) ?> — почтовый сервис</title>
    <style>
        :root {
            --bg: #f5f6f8;
            --panel: #ffffff;
            --border: #e2e5ea;
            --text: #1d2129;
            --muted: #6b7280;
            --accent: #2563eb;
            --ok: #15803d;
            --ok-bg: #dcfce7;
            --warn: #b45309;
            --warn-bg: #fef3c7;
            --err: #b91c1c;
            --err-bg: #fee2e2;
            --info-bg: #dbeafe;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #14161a;
                --panel: #1c1f25;
                --border: #2c313a;
                --text: #e5e7eb;
                --muted: #9ca3af;
                --accent: #60a5fa;
                --ok: #4ade80;
                --ok-bg: #14321f;
                --warn: #fbbf24;
                --warn-bg: #3a2d0c;
                --err: #f87171;
                --err-bg: #3b1717;
                --info-bg: #17293f;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font: 14px/1.5 -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }

        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }

        header {
            background: var(--panel);
            border-bottom: 1px solid var(--border);
            padding: 0 20px;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .brand {
            display: flex;
            align-items: baseline;
            gap: 12px;
            padding: 14px 0 10px;
        }

        .brand b { font-size: 17px; }
        .brand span { color: var(--muted); font-size: 12px; }
        .brand .who { margin-left: auto; display: flex; align-items: center; gap: 10px; }
        .brand .who form { display: inline; }

        .auth { max-width: 380px; margin: 8vh auto 0; }
        .auth h1 { text-align: center; }

        nav { display: flex; gap: 4px; flex-wrap: wrap; }

        nav a {
            padding: 8px 12px;
            border-radius: 6px 6px 0 0;
            color: var(--text);
            font-weight: 500;
        }

        nav a:hover { background: var(--bg); text-decoration: none; }
        nav a.active { background: var(--accent); color: #fff; }

        main { padding: 20px; max-width: 1400px; margin: 0 auto; }

        h1 { font-size: 20px; margin: 0 0 16px; }
        h2 { font-size: 16px; margin: 0 0 12px; }

        .card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 16px;
        }

        .card > h2:first-child { margin-top: 0; }

        .grid { display: grid; gap: 16px; }
        .grid.cols-2 { grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); }
        .grid.cols-4 { grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); }

        .stat { text-align: left; }
        .stat .value { font-size: 26px; font-weight: 600; }
        .stat .label { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }

        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 8px 10px; text-align: left; border-bottom: 1px solid var(--border); vertical-align: top; }
        th { color: var(--muted); font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; }
        tr:last-child td { border-bottom: none; }
        .table-wrap { overflow-x: auto; }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }

        .badge.queued { background: var(--info-bg); color: var(--accent); }
        .badge.sending { background: var(--warn-bg); color: var(--warn); }
        .badge.sent { background: var(--ok-bg); color: var(--ok); }
        .badge.failed { background: var(--err-bg); color: var(--err); }
        .badge.canceled { background: var(--border); color: var(--muted); }
        .badge.delivered { background: var(--ok-bg); color: var(--ok); }
        .badge.muted { background: var(--border); color: var(--muted); }

        input[type=text], input[type=email], input[type=number], input[type=password],
        input[type=date], select, textarea {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--bg);
            color: var(--text);
            font: inherit;
        }

        textarea { min-height: 120px; font-family: ui-monospace, Consolas, monospace; font-size: 13px; }

        label { display: block; margin-bottom: 12px; }
        label > span { display: block; margin-bottom: 4px; color: var(--muted); font-size: 12px; }
        label.inline { display: inline-flex; align-items: center; gap: 8px; }
        label.inline > span { margin: 0; color: var(--text); font-size: 14px; }

        button, .btn {
            display: inline-block;
            padding: 8px 14px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--panel);
            color: var(--text);
            font: inherit;
            cursor: pointer;
        }

        /* Всё, по чему можно щёлкнуть, должно об этом говорить курсором */
        a,
        select,
        summary,
        input[type=checkbox],
        input[type=radio],
        input[type=submit],
        input[type=file],
        label.inline,
        label.inline > span,
        .pagination a,
        .chart .bar {
            cursor: pointer;
        }

        button:disabled, .btn:disabled { cursor: not-allowed; opacity: .6; }

        button:hover, .btn:hover { border-color: var(--accent); text-decoration: none; }
        button.primary, .btn.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
        button.danger, .btn.danger { color: var(--err); }

        .row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .row.end { justify-content: flex-end; }
        .spacer { flex: 1; }

        .filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; align-items: end; }
        .filters label { margin: 0; }

        .flash { padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; word-break: break-word; }
        .flash.ok { background: var(--ok-bg); color: var(--ok); }
        .flash.error { background: var(--err-bg); color: var(--err); }

        .muted { color: var(--muted); }
        .mono { font-family: ui-monospace, Consolas, monospace; font-size: 12px; }
        .nowrap { white-space: nowrap; }
        .small { font-size: 12px; }

        pre {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 12px;
            overflow-x: auto;
            font-size: 12px;
            margin: 0;
            white-space: pre-wrap;
            word-break: break-word;
        }

        iframe.preview {
            width: 100%;
            min-height: 320px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #fff;
        }

        .chart { display: flex; align-items: flex-end; gap: 4px; height: 140px; }
        .chart .bar { flex: 1; display: flex; flex-direction: column; justify-content: flex-end; gap: 2px; }
        .chart .bar .sent { background: var(--ok); border-radius: 3px 3px 0 0; }
        .chart .bar .failed { background: var(--err); }
        .chart .bar .day { text-align: center; font-size: 10px; color: var(--muted); margin-top: 4px; }

        .pagination { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 14px; }
        .pagination a, .pagination span { padding: 6px 10px; border: 1px solid var(--border); border-radius: 6px; }
        .pagination .current { background: var(--accent); border-color: var(--accent); color: #fff; }

        dl.props { display: grid; grid-template-columns: 200px 1fr; gap: 6px 16px; margin: 0; }
        dl.props dt { color: var(--muted); }
        dl.props dd { margin: 0; word-break: break-word; }

        .tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border); margin-bottom: 12px; }
        .tabs a { padding: 8px 12px; color: var(--text); }
        .tabs a.active { border-bottom: 2px solid var(--accent); font-weight: 600; }
    </style>
</head>
<body>
<?php if (!$bare) { ?>
<header>
    <div class="brand">
        <b>Почтовый сервис</b>
        <span>панель управления</span>

        <?php if ($user !== null) { ?>
            <span class="who">
                <span class="muted small"><?= View::e((string) ($user['name'] ?? $user['login'])) ?></span>
                <form method="post" action="<?= View::e(View::url('/logout')) ?>">
                    <button type="submit">Выйти</button>
                </form>
            </span>
        <?php } ?>
    </div>
    <nav>
        <?php foreach ($menu as $key => [$label, $path]) { ?>
            <a href="<?= View::e(View::url($path)) ?>" class="<?= $active === $key ? 'active' : '' ?>"><?= View::e($label) ?></a>
        <?php } ?>
    </nav>
</header>
<?php } ?>

<main>
    <?php foreach ($flash as $item) { ?>
        <div class="flash <?= View::e($item['type']) ?>"><?= View::e($item['message']) ?></div>
    <?php } ?>

    <?= $content ?>
</main>
</body>
</html>
