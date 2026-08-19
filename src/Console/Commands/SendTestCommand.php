<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\MailService;
use Mailer\Repository\MessageRepository;
use Mailer\Support\Env;

/**
 * отправить тестовое письмо.
 */
final class SendTestCommand extends Command
{
    public function name(): string
    {
        return 'send:test';
    }

    public function description(): string
    {
        return 'отправить тестовое письмо';
    }

    public function usage(): string
    {
        return 'send:test <email> [--transport=]';
    }

    public function run(): int
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
}
