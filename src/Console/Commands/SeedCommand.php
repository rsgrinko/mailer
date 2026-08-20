<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\Repository\ProjectRepository;
use Mailer\Repository\TemplateRepository;
use Mailer\Repository\TransportRepository;
use Mailer\Support\Config;
use Mailer\Support\Env;

/**
 * добавить транспорт и шаблоны из .env.
 */
final class SeedCommand extends Command
{
    public function name(): string
    {
        return 'seed';
    }

    public function description(): string
    {
        return 'добавить транспорт и шаблоны из .env';
    }

    public function usage(): string
    {
        return 'seed';
    }

    /**
     * Первичное наполнение: транспорт из .env и пара шаблонов.
     */
    public function run(): int
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
                    // Базовые транспорты общие: ими пользуются все, правит администратор
                    'shared'     => true,
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
                'shared'   => true,
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
}
