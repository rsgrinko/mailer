<?php

declare(strict_types=1);

namespace Mailer\Ui\Controllers;

use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Queue\Queue;
use Mailer\Queue\Worker;
use Mailer\RateLimit\RateLimiter;
use Mailer\Repository\EventRepository;
use Mailer\Repository\MessageRepository;
use Mailer\Repository\ProjectRepository;
use Mailer\Repository\SettingRepository;
use Mailer\Repository\TransportRepository;
use Mailer\Repository\WebhookRepository;
use Mailer\Security\Crypto;
use Mailer\Storage\Database;
use Mailer\Storage\Migrator;
use Mailer\Support\Config;
use Mailer\Support\Logger;
use Mailer\Ui\View;

/**
 * Главная страница панели, состояние сервиса и просмотр логов.
 */
final class DashboardController
{
    private MessageRepository $messages;
    private EventRepository $events;
    private SettingRepository $settings;
    private TransportRepository $transports;
    private ProjectRepository $projects;
    private WebhookRepository $webhooks;

    public function __construct()
    {
        $this->messages   = new MessageRepository();
        $this->events     = new EventRepository();
        $this->settings   = new SettingRepository();
        $this->transports = new TransportRepository();
        $this->projects   = new ProjectRepository();
        $this->webhooks   = new WebhookRepository();
    }

    /**
     * Дашборд: цифры, график, состояние воркера и последние события.
     */
    public function index(Request $request): Response
    {
        $stats = $this->messages->stats();

        return Response::html(View::render('dashboard', [
            'active'     => 'dashboard',
            'stats'      => $stats,
            'daily'      => $this->messages->dailyStats(14),
            'worker'     => $this->workerState(),
            'recent'     => $this->messages->paginate([], 1, 10)['items'],
            'failed'     => $this->messages->paginate(['status' => MessageRepository::FAILED], 1, 5)['items'],
            'events'     => $this->events->latest(12),
            'transports' => $this->transports->all(),
            'webhooks'   => $this->webhooks->countByStatus(),
        ], 'Обзор'));
    }

    /**
     * Состояние сервиса: настройки, база, счётчики, служебные значения.
     */
    public function system(Request $request): Response
    {
        $db       = Database::instance();
        $migrator = new Migrator($db);
        $limiter  = new RateLimiter($db);

        $counters = $db->select('SELECT * FROM counters ORDER BY counter_key');

        return Response::html(View::render('system', [
            'active'    => 'system',
            'driver'    => $db->driver(),
            'dbInfo'    => $this->databaseInfo($db),
            'pending'   => $migrator->pending(),
            'hasKey'    => Crypto::hasKey(),
            'config'    => $this->safeConfig(),
            'counters'  => $counters,
            'settings'  => $this->settings->all(),
            'worker'    => $this->workerState(),
            'tables'    => $this->tableSizes($db),
            'php'       => [
                'version'    => PHP_VERSION,
                'sapi'       => PHP_SAPI,
                'extensions' => [
                    'pdo_sqlite' => extension_loaded('pdo_sqlite'),
                    'pdo_mysql'  => extension_loaded('pdo_mysql'),
                    'openssl'    => extension_loaded('openssl'),
                    'mbstring'   => extension_loaded('mbstring'),
                    'curl'       => extension_loaded('curl'),
                    'pcntl'      => extension_loaded('pcntl'),
                ],
            ],
            'limiterUsage' => $limiter,
        ], 'Состояние сервиса'));
    }

    /**
     * Кнопки со страницы состояния.
     */
    public function systemAction(Request $request, string $action): Response
    {
        $db = Database::instance();

        switch ($action) {
            case 'migrate':
                $applied = (new Migrator($db))->run();
                View::flash($applied === [] ? 'Новых миграций нет' : 'Применены миграции: ' . implode(', ', $applied));
                break;

            case 'requeue':
                $count = (new Queue($db))->requeueStuck();
                View::flash('Возвращено в очередь зависших писем: ' . $count);
                break;

            case 'cleanup-counters':
                $count = (new RateLimiter($db))->cleanup();
                View::flash('Удалено устаревших счётчиков: ' . $count);
                break;

            case 'reset-counters':
                $db->execute('DELETE FROM counters');
                View::flash('Счётчики лимитов сброшены');
                break;

            case 'purge':
                $days    = (int) ($request->input('days', 30));
                $status  = (string) $request->input('status', MessageRepository::SENT);
                $deleted = $this->messages->purge($status, $days);
                View::flash('Удалено писем: ' . $deleted);
                break;

            case 'restart-worker':
                Worker::requestRestart($db);
                View::flash('Воркер получит запрос в ближайшие секунды и перезапустится');
                break;

            case 'worker-once':
                $processed = (new Worker($db, static function (string $line): void {
                }))->run(true);
                View::flash('Разовый проход воркера завершён, обработано писем: ' . $processed);
                break;

            default:
                View::flash('Неизвестное действие: ' . $action, 'error');
        }

        return Response::redirect(View::url('/system'));
    }

    /**
     * Просмотр файлов логов.
     */
    public function logs(Request $request): Response
    {
        $logger = new Logger('ui');
        $files  = $logger->files();
        $file   = (string) $request->query('file', $files[0]['name'] ?? '');
        $lines  = (int) $request->query('lines', 300);

        return Response::html(View::render('logs', [
            'active'  => 'logs',
            'files'   => $files,
            'current' => $file,
            'lines'   => $lines,
            'content' => $file !== '' ? $logger->tail($file, $lines) : '',
        ], 'Логи'));
    }

    /**
     * Что известно про воркер.
     *
     * @return array<string, mixed>
     */
    private function workerState(): array
    {
        $raw = $this->settings->get(Worker::HEARTBEAT_KEY);

        if ($raw === null) {
            return ['known' => false, 'alive' => false, 'time' => null, 'processed' => 0, 'worker' => null];
        }

        $data    = (array) json_decode($raw, true);
        $seconds = time() - (int) strtotime((string) ($data['time'] ?? 'now'));

        return [
            'known'     => true,
            'alive'     => $seconds < 120,
            'time'      => $data['time'] ?? null,
            'seconds'   => $seconds,
            'processed' => (int) ($data['processed'] ?? 0),
            'worker'    => $data['worker'] ?? null,
            'pid'       => $data['pid'] ?? null,
        ];
    }

    /**
     * Настройки без паролей — их показывать незачем.
     *
     * @return array<string, mixed>
     */
    private function safeConfig(): array
    {
        $config = Config::all();

        unset($config['db']['mysql']['password'], $config['app']['key'], $config['smtpd']['auth_password']);

        return $config;
    }

    /**
     * Размер базы и путь к ней.
     *
     * @return array<string, string>
     */
    private function databaseInfo(Database $db): array
    {
        if ($db->isSqlite()) {
            $path = (string) Config::get('db.sqlite.path', MAILER_ROOT . '/var/mailer.sqlite');

            return [
                'Файл'   => $path,
                'Размер' => is_file($path) ? \Mailer\Support\Str::bytes((int) filesize($path)) : 'нет файла',
            ];
        }

        return [
            'Хост' => (string) Config::get('db.mysql.host') . ':' . (string) Config::get('db.mysql.port'),
            'База' => (string) Config::get('db.mysql.database'),
        ];
    }

    /**
     * Сколько записей в каждой таблице.
     *
     * @return array<string, int>
     */
    private function tableSizes(Database $db): array
    {
        $tables = ['messages', 'message_events', 'projects', 'transports', 'templates', 'webhook_deliveries', 'counters', 'settings', 'users'];
        $result = [];

        foreach ($tables as $table) {
            $result[$table] = $db->hasTable($table) ? (int) $db->value('SELECT COUNT(*) FROM ' . $table) : 0;
        }

        return $result;
    }
}
