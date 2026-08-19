<?php

declare(strict_types=1);

namespace Mailer\Console\Commands;

use Mailer\Console\Command;
use Mailer\MailService;
use Mailer\Repository\MessageRepository;

/**
 * отправить письмо.
 */
final class SendCommand extends Command
{
    public function name(): string
    {
        return 'send';
    }

    public function description(): string
    {
        return 'отправить письмо';
    }

    public function usage(): string
    {
        return 'send --to= --subject= --text= [--html=] [--from=] [--sync]';
    }

    public function run(): int
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
}
