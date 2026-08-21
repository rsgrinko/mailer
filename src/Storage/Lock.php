<?php

declare(strict_types=1);

namespace Mailer\Storage;

/**
 * Блокировка на время долгой операции с базой — сейчас это накат миграций.
 *
 * Без неё кнопка в панели, нажатая дважды, или деплой одновременно с ручным
 * `migrate` разойдутся между проверкой «применена ли миграция» и записью о ней,
 * и одна и та же миграция выполнится дважды.
 *
 * В MySQL берём GET_LOCK — он общий для всех процессов, ходящих в эту базу.
 * В SQLite базой пользуются с одной машины, поэтому там хватает flock на файле.
 */
final class Lock
{
    private Database $db;
    private string $name;

    /** @var resource|null Файл блокировки для SQLite */
    private $handle = null;

    private bool $held = false;

    public function __construct(Database $db, string $name)
    {
        $this->db   = $db;
        $this->name = $name;
    }

    /**
     * Пытается взять блокировку, ожидая не дольше указанного времени.
     */
    public function acquire(int $seconds = 30): bool
    {
        if ($this->held) {
            return true;
        }

        $this->held = $this->db->isSqlite()
            ? $this->acquireFile($seconds)
            : $this->acquireMysql($seconds);

        return $this->held;
    }

    public function release(): void
    {
        if (!$this->held) {
            return;
        }

        if ($this->db->isSqlite()) {
            if (is_resource($this->handle)) {
                flock($this->handle, LOCK_UN);
                fclose($this->handle);
            }

            $this->handle = null;
        } else {
            $this->db->value('SELECT RELEASE_LOCK(:name)', ['name' => $this->name]);
        }

        $this->held = false;
    }

    public function __destruct()
    {
        $this->release();
    }

    /**
     * GET_LOCK живёт в соединении: оборвётся связь — пропадёт и блокировка.
     * Для миграций это то, что нужно: упавший процесс не оставит базу запертой.
     */
    private function acquireMysql(int $seconds): bool
    {
        return (int) $this->db->value(
            'SELECT GET_LOCK(:name, :timeout)',
            ['name' => $this->name, 'timeout' => $seconds]
        ) === 1;
    }

    private function acquireFile(int $seconds): bool
    {
        $dir = MAILER_ROOT . '/var/lock';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $handle = @fopen($dir . '/' . $this->name . '.lock', 'c');
        if ($handle === false) {
            throw new StorageException('Не удалось открыть файл блокировки: ' . $this->name);
        }

        $deadline = microtime(true) + $seconds;

        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                $this->handle = $handle;

                return true;
            }

            usleep(200000);
        } while (microtime(true) < $deadline);

        fclose($handle);

        return false;
    }
}
