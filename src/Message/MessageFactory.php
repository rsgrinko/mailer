<?php

declare(strict_types=1);

namespace Mailer\Message;

use Mailer\Repository\TemplateRepository;
use Mailer\Template\Renderer;
use Mailer\Support\Config;
use Mailer\Support\Validator;

/**
 * Превращает данные запроса (JSON от клиента) в объект письма.
 * Здесь же проверяем всё, что может прислать клиент.
 */
final class MessageFactory
{
    private TemplateRepository $templates;
    private Renderer $renderer;

    public function __construct(?TemplateRepository $templates = null, ?Renderer $renderer = null)
    {
        $this->templates = $templates ?? new TemplateRepository();
        $this->renderer  = $renderer ?? new Renderer();
    }

    /**
     * Собирает письмо из данных запроса.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $project проект, от имени которого пришёл запрос
     * @return array{message: Message, options: array<string, mixed>}
     */
    public function build(array $payload, ?array $project = null): array
    {
        $validator = new Validator();
        $message   = new Message();

        // Готовое письмо целиком — так шлют sendmail-shim и SMTP-релей
        if (isset($payload['raw']) && is_string($payload['raw']) && trim($payload['raw']) !== '') {
            return $this->buildFromRaw($payload, $validator);
        }

        // --- Отправитель -----------------------------------------------------
        $fromEmail = (string) ($payload['from']['email'] ?? $payload['from_email'] ?? $project['default_from_email'] ?? '');
        $fromName  = (string) ($payload['from']['name'] ?? $payload['from_name'] ?? $project['default_from_name'] ?? '');

        if (is_string($payload['from'] ?? null) && $payload['from'] !== '') {
            try {
                $parsed    = Address::parse((string) $payload['from']);
                $fromEmail = $parsed->email;
                $fromName  = $parsed->name !== '' ? $parsed->name : $fromName;
            } catch (\Throwable $e) {
                $validator->add('Поле from: ' . $e->getMessage());
            }
        }

        if ($fromEmail !== '') {
            if (Validator::isEmail($fromEmail)) {
                $message->from = new Address($fromEmail, $fromName);
            } else {
                $validator->add('Некорректный адрес отправителя: ' . $fromEmail);
            }
        }
        // Если отправитель не указан вовсе — его подставит транспорт из своих настроек

        // --- Получатели ------------------------------------------------------
        try {
            $message->to = Address::parseList($payload['to'] ?? null);
        } catch (\Throwable $e) {
            $validator->add('Поле to: ' . $e->getMessage());
        }

        try {
            $message->cc = Address::parseList($payload['cc'] ?? null);
        } catch (\Throwable $e) {
            $validator->add('Поле cc: ' . $e->getMessage());
        }

        try {
            $message->bcc = Address::parseList($payload['bcc'] ?? null);
        } catch (\Throwable $e) {
            $validator->add('Поле bcc: ' . $e->getMessage());
        }

        $validator->check($message->to !== [], 'Не указан ни один получатель (поле to)');

        $maxRecipients = (int) Config::get('limits.max_recipients', 50);
        $total         = count($message->to) + count($message->cc) + count($message->bcc);
        $validator->check(
            $total <= $maxRecipients,
            'Слишком много получателей: ' . $total . ', разрешено не больше ' . $maxRecipients
        );

        if (isset($payload['reply_to']) && $payload['reply_to'] !== '') {
            try {
                $message->replyTo = Address::parse((string) (is_array($payload['reply_to'])
                    ? ($payload['reply_to']['email'] ?? '')
                    : $payload['reply_to']));
            } catch (\Throwable $e) {
                $validator->add('Поле reply_to: ' . $e->getMessage());
            }
        }

        // --- Тема и тело -----------------------------------------------------
        $message->subject = trim((string) ($payload['subject'] ?? ''));
        $message->text    = (string) ($payload['text'] ?? '');
        $message->html    = (string) ($payload['html'] ?? '');

        $templateName = isset($payload['template']) ? trim((string) $payload['template']) : '';
        $templateData = (array) ($payload['template_data'] ?? $payload['data'] ?? []);

        if ($templateName !== '') {
            $template = $this->templates->findByName($templateName);

            if ($template === null) {
                $validator->add('Шаблон «' . $templateName . '» не найден');
            } else {
                $rendered = $this->renderer->renderTemplate($template, $templateData);

                if ($message->subject === '' && $rendered['subject'] !== '') {
                    $message->subject = $rendered['subject'];
                }
                if ($message->html === '') {
                    $message->html = $rendered['html'];
                }
                if ($message->text === '') {
                    $message->text = $rendered['text'];
                }
            }
        }

        $validator->check($message->subject !== '', 'Не указана тема письма (поле subject)');
        $validator->check(
            mb_strlen($message->subject) <= (int) Config::get('limits.max_subject_length', 500),
            'Слишком длинная тема письма'
        );
        $validator->check(
            $message->text !== '' || $message->html !== '',
            'Письмо пустое: нужно указать text, html или template'
        );

        // --- Заголовки -------------------------------------------------------
        foreach ((array) ($payload['headers'] ?? []) as $name => $value) {
            if (!is_string($name) || is_array($value)) {
                continue;
            }
            $message->headers[$name] = (string) $value;
        }

        // --- Вложения --------------------------------------------------------
        $maxAttachment = (int) Config::get('limits.max_attachment_size', 10 * 1024 * 1024);

        foreach ((array) ($payload['attachments'] ?? []) as $item) {
            if (!is_array($item)) {
                $validator->add('Каждое вложение должно быть объектом с полями name и content');
                continue;
            }

            try {
                $attachment = Attachment::fromArray($item);
            } catch (\Throwable $e) {
                $validator->add($e->getMessage());
                continue;
            }

            if ($attachment->size() > $maxAttachment) {
                $validator->add(
                    'Вложение «' . $attachment->name . '» больше разрешённого размера ('
                    . round($maxAttachment / 1048576, 1) . ' МБ)'
                );
                continue;
            }

            $message->attachments[] = $attachment;
        }

        // --- Прочее ----------------------------------------------------------
        $message->priority = (int) ($payload['priority'] ?? 100);
        $message->tag      = isset($payload['tag']) && $payload['tag'] !== '' ? (string) $payload['tag'] : null;
        $message->meta     = (array) ($payload['meta'] ?? []);

        $maxSize = (int) Config::get('limits.max_message_size', 25 * 1024 * 1024);
        $validator->check(
            $message->approximateSize() <= $maxSize,
            'Письмо слишком большое: ' . round($message->approximateSize() / 1048576, 1)
            . ' МБ при разрешённых ' . round($maxSize / 1048576, 1) . ' МБ'
        );

        $validator->throwIfFails();

        return [
            'message' => $message,
            'options' => [
                'template'        => $templateName !== '' ? $templateName : null,
                'template_data'   => $templateData,
                'priority'        => $message->priority,
                'idempotency_key' => isset($payload['idempotency_key']) && $payload['idempotency_key'] !== ''
                    ? (string) $payload['idempotency_key']
                    : null,
                'transport'       => isset($payload['transport']) && $payload['transport'] !== ''
                    ? (string) $payload['transport']
                    : null,
                'send_at'         => isset($payload['send_at']) && $payload['send_at'] !== ''
                    ? (string) $payload['send_at']
                    : null,
            ],
        ];
    }

    /**
     * Письмо, которое пришло уже собранным. Разбираем только «шапку»,
     * чтобы знать отправителя, получателей и тему.
     *
     * @param array<string, mixed> $payload
     * @return array{message: Message, options: array<string, mixed>}
     */
    private function buildFromRaw(array $payload, Validator $validator): array
    {
        $raw     = (string) $payload['raw'];
        $message = new Message();
        $parsed  = MimeParser::parse($raw);

        $message->raw     = $raw;
        $message->subject = $parsed['subject'];
        $message->from    = $parsed['from'][0] ?? null;
        $message->to      = $parsed['to'];
        $message->cc      = $parsed['cc'];
        $message->bcc     = $parsed['bcc'];
        $message->text    = $parsed['text'];
        $message->html    = $parsed['html'];

        // Кто отправитель и получатели по конверту — важнее того, что написано в заголовках
        $message->envelopeFrom = isset($payload['envelope_from']) && $payload['envelope_from'] !== ''
            ? (string) $payload['envelope_from']
            : $message->from?->email;

        $envelopeTo = $payload['envelope_to'] ?? null;
        if (is_string($envelopeTo) && $envelopeTo !== '') {
            $envelopeTo = Address::splitList($envelopeTo);
        }

        if (is_array($envelopeTo) && $envelopeTo !== []) {
            foreach ($envelopeTo as $item) {
                $email = trim((string) $item);
                if (Validator::isEmail($email)) {
                    $message->envelopeTo[] = $email;
                }
            }
        }

        if ($message->envelopeTo === []) {
            foreach ([...$parsed['to'], ...$parsed['cc'], ...$parsed['bcc']] as $address) {
                $message->envelopeTo[] = $address->email;
            }
        }

        $message->tag  = isset($payload['tag']) && $payload['tag'] !== '' ? (string) $payload['tag'] : null;
        $message->meta = (array) ($payload['meta'] ?? []);

        $validator->check($message->envelopeTo !== [], 'У письма нет получателей: ни в заголовках, ни в конверте');
        $validator->check(
            strlen($raw) <= (int) Config::get('limits.max_message_size', 25 * 1024 * 1024),
            'Письмо слишком большое'
        );
        $validator->throwIfFails();

        return [
            'message' => $message,
            'options' => [
                'transport'       => isset($payload['transport']) && $payload['transport'] !== ''
                    ? (string) $payload['transport']
                    : null,
                'idempotency_key' => null,
                'priority'        => (int) ($payload['priority'] ?? 100),
            ],
        ];
    }
}
