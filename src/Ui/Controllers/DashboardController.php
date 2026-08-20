<?php

declare(strict_types=1);

namespace Mailer\Ui\Controllers;

use Mailer\Domain\Scope;
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
use Mailer\Support\Cache;
use Mailer\Support\Config;
use Mailer\Support\Logger;
use Mailer\Ui\Audit;
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

    public function __construct(
        MessageRepository $messages,
        EventRepository $events,
        SettingRepository $settings,
        TransportRepository $transports,
        ProjectRepository $projects,
        WebhookRepository $webhooks
    ) {
        $this->messages   = $messages;
        $this->events     = $events;
        $this->settings   = $settings;
        $this->transports = $transports;
        $this->projects   = $projects;
        $this->webhooks   = $webhooks;
    }

    /**
     * Дашборд: цифры, график, состояние воркера и последние события.
     */
    public function index(Request $request, Scope $scope): Response
    {
        // График за две недели считается по всей таблице, поэтому держим его
        // несколько секунд в кэше: на обзоре секундная точность не нужна.
        // Ключ кэша — с владельцем, иначе первый зашедший покажет свой график всем
        $ttl = (int) Config::get('ui.stats_cache', 30);

        $stats = $this->messages->stats($scope);
        $daily = Cache::remember(
            'dashboard:daily:' . $scope->ownerId(),
            $ttl,
            fn (): array => $this->messages->dailyStats(14, $scope)
        );

        return Response::html(View::render('dashboard', [
            'active'     => 'dashboard',
            'stats'      => $stats,
            'daily'      => $daily,
            'worker'     => $this->workerState(),
            'recent'     => $this->messages->paginate([], 1, 10, $scope)['items'],
            'failed'     => $this->messages->paginate(['status' => MessageRepository::FAILED], 1, 5, $scope)['items'],
            'events'     => $this->events->latest(12, $scope),
            'transports' => $this->transports->all(false, $scope),
            'webhooks'   => $this->webhooks->countByStatus($scope),
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

        $counters = $limiter->all();

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
                Audit::action('system', null, $applied === [] ? 'миграции: новых нет' : 'применены миграции: ' . implode(', ', $applied));
                View::flash($applied === [] ? 'Новых миграций нет' : 'Применены миграции: ' . implode(', ', $applied));
                break;

            case 'requeue':
                $count = (new Queue($db))->requeueStuck();
                Audit::action('system', null, 'возвращено зависших писем: ' . $count);
                View::flash('Возвращено в очередь зависших писем: ' . $count);
                break;

            case 'cleanup-counters':
                $count = (new RateLimiter($db))->cleanup();
                Audit::action('system', null, 'удалено устаревших счётчиков: ' . $count);
                View::flash('Удалено устаревших счётчиков: ' . $count);
                break;

            case 'reset-counters':
                (new RateLimiter($db))->resetAll();
                Audit::action('system', null, 'счётчики лимитов сброшены');
                View::flash('Счётчики лимитов сброшены');
                break;

            case 'purge':
                $days    = (int) ($request->input('days', 30));
                $status  = (string) $request->input('status', MessageRepository::SENT);
                $deleted = $this->messages->purge($status, $days);
                Audit::action('system', null, 'чистка писем со статусом «' . $status . '» старше ' . $days . ' дней, удалено: ' . $deleted);
                View::flash('Удалено писем: ' . $deleted);
                break;

            case 'restart-worker':
                Worker::requestRestart($db);
                Audit::action('system', null, 'запрошен перезапуск воркера');
                View::flash('Воркер получит запрос в ближайшие секунды и перезапустится');
                break;

            case 'worker-once':
                $processed = (new Worker($db, static function (string $line): void {
                }))->run(true);
                Audit::action('system', null, 'разовый проход воркера, обработано писем: ' . $processed);
                View::flash('Разовый проход воркера завершён, обработано писем: ' . $processed);
                break;

            default:
                View::flash('Неизвестное действие: ' . $action, 'error');
        }

        return Response::redirect(View::route('ui.system'));
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
            'php'       => $data['php'] ?? null,
            'pcntl'     => $data['pcntl'] ?? null,
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
        $tables = ['messages', 'message_events', 'projects', 'transports', 'templates', 'webhook_deliveries', 'counters', 'settings', 'users', 'audit_log'];
        $result = [];

        foreach ($tables as $table) {
            $result[$table] = $db->count($table);
        }

        return $result;
    }
}
