<?php

declare(strict_types=1);

namespace Mailer\Http\Controllers;

use Mailer\Http\Request;
use Mailer\Http\Response;
use Mailer\Queue\Worker;
use Mailer\Repository\MessageRepository;
use Mailer\Repository\SettingRepository;
use Mailer\Storage\Database;
use Throwable;

/**
 * Проверка состояния сервиса — для мониторинга.
 */
final class HealthController
{
    /**
     * GET /api/v1/health
     */
    public function health(Request $request): Response
    {
        $status = 'ok';
        $checks = [];

        // База
        try {
            Database::instance()->value('SELECT 1');
            $checks['database'] = ['ok' => true];
        } catch (Throwable $e) {
            $status             = 'error';
            $checks['database'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        // Очередь и воркер
        if (($checks['database']['ok'] ?? false) === true) {
            $stats = (new MessageRepository())->stats();

            $checks['queue'] = [
                'ok'      => true,
                'ready'   => $stats['queue_ready'],
                'delayed' => $stats['queue_delayed'],
                'failed'  => $stats['by_status']['failed'] ?? 0,
            ];

            $heartbeat = (new SettingRepository())->get(Worker::HEARTBEAT_KEY);
            if ($heartbeat === null) {
                $checks['worker'] = ['ok' => false, 'error' => 'Воркер ещё ни разу не запускался'];
                $status           = $status === 'ok' ? 'degraded' : $status;
            } else {
                $data    = (array) json_decode($heartbeat, true);
                $seconds = time() - (int) strtotime((string) ($data['time'] ?? 'now'));
                $alive   = $seconds < 120;

                $checks['worker'] = [
                    'ok'             => $alive,
                    'last_seen'      => $data['time'] ?? null,
                    'seconds_ago'    => $seconds,
                    'processed'      => $data['processed'] ?? 0,
                ];

                if (!$alive) {
                    $status = $status === 'ok' ? 'degraded' : $status;
                }
            }
        }

        return Response::json([
            'status'  => $status,
            'time'    => date('c'),
            'checks'  => $checks,
        ], $status === 'error' ? 503 : 200);
    }
}
