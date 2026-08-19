<?php

declare(strict_types=1);

namespace Mailer\Console;

use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Http\Router;
use Mailer\MailService;
use Mailer\Queue\Queue;
use Mailer\Queue\Worker;
use Mailer\RateLimit\RateLimiter;
use Mailer\Repository\MessageRepository;
use Mailer\Repository\ProjectRepository;
use Mailer\Repository\SettingRepository;
use Mailer\Repository\TemplateRepository;
use Mailer\Repository\TransportRepository;
use Mailer\Repository\UserRepository;
use Mailer\Repository\WebhookRepository;
use Mailer\Security\Crypto;
use Mailer\Security\Password;
use Mailer\Smtpd\SmtpServer;
use Mailer\Storage\Database;
use Mailer\Storage\Migrator;
use Mailer\Support\Config;
use Mailer\Support\Env;
use Mailer\Support\Logger;
use Mailer\Support\Str;
use Mailer\Transport\TransportFactory;
use Mailer\Webhook\WebhookSender;
use Throwable;

/**
 * Консольная утилита: `php bin/mailer <команда> [аргументы]`.
 * Все команды собраны здесь — так проще искать нужную.
 */
final class Application
{
    /** @var array<int, string> Позиционные аргументы */
    private array $args = [];

    /** @var array<string, string> Опции вида --name=value */
    private array $options = [];

    /**
     * @param array<int, string> $argv
     */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $this->parseArguments(array_slice($argv, 2));

        try {
            return match ($command) {
                'help', '--help', '-h' => $this->help(),
                'migrate'              => $this->migrate(),
                'seed'                 => $this->seed(),
                'app:key'              => $this->appKey(),
                'worker'               => $this->worker(),
                'worker:restart'       => $this->workerRestart(),
                'smtpd'                => $this->smtpd(),
                'status'               => $this->status(),

                'user:create'   => $this->userCreate(),
                'user:list'     => $this->userList(),
                'user:password' => $this->userPassword(),
                'user:delete'   => $this->userDelete(),

                'key:create'     => $this->keyCreate(),
                'key:list'       => $this->keyList(),
                'key:regenerate' => $this->keyRegenerate(),
                'key:revoke'     => $this->keyRevoke(),

                'transport:add'     => $this->transportAdd(),
                'transport:list'    => $this->transportList(),
                'transport:test'    => $this->transportTest(),
                'transport:default' => $this->transportDefault(),
                'transport:delete'  => $this->transportDelete(),

                'queue:status'  => $this->queueStatus(),
                'queue:retry'   => $this->queueRetry(),
                'queue:purge'   => $this->queuePurge(),
                'queue:cancel'  => $this->queueCancel(),

                'template:list'   => $this->templateList(),
                'template:delete' => $this->templateDelete(),

                'webhook:process' => $this->webhookProcess(),

                'send:test' => $this->sendTest(),
                'send'      => $this->send(),

                'route:list' => $this->routeList(),
                'logs:purge' => $this->logsPurge(),

                'test' => $this->runTests(),

                default => $this->unknown($command),
            };
        } catch (Throwable $e) {
            $this->line('ОШИБКА: ' . $e->getMessage());

            if ((bool) Config::get('app.debug', false)) {
                $this->line($e->getFile() . ':' . $e->getLine());
            }

            return 1;
        }
    }

    // --- Команды -------------------------------------------------------------

    private function help(): int
    {
        $this->line('Сервис отправки почты — консольная утилита');
        $this->line('');
        $this->line('Использование: php bin/mailer <команда> [аргументы]');
        $this->line('');
        $this->line('Установка и обслуживание:');
        $this->line('  migrate                        создать или обновить таблицы');
        $this->line('  seed                           добавить транспорт и шаблоны из .env');
        $this->line('  app:key                        сгенерировать ключ шифрования для .env');
        $this->line('  status                         общее состояние сервиса');
        $this->line('');
        $this->line('Работа очереди:');
        $this->line('  worker [--once] [--limit=N]    запустить воркер');
        $this->line('  worker:restart                 попросить работающий воркер перезапуститься');
        $this->line('  smtpd                          локальный SMTP-релей для чужих приложений');
        $this->line('  queue:status                   что сейчас в очереди');
        $this->line('  queue:retry <id|--failed>      повторить письмо или все неудачные');
        $this->line('  queue:cancel <id>              отменить письмо');
        $this->line('  queue:purge [--status=sent] [--days=30]   удалить старые письма');
        $this->line('  webhook:process                разослать накопившиеся вебхуки');
        $this->line('  route:list [строка]            карта адресов сервиса');
        $this->line('  logs:purge [--days=30]          удалить старые файлы логов');
        $this->line('');
        $this->line('Проекты и ключи:');
        $this->line('  key:create <имя> [--transport=] [--limit-day=] [--webhook=]');
        $this->line('  key:list                       список проектов');
        $this->line('  key:regenerate <имя>           выдать новый ключ');
        $this->line('  key:revoke <имя>               отключить проект');
        $this->line('');
        $this->line('Пользователи панели:');
        $this->line('  user:create <логин> [--password=] [--name=]  завести пользователя');
        $this->line('  user:list                      список пользователей');
        $this->line('  user:password <логин> [--password=]   сменить пароль');
        $this->line('  user:delete <логин> [--force]  удалить пользователя (--force — даже последнего)');
        $this->line('');
        $this->line('Транспорты:');
        $this->line('  transport:add <имя> --type=smtp --host= --port= --encryption= --user= --password= [--from=] [--from-name=] [--default]');
        $this->line('  transport:list                 список транспортов');
        $this->line('  transport:test <имя>           проверить подключение');
        $this->line('  transport:default <имя>        сделать основным');
        $this->line('  transport:delete <имя>         удалить');
        $this->line('');
        $this->line('Шаблоны:');
        $this->line('  template:list                  список шаблонов');
        $this->line('  template:delete <имя>          удалить шаблон');
        $this->line('');
        $this->line('Отправка:');
        $this->line('  send:test <email> [--transport=]      тестовое письмо');
        $this->line('  send --to= --subject= --text= [--html=] [--from=] [--sync]');
        $this->line('');
        $this->line('  test                           прогнать тесты');

        return 0;
    }

    private function migrate(): int
    {
        $migrator = new Migrator();
        $applied  = $migrator->run();

        if ($applied === []) {
            $this->line('Все миграции уже применены, база в порядке.');
        } else {
            foreach ($applied as $name) {
                $this->line('Применена миграция: ' . $name);
            }
        }

        $this->line('База: ' . Database::instance()->driver());

        return 0;
    }

    /**
     * Первичное наполнение: транспорт из .env и пара шаблонов.
     */
    private function seed(): int
    {
        $transports = new TransportRepository();
        $templates  = new TemplateRepository();

        $host = Env::string('SEED_SMTP_HOST', '');
        $user = Env::string('SEED_SMTP_USERNAME', '');

        if ($host !== '' && $user !== '') {
            $name = Env::string('SEED_SMTP_NAME', 'yandex');

            if ($transports->findByName($name) === null) {
                $transports->create([
                    'name'       => $name,
                    'type'       => 'smtp',
                    'from_email' => Env::string('SEED_SMTP_FROM_EMAIL', $user),
                    'from_name'  => Env::string('SEED_SMTP_FROM_NAME', 'Mailer'),
                    'is_default' => true,
                    'settings'   => [
                        'host'       => $host,
                        'port'       => Env::int('SEED_SMTP_PORT', 465),
                        'encryption' => Env::string('SEED_SMTP_ENCRYPTION', 'ssl'),
                        'username'   => $user,
                        'password'   => Env::string('SEED_SMTP_PASSWORD', ''),
                        'auth_mode'  => 'auto',
                    ],
                ]);

                $this->line('Добавлен SMTP-транспорт «' . $name . '» (' . $host . ') и назначен основным.');
            } else {
                $this->line('Транспорт «' . $name . '» уже есть, пропускаем.');
            }
        } else {
            $this->line('В .env не заданы SEED_SMTP_HOST и SEED_SMTP_USERNAME — SMTP-транспорт не создан.');
        }

        // Транспорт для разработки: письма падают в файлы
        if ($transports->findByName('log') === null) {
            $transports->create([
                'name'     => 'log',
                'type'     => 'log',
                'priority' => 900,
                'settings' => ['dir' => Config::get('paths.spool') . '/sent'],
            ]);
            $this->line('Добавлен транспорт «log» — письма складываются в var/spool/sent.');
        }

        // Демонстрационный шаблон
        if ($templates->findByName('welcome') === null) {
            $templates->create([
                'name'        => 'welcome',
                'description' => 'Пример шаблона: приветствие нового пользователя',
                'subject'     => 'Здравствуйте, {{ name }}!',
                'html'        => "<p>Здравствуйте, {{ name }}!</p>\n<p>Вы зарегистрировались на сайте {{ site }}.</p>",
                'text'        => "Здравствуйте, {{ name }}!\n\nВы зарегистрировались на сайте {{ site }}.",
            ]);
            $this->line('Добавлен шаблон «welcome».');
        }

        // Проекты для приложений без SDK
        $projects = new ProjectRepository();
        foreach ([
            Config::get('sendmail.project', 'local-sendmail') => 'Письма из sendmail-shim',
            Config::get('smtpd.project', 'local-relay')       => 'Письма из локального SMTP-релея',
        ] as $name => $description) {
            if ($projects->findByName((string) $name) === null) {
                $created = $projects->create(['name' => (string) $name, 'description' => $description]);
                $this->line('Создан служебный проект «' . $name . '» (ключ: ' . $created['key'] . ')');
            }
        }

        return 0;
    }

    private function appKey(): int
    {
        $key = Crypto::generateKey();

        $this->line('Добавьте в .env строку:');
        $this->line('APP_KEY=' . $key);
        $this->line('');
        $this->line('Если в базе уже есть транспорты с паролями — после смены ключа их надо задать заново.');

        return 0;
    }

    private function worker(): int
    {
        $once  = isset($this->options['once']);
        $limit = isset($this->options['limit']) ? (int) $this->options['limit'] : null;

        (new Worker())->run($once, $limit);

        return 0;
    }

    /**
     * Просит работающий воркер завершиться: под systemd он сразу поднимется заново.
     */
    private function workerRestart(): int
    {
        $settings  = new SettingRepository();
        $heartbeat = $settings->get(Worker::HEARTBEAT_KEY);

        if ($heartbeat === null) {
            $this->line('Воркер ни разу не запускался — перезапускать нечего.');

            return 1;
        }

        Worker::requestRestart();

        $state = (array) json_decode($heartbeat, true);
        $sleep = (int) Config::get('queue.sleep', 5);

        $this->line('Запрос отправлен воркеру ' . (string) ($state['worker'] ?? '?'));
        $this->line('Он доработает текущую пачку и выйдет — это займёт до ' . $sleep . ' с.');
        $this->line('Если воркер под systemd, служба поднимет его сама; иначе запустите заново.');

        return 0;
    }

    private function smtpd(): int
    {
        $server = new SmtpServer(
            $this->options['host'] ?? null,
            isset($this->options['port']) ? (int) $this->options['port'] : null,
            fn (string $line): mixed => $this->line($line)
        );

        $server->run();

        return 0;
    }

    private function status(): int
    {
        $messages = new MessageRepository();
        $stats    = $messages->stats();

        $this->line('=== Состояние сервиса ===');
        $this->line('База данных:        ' . Database::instance()->driver());
        $this->line('Ключ шифрования:    ' . (Crypto::hasKey() ? 'задан' : 'НЕ задан (пароли лежат открытым текстом)'));
        $this->line('Всего писем:        ' . $stats['total']);

        foreach ($stats['by_status'] as $status => $count) {
            $this->line('  ' . str_pad($status, 18) . $count);
        }

        $this->line('Готовы к отправке:  ' . $stats['queue_ready']);
        $this->line('Ждут своего часа:   ' . $stats['queue_delayed']);
        $this->line('Отправлено сегодня: ' . $stats['today_sent']);
        $this->line('Ошибок сегодня:     ' . $stats['today_failed']);

        $heartbeat = (new SettingRepository())->get(Worker::HEARTBEAT_KEY);
        if ($heartbeat === null) {
            $this->line('Воркер:             ни разу не запускался');
        } else {
            $data    = (array) json_decode($heartbeat, true);
            $seconds = time() - (int) strtotime((string) ($data['time'] ?? 'now'));
            $this->line('Воркер:             ' . ($seconds < 120 ? 'работает' : 'молчит')
                . ' (последний отклик ' . $seconds . ' с назад, обработано ' . ($data['processed'] ?? 0) . ')');
        }

        $webhooks = (new WebhookRepository())->countByStatus();
        $this->line('Вебхуки:            в очереди ' . $webhooks['queued'] . ', доставлено ' . $webhooks['delivered'] . ', не удалось ' . $webhooks['failed']);

        return 0;
    }

    // --- Проекты -------------------------------------------------------------

    private function userCreate(): int
    {
        $login = $this->args[0] ?? '';
        if ($login === '') {
            $this->line('Укажите логин: php bin/mailer user:create ivan');

            return 1;
        }

        $password = (string) ($this->options['password'] ?? '');
        $generated = $password === '';
        if ($generated) {
            $password = Password::generate();
        }

        $user = (new UserRepository())->create([
            'login'    => $login,
            'password' => $password,
            'name'     => $this->options['name'] ?? '',
            'active'   => true,
        ]);

        $this->line('Пользователь создан: ' . $user['login']);
        if ($generated) {
            $this->line('Пароль (больше не покажется): ' . $password);
        }

        return 0;
    }

    private function userList(): int
    {
        $users = (new UserRepository())->all();

        if ($users === []) {
            $this->line('Пользователей нет. Создайте: php bin/mailer user:create ivan');

            return 0;
        }

        $this->line($this->pad('Логин', 24) . $this->pad('Состояние', 12) . 'Последний вход');
        foreach ($users as $user) {
            $this->line(
                $this->pad((string) $user['login'], 24)
                . $this->pad((int) $user['active'] === 1 ? 'активен' : 'отключён', 12)
                . (string) ($user['last_login_at'] ?? 'не входил')
            );
        }

        return 0;
    }

    private function userPassword(): int
    {
        $login = $this->args[0] ?? '';
        $users = new UserRepository();
        $user  = $login === '' ? null : $users->findByLogin($login);

        if ($user === null) {
            $this->line('Пользователь не найден: php bin/mailer user:password ivan [--password=]');

            return 1;
        }

        $password  = (string) ($this->options['password'] ?? '');
        $generated = $password === '';
        if ($generated) {
            $password = Password::generate();
        }

        $users->setPassword((int) $user['id'], $password);

        $this->line('Пароль пользователя «' . $user['login'] . '» изменён');
        if ($generated) {
            $this->line('Новый пароль (больше не покажется): ' . $password);
        }

        return 0;
    }

    private function userDelete(): int
    {
        $login = $this->args[0] ?? '';
        $users = new UserRepository();
        $user  = $login === '' ? null : $users->findByLogin($login);

        if ($user === null) {
            $this->line('Пользователь не найден: php bin/mailer user:delete ivan');

            return 1;
        }

        $users->delete((int) $user['id'], isset($this->options['force']));
        $this->line('Пользователь «' . $user['login'] . '» удалён');

        return 0;
    }

    private function keyCreate(): int
    {
        $name = $this->args[0] ?? '';
        if ($name === '') {
            $this->line('Укажите имя проекта: php bin/mailer key:create my-site');

            return 1;
        }

        $transportId = null;
        if (isset($this->options['transport'])) {
            $transport = (new TransportRepository())->findByName($this->options['transport']);
            if ($transport === null) {
                $this->line('Транспорт «' . $this->options['transport'] . '» не найден');

                return 1;
            }
            $transportId = (int) $transport['id'];
        }

        $created = (new ProjectRepository())->create([
            'name'            => $name,
            'description'     => $this->options['description'] ?? null,
            'transport_id'    => $transportId,
            'rate_limit_hour' => (int) ($this->options['limit-hour'] ?? 0),
            'rate_limit_day'  => (int) ($this->options['limit-day'] ?? 0),
            'webhook_url'     => $this->options['webhook'] ?? null,
            'default_from_email' => $this->options['from'] ?? null,
            'default_from_name'  => $this->options['from-name'] ?? null,
        ]);

        $this->line('Проект создан: ' . $name);
        $this->line('API-ключ (сохраните, он больше не покажется):');
        $this->line('  ' . $created['key']);

        return 0;
    }

    private function keyList(): int
    {
        $projects = (new ProjectRepository())->all();
        $limiter  = new RateLimiter();

        if ($projects === []) {
            $this->line('Проектов пока нет. Создайте: php bin/mailer key:create my-site');

            return 0;
        }

        $this->line(str_pad('ID', 5) . str_pad('Проект', 24) . str_pad('Ключ', 24) . str_pad('За час', 10) . str_pad('За сутки', 10) . 'Статус');

        foreach ($projects as $project) {
            $usage = $limiter->projectUsage((int) $project['id']);

            $this->line(
                str_pad((string) $project['id'], 5)
                . str_pad(Str::limit((string) $project['name'], 22), 24)
                . str_pad(\Mailer\Security\ApiKey::mask((string) $project['api_key_prefix']), 24)
                . str_pad($usage['hour'] . ($project['rate_limit_hour'] > 0 ? '/' . $project['rate_limit_hour'] : ''), 10)
                . str_pad($usage['day'] . ($project['rate_limit_day'] > 0 ? '/' . $project['rate_limit_day'] : ''), 10)
                . ((int) $project['active'] === 1 ? 'активен' : 'отключён')
            );
        }

        return 0;
    }

    private function keyRegenerate(): int
    {
        $name    = $this->args[0] ?? '';
        $projects = new ProjectRepository();
        $project = $projects->findByName($name);

        if ($project === null) {
            $this->line('Проект «' . $name . '» не найден');

            return 1;
        }

        $key = $projects->regenerateKey((int) $project['id']);
        $this->line('Новый ключ проекта ' . $name . ':');
        $this->line('  ' . $key);
        $this->line('Старый ключ больше не работает.');

        return 0;
    }

    private function keyRevoke(): int
    {
        $name     = $this->args[0] ?? '';
        $projects = new ProjectRepository();
        $project  = $projects->findByName($name);

        if ($project === null) {
            $this->line('Проект «' . $name . '» не найден');

            return 1;
        }

        $projects->update((int) $project['id'], ['active' => false]);
        $this->line('Проект «' . $name . '» отключён, его ключ больше не принимается.');

        return 0;
    }

    // --- Транспорты ----------------------------------------------------------

    private function transportAdd(): int
    {
        $name = $this->args[0] ?? '';
        $type = $this->options['type'] ?? 'smtp';

        if ($name === '') {
            $this->line('Укажите имя транспорта: php bin/mailer transport:add yandex --type=smtp --host=smtp.yandex.ru ...');

            return 1;
        }

        $settings = [];

        if ($type === 'smtp') {
            $settings = [
                'host'       => $this->options['host'] ?? 'smtp.yandex.ru',
                'port'       => (int) ($this->options['port'] ?? 465),
                'encryption' => $this->options['encryption'] ?? 'ssl',
                'username'   => $this->options['user'] ?? '',
                'password'   => $this->options['password'] ?? '',
                'auth_mode'  => $this->options['auth'] ?? 'auto',
            ];
        } elseif ($type === 'sendmail') {
            $settings = ['path' => $this->options['path'] ?? '/usr/sbin/sendmail'];
        } elseif ($type === 'log') {
            $settings = ['dir' => $this->options['dir'] ?? (Config::get('paths.spool') . '/sent')];
        } elseif (in_array($type, ['failover', 'roundrobin'], true)) {
            $list = trim((string) ($this->options['transports'] ?? ''));
            if ($list === '') {
                $this->line('Для составного транспорта укажите --transports=имя1,имя2');

                return 1;
            }
            $settings = ['transports' => array_map('trim', explode(',', $list))];
        }

        $id = (new TransportRepository())->create([
            'name'        => $name,
            'type'        => $type,
            'settings'    => $settings,
            'from_email'  => $this->options['from'] ?? null,
            'from_name'   => $this->options['from-name'] ?? null,
            'daily_limit' => (int) ($this->options['daily-limit'] ?? 0),
            'priority'    => (int) ($this->options['priority'] ?? 100),
            'is_default'  => isset($this->options['default']),
        ]);

        $this->line('Транспорт «' . $name . '» создан (id=' . $id . ').');
        $this->line('Проверить: php bin/mailer transport:test ' . $name);

        return 0;
    }

    private function transportList(): int
    {
        $transports = (new TransportRepository())->all();
        $limiter    = new RateLimiter();

        if ($transports === []) {
            $this->line('Транспортов нет. Добавьте: php bin/mailer transport:add yandex --type=smtp ...');

            return 0;
        }

        $this->line(str_pad('ID', 5) . str_pad('Имя', 20) . str_pad('Тип', 12) . str_pad('Куда', 30) . str_pad('Сегодня', 12) . 'Признаки');

        foreach ($transports as $transport) {
            $settings = (array) $transport['settings'];
            $target   = match ($transport['type']) {
                'smtp'     => ($settings['host'] ?? '') . ':' . ($settings['port'] ?? ''),
                'sendmail' => (string) ($settings['path'] ?? ''),
                'log'      => (string) ($settings['dir'] ?? ''),
                default    => implode(',', (array) ($settings['transports'] ?? [])),
            };

            $flags = [];
            if ((int) $transport['is_default'] === 1) {
                $flags[] = 'основной';
            }
            if ((int) $transport['active'] !== 1) {
                $flags[] = 'выключен';
            }
            if (($transport['last_error'] ?? null) !== null) {
                $flags[] = 'была ошибка';
            }

            $used = $limiter->transportUsage((int) $transport['id']);

            $this->line(
                str_pad((string) $transport['id'], 5)
                . str_pad(Str::limit((string) $transport['name'], 18), 20)
                . str_pad((string) $transport['type'], 12)
                . str_pad(Str::limit($target, 28), 30)
                . str_pad($used . ((int) $transport['daily_limit'] > 0 ? '/' . $transport['daily_limit'] : ''), 12)
                . implode(', ', $flags)
            );
        }

        return 0;
    }

    private function transportTest(): int
    {
        $name = $this->args[0] ?? '';
        if ($name === '') {
            $this->line('Укажите имя транспорта');

            return 1;
        }

        $transport = (new TransportFactory())->byName($name);

        $this->line('Проверяем «' . $name . '»…');

        try {
            $this->line($transport->test());
            $this->line('Транспорт работает.');

            return 0;
        } catch (Throwable $e) {
            $this->line('Не получилось: ' . $e->getMessage());

            return 1;
        }
    }

    private function transportDefault(): int
    {
        $name       = $this->args[0] ?? '';
        $repository = new TransportRepository();
        $transport  = $repository->findByName($name);

        if ($transport === null) {
            $this->line('Транспорт «' . $name . '» не найден');

            return 1;
        }

        $repository->setDefault((int) $transport['id']);
        $this->line('Основной транспорт теперь «' . $name . '».');

        return 0;
    }

    private function transportDelete(): int
    {
        $name       = $this->args[0] ?? '';
        $repository = new TransportRepository();
        $transport  = $repository->findByName($name);

        if ($transport === null) {
            $this->line('Транспорт «' . $name . '» не найден');

            return 1;
        }

        $repository->delete((int) $transport['id']);
        $this->line('Транспорт «' . $name . '» удалён.');

        return 0;
    }

    // --- Очередь -------------------------------------------------------------

    private function queueStatus(): int
    {
        $messages = new MessageRepository();
        $stats    = $messages->stats();

        $this->line('В очереди готовы:  ' . $stats['queue_ready']);
        $this->line('Отложены:          ' . $stats['queue_delayed']);
        $this->line('Отправляются:      ' . ($stats['by_status']['sending'] ?? 0));
        $this->line('Ошибки:            ' . ($stats['by_status']['failed'] ?? 0));
        $this->line('Отправлено всего:  ' . ($stats['by_status']['sent'] ?? 0));

        if ($stats['oldest_queued'] !== null) {
            $this->line('Самое старое в очереди: ' . $stats['oldest_queued']);
        }

        $recent = $messages->paginate(['status' => MessageRepository::FAILED], 1, 5);
        if ($recent['items'] !== []) {
            $this->line('');
            $this->line('Последние неудачные:');
            foreach ($recent['items'] as $row) {
                $this->line('  ' . $row['uuid'] . '  ' . Str::limit((string) $row['subject'], 40)
                    . '  ' . Str::limit((string) $row['last_error'], 60));
            }
        }

        return 0;
    }

    private function queueRetry(): int
    {
        $queue    = new Queue();
        $messages = new MessageRepository();

        if (isset($this->options['failed'])) {
            $count = 0;
            $page  = $messages->paginate(['status' => MessageRepository::FAILED], 1, 200);

            foreach ($page['items'] as $row) {
                $count += $queue->retry((int) $row['id'], 'Массовый повтор из CLI') ? 1 : 0;
            }

            $this->line('Возвращено в очередь писем: ' . $count);

            return 0;
        }

        $id  = $this->args[0] ?? '';
        $row = $messages->findAny($id);

        if ($row === null) {
            $this->line('Письмо не найдено: ' . $id);

            return 1;
        }

        if (!$queue->retry((int) $row['id'], 'Повтор из CLI')) {
            $this->line('Повторить нельзя: письмо уже отправлено.');

            return 1;
        }

        $this->line('Письмо ' . $row['uuid'] . ' снова в очереди.');

        return 0;
    }

    private function queueCancel(): int
    {
        $id  = $this->args[0] ?? '';
        $row = (new MessageRepository())->findAny($id);

        if ($row === null) {
            $this->line('Письмо не найдено: ' . $id);

            return 1;
        }

        if (!(new Queue())->cancel((int) $row['id'], 'Отмена из CLI')) {
            $this->line('Письмо нельзя отменить: оно уже отправлено или отменено.');

            return 1;
        }

        $this->line('Письмо ' . $row['uuid'] . ' отменено.');

        return 0;
    }

    private function queuePurge(): int
    {
        $status = $this->options['status'] ?? MessageRepository::SENT;
        $days   = (int) ($this->options['days'] ?? Config::get('queue.keep_sent_days', 30));

        $deleted = (new MessageRepository())->purge($status, $days);
        $this->line('Удалено писем в статусе «' . $status . '» старше ' . $days . ' дней: ' . $deleted);

        return 0;
    }

    private function webhookProcess(): int
    {
        $count = (new WebhookSender())->processQueue(100);
        $this->line('Обработано вебхуков: ' . $count);

        return 0;
    }

    // --- Шаблоны -------------------------------------------------------------

    private function templateList(): int
    {
        $templates = (new TemplateRepository())->all();

        if ($templates === []) {
            $this->line('Шаблонов нет.');

            return 0;
        }

        foreach ($templates as $template) {
            $this->line(str_pad((string) $template['id'], 5) . str_pad((string) $template['name'], 24)
                . Str::limit((string) ($template['subject'] ?? ''), 50));
        }

        return 0;
    }

    private function templateDelete(): int
    {
        $name       = $this->args[0] ?? '';
        $repository = new TemplateRepository();
        $template   = $repository->findByName($name);

        if ($template === null) {
            $this->line('Шаблон «' . $name . '» не найден');

            return 1;
        }

        $repository->delete((int) $template['id']);
        $this->line('Шаблон «' . $name . '» удалён.');

        return 0;
    }

    // --- Отправка ------------------------------------------------------------

    private function sendTest(): int
    {
        $to = $this->args[0] ?? Env::string('SEED_TEST_EMAIL', '');

        if ($to === '') {
            $this->line('Укажите адрес: php bin/mailer send:test user@example.com');

            return 1;
        }

        $payload = [
            'to'      => $to,
            'subject' => 'Проверка связи — ' . date('d.m.Y H:i:s'),
            'text'    => "Это тестовое письмо от сервиса рассылки.\n\nЕсли вы его читаете — отправка настроена верно.",
            'html'    => '<p>Это <b>тестовое письмо</b> от сервиса рассылки.</p>'
                . '<p>Если вы его читаете — отправка настроена верно.</p>'
                . '<p style="color:#888">Отправлено ' . date('d.m.Y H:i:s') . '</p>',
            'sync'    => true,
        ];

        if (isset($this->options['transport'])) {
            $payload['transport'] = $this->options['transport'];
        }

        $result = (new MailService())->accept($payload, null, MessageRepository::SOURCE_CLI);

        $this->line('Письмо: ' . $result['uuid']);
        $this->line('Статус: ' . $result['status']);
        $this->line('Ответ:  ' . ($result['info'] ?? '—'));

        return $result['status'] === MessageRepository::SENT ? 0 : 1;
    }

    private function send(): int
    {
        $to = $this->options['to'] ?? '';
        if ($to === '') {
            $this->line('Укажите получателя: php bin/mailer send --to=user@example.com --subject=Тема --text=Текст');

            return 1;
        }

        $payload = [
            'to'      => $to,
            'subject' => $this->options['subject'] ?? '(без темы)',
            'text'    => $this->options['text'] ?? '',
            'html'    => $this->options['html'] ?? '',
            'sync'    => isset($this->options['sync']),
        ];

        if (isset($this->options['from'])) {
            $payload['from'] = $this->options['from'];
        }
        if (isset($this->options['transport'])) {
            $payload['transport'] = $this->options['transport'];
        }
        if (isset($this->options['template'])) {
            $payload['template'] = $this->options['template'];
        }

        $result = (new MailService())->accept($payload, null, MessageRepository::SOURCE_CLI);

        $this->line('Письмо ' . $result['uuid'] . ': ' . $result['status']);
        if (isset($result['info'])) {
            $this->line($result['info']);
        }

        return 0;
    }

    /**
     * Убирает старые файлы логов. По умолчанию срок берётся из LOG_KEEP_DAYS.
     */
    private function logsPurge(): int
    {
        $days    = isset($this->options['days']) ? (int) $this->options['days'] : null;
        $removed = (new Logger('cli'))->purge($days);

        if ($removed === []) {
            $this->line('Удалять нечего.');

            return 0;
        }

        $this->line('Удалено файлов: ' . count($removed));
        foreach ($removed as $name) {
            $this->line('  ' . $name);
        }

        return 0;
    }

    /**
     * Карта адресов сервиса: что куда ведёт, под какими прослойками и как называется.
     */
    private function routeList(): int
    {
        $router = new Router();

        // Настоящие прослойки не нужны — нам важны только их имена у маршрутов
        foreach (['api-key', 'panel-auth', 'panel-guest', 'panel-setup'] as $name) {
            $router->middleware($name, static fn (Request $request, callable $next): Response => $next($request));
        }

        $router->load(MAILER_ROOT . '/routes/api.php');
        $router->load(MAILER_ROOT . '/routes/ui.php');

        $needle = (string) ($this->args[0] ?? '');
        $rows   = [];

        foreach ($router->routes() as $route) {
            $handler = is_array($route->handler)
                ? substr((string) strrchr('\\' . $route->handler[0], '\\'), 1) . '::' . $route->handler[1]
                : 'функция';

            $row = [
                implode('|', $route->methods),
                $route->pattern,
                $handler,
                implode(', ', $route->middleware) ?: '—',
                $route->name ?? '',
            ];

            if ($needle !== '' && !str_contains(mb_strtolower(implode(' ', $row)), mb_strtolower($needle))) {
                continue;
            }

            $rows[] = $row;
        }

        if ($rows === []) {
            $this->line($needle === '' ? 'Маршрутов нет.' : 'Ничего не нашлось по «' . $needle . '».');

            return 0;
        }

        $widths = [];
        foreach ([0, 1, 2, 3] as $column) {
            $widths[$column] = max(array_map(static fn (array $row): int => mb_strlen($row[$column]), $rows)) + 2;
        }

        $this->line($this->pad('Метод', $widths[0]) . $this->pad('Адрес', $widths[1])
            . $this->pad('Обработчик', $widths[2]) . $this->pad('Прослойки', $widths[3]) . 'Имя');

        foreach ($rows as $row) {
            $this->line($this->pad($row[0], $widths[0]) . $this->pad($row[1], $widths[1])
                . $this->pad($row[2], $widths[2]) . $this->pad($row[3], $widths[3]) . $row[4]);
        }

        $this->line('');
        $this->line('Всего маршрутов: ' . count($rows));

        return 0;
    }
    private function runTests(): int
    {
        $runner = MAILER_ROOT . '/tests/run.php';

        if (!is_file($runner)) {
            $this->line('Тесты не найдены: ' . $runner);

            return 1;
        }

        return (int) require $runner;
    }

    private function unknown(string $command): int
    {
        $this->line('Неизвестная команда: ' . $command);
        $this->line('Список команд: php bin/mailer help');

        return 1;
    }

    // --- Вспомогательное -----------------------------------------------------

    /**
     * Разбирает аргументы: --name=value, --flag и обычные значения.
     *
     * @param array<int, string> $arguments
     */
    private function parseArguments(array $arguments): void
    {
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--')) {
                $argument = substr($argument, 2);

                if (str_contains($argument, '=')) {
                    [$name, $value]        = explode('=', $argument, 2);
                    $this->options[$name]  = $value;
                } else {
                    $this->options[$argument] = '1';
                }

                continue;
            }

            $this->args[] = $argument;
        }
    }

    /**
     * Колонка нужной ширины. str_pad считает байты, а в русских словах их вдвое больше.
     */
    private function pad(string $text, int $width): string
    {
        $length = mb_strlen($text);

        return $length >= $width ? $text . ' ' : $text . str_repeat(' ', $width - $length);
    }

    private function line(string $text = ''): void
    {
        echo $text . PHP_EOL;
    }
}
