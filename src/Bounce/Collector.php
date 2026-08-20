<?php

declare(strict_types=1);

namespace Mailer\Bounce;

use Mailer\Repository\EventRepository;
use Mailer\Repository\MessageRepository;
use Mailer\Repository\SuppressionRepository;
use Mailer\Storage\Database;
use Mailer\Support\Config;
use Mailer\Support\Logger;
use Throwable;

/**
 * Сборщик отказов: читает ящик, куда возвращаются недоставленные письма, и закрывает
 * адреса, по которым пришёл окончательный отказ.
 *
 * Синхронные отказы (сервер сказал «нет такого ящика» прямо на RCPT) сервис ловит и без
 * этого — в Sender. Сюда попадает всё остальное: сервер принял письмо и только потом
 * прислал отчёт о недоставке, а такое бывает чаще.
 */
final class Collector
{
    private Database $db;
    private SuppressionRepository $suppressions;
    private MessageRepository $messages;
    private EventRepository $events;
    private Logger $logger;

    public function __construct(?Database $db = null)
    {
        $this->db           = $db ?? Database::instance();
        $this->suppressions = new SuppressionRepository($this->db);
        $this->messages     = new MessageRepository($this->db);
        $this->events       = new EventRepository($this->db);
        $this->logger       = new Logger('bounce');
    }

    public static function enabled(): bool
    {
        return (bool) Config::get('bounce.enabled', false)
            && (string) Config::get('bounce.host', '') !== '';
    }

    /**
     * Забирает и разбирает письма из ящика отказов.
     *
     * @return array{fetched: int, suppressed: int, skipped: int}
     */
    public function run(?int $limit = null): array
    {
        $limit  = $limit ?? (int) Config::get('bounce.limit', 50);
        $client = new Pop3Client([
            'host'       => (string) Config::get('bounce.host', ''),
            'port'       => (int) Config::get('bounce.port', 995),
            'encryption' => (string) Config::get('bounce.encryption', 'ssl'),
            'username'   => (string) Config::get('bounce.username', ''),
            'password'   => (string) Config::get('bounce.password', ''),
        ]);

        $result = ['fetched' => 0, 'suppressed' => 0, 'skipped' => 0];
        $delete = (bool) Config::get('bounce.delete', true);

        try {
            foreach (array_slice($client->messages(), 0, max(1, $limit)) as $number) {
                $raw = $client->fetch($number);
                $result['fetched']++;

                $applied = $this->handle($raw);
                $result['suppressed'] += $applied;

                if ($applied === 0) {
                    $result['skipped']++;
                }

                // Разобранное письмо в ящике не держим: иначе следующий заход прочитает его снова
                if ($delete) {
                    $client->delete($number);
                }
            }

            $client->quit();
        } catch (Throwable $e) {
            $client->close();

            throw $e;
        }

        if ($result['fetched'] > 0) {
            $this->logger->info('Разобраны отказы', $result);
        }

        return $result;
    }

    /**
     * Разбирает одно письмо-отказ. Возвращает, сколько адресов закрыли.
     */
    public function handle(string $raw): int
    {
        $report  = DsnParser::parse($raw);
        $message = $report['uuid'] === null ? null : $this->messages->findByUuid($report['uuid']);
        $closed  = 0;

        foreach ($report['recipients'] as $recipient) {
            $answer = $recipient['diagnostic'] !== '' ? $recipient['diagnostic'] : $recipient['status'];

            // Временная задержка адрес не закрывает: письмо ещё может дойти
            if (!$recipient['permanent'] || !SuppressionRepository::isHardBounce($recipient['status'] . ' ' . $answer)) {
                $this->note($message, $recipient, false);

                continue;
            }

            $this->suppressions->block($recipient['email'], SuppressionRepository::BOUNCE, 'bounce', [
                'message_id' => $message === null ? 0 : (int) $message['id'],
                'owner_id'   => $message === null ? 0 : (int) ($message['owner_id'] ?? 0),
                'note'       => $answer,
            ]);

            $this->note($message, $recipient, true);
            $closed++;

            $this->logger->warning('Адрес закрыт по отказу из ящика', [
                'recipient' => $recipient['email'],
                'status'    => $recipient['status'],
                'uuid'      => $report['uuid'],
            ]);
        }

        return $closed;
    }

    /**
     * Отметка в истории письма — если понятно, о каком письме речь.
     *
     * @param array<string, mixed>|null $message
     * @param array{email: string, status: string, action: string, diagnostic: string, permanent: bool} $recipient
     */
    private function note(?array $message, array $recipient, bool $blocked): void
    {
        if ($message === null) {
            return;
        }

        $this->events->add(
            (int) $message['id'],
            EventRepository::SUPPRESSED,
            $blocked
                ? 'Отказ от сервера получателя: адрес ' . $recipient['email'] . ' закрыт стоп-листом'
                : 'Отказ от сервера получателя по адресу ' . $recipient['email'] . ' — временный, адрес оставлен',
            $recipient
        );
    }
}
