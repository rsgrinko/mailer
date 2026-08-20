<?php

declare(strict_types=1);

namespace Mailer\Storage;

use Mailer\Domain\Permission;

/**
 * Миграции. Список запросов лежит прямо здесь: их немного, зато всё видно в одном месте.
 * Уже применённые миграции запоминаются в таблице migrations.
 */
final class Migrator
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
    }

    /**
     * Применяет все новые миграции. Возвращает список применённых имён.
     *
     * @return array<int, string>
     */
    public function run(): array
    {
        $this->createMigrationsTable();

        $applied = [];
        foreach ($this->migrations() as $name => $statements) {
            if ($this->isApplied($name)) {
                continue;
            }

            foreach ($statements as $sql) {
                $this->db->execute($sql);
            }

            $this->db->insert('migrations', [
                'name'       => $name,
                'applied_at' => Database::now(),
            ]);

            $applied[] = $name;
        }

        return $applied;
    }

    /**
     * Список ещё не применённых миграций.
     *
     * @return array<int, string>
     */
    public function pending(): array
    {
        if (!$this->db->hasTable('migrations')) {
            return array_keys($this->migrations());
        }

        $pending = [];
        foreach (array_keys($this->migrations()) as $name) {
            if (!$this->isApplied($name)) {
                $pending[] = $name;
            }
        }

        return $pending;
    }

    private function isApplied(string $name): bool
    {
        return $this->db->selectOne('SELECT name FROM migrations WHERE name = :name', ['name' => $name]) !== null;
    }

    private function createMigrationsTable(): void
    {
        $this->db->execute(
            'CREATE TABLE IF NOT EXISTS migrations ('
            . 'name ' . $this->str(191) . ' NOT NULL PRIMARY KEY, '
            . 'applied_at ' . $this->dt() . ' NOT NULL'
            . ')' . $this->tableSuffix()
        );
    }

    /**
     * Все миграции сервиса.
     *
     * @return array<string, array<int, string>>
     */
    private function migrations(): array
    {
        return [
            '0001_init'  => $this->initialSchema(),
            '0002_users' => $this->usersSchema(),
            '0003_message_indexes' => $this->messageIndexes(),
            '0004_message_fulltext' => $this->messageFulltext(),
            '0005_message_sender' => $this->messageSender(),
            '0006_access' => $this->accessSchema(),
        ];
    }

    /**
     * Роли и владельцы записей. До неё в панели все были равны и видели всё.
     *
     * Владелец — `owner_id`, ноль означает «ничьё»: так помечены записи, заведённые
     * до разделения прав, и их видит только тот, у кого есть право data.all.
     * Транспортам такие записи заодно ставим `shared = 1` — иначе после миграции
     * обычный пользователь останется без единого способа отправки.
     *
     * У писем владелец свой, а не через проект: письмо из панели проекта может не иметь
     * вовсе, а искать владельца подзапросом на каждом списке — лишняя работа для базы.
     *
     * @return array<int, string>
     */
    private function accessSchema(): array
    {
        $id   = $this->id();
        $str  = fn (int $length = 191): string => $this->str($length);
        $text = $this->text();
        $int  = $this->int();
        $dt   = $this->dt();
        $end  = $this->tableSuffix();

        $admin = json_encode(Permission::admin());
        $user  = json_encode(Permission::user());
        $now   = Database::now();

        return [
            "CREATE TABLE IF NOT EXISTS roles (
                id {$id},
                name {$str(191)} NOT NULL,
                description {$text} NULL,
                permissions {$text} NOT NULL,
                is_system {$int} NOT NULL DEFAULT 0,
                created_at {$dt} NOT NULL,
                updated_at {$dt} NOT NULL
            ){$end}",
            $this->index('idx_roles_name', 'roles', 'name', true),

            "INSERT INTO roles (name, description, permissions, is_system, created_at, updated_at)
                VALUES ('Администратор', 'Полный доступ ко всем данным и настройкам сервиса', '{$admin}', 1, '{$now}', '{$now}')",
            "INSERT INTO roles (name, description, permissions, is_system, created_at, updated_at)
                VALUES ('Пользователь', 'Свои проекты, транспорты, шаблоны и письма', '{$user}', 0, '{$now}', '{$now}')",

            "ALTER TABLE users ADD COLUMN role_id {$int} NULL",
            "ALTER TABLE projects ADD COLUMN owner_id {$int} NOT NULL DEFAULT 0",
            "ALTER TABLE transports ADD COLUMN owner_id {$int} NOT NULL DEFAULT 0",
            "ALTER TABLE transports ADD COLUMN shared {$int} NOT NULL DEFAULT 0",
            "ALTER TABLE templates ADD COLUMN owner_id {$int} NOT NULL DEFAULT 0",
            "ALTER TABLE messages ADD COLUMN owner_id {$int} NOT NULL DEFAULT 0",

            $this->index('idx_projects_owner', 'projects', 'owner_id'),
            $this->index('idx_transports_owner', 'transports', 'owner_id'),
            $this->index('idx_templates_owner', 'templates', 'owner_id'),
            $this->index('idx_messages_owner', 'messages', 'owner_id, created_at'),

            // Те, кто уже работал в панели, ничего не теряют
            "UPDATE users SET role_id = (SELECT id FROM roles WHERE name = 'Администратор')",
            'UPDATE transports SET shared = 1',
        ];
    }

    /**
     * Адрес, с которого письмо ушло на самом деле. В `from_email` лежит то, что прислал
     * клиент, а транспорт с `force_from` подменяет отправителя уже на отправке —
     * без отдельной колонки в карточке письма не понять, почему адрес не тот.
     *
     * @return array<int, string>
     */
    private function messageSender(): array
    {
        return [
            'ALTER TABLE messages ADD COLUMN sender_used ' . $this->str(191) . ' NULL',
        ];
    }

    /**
     * Полнотекстовый индекс для поиска по письмам. Есть только в MySQL —
     * в SQLite полнотекста нет, там поиск остаётся на LIKE (см. MessageRepository).
     *
     * @return array<int, string>
     */
    private function messageFulltext(): array
    {
        if ($this->db->isSqlite()) {
            return [];
        }

        return [
            'ALTER TABLE messages ADD FULLTEXT INDEX ft_messages_search (subject, to_json, from_email)',
        ];
    }

    /**
     * Индексы под запросы дашборда и списков. Без них при десятках тысяч писем
     * обзор считался сотнями миллисекунд: «отправлено сегодня», «ошибок сегодня»,
     * график за две недели и самое старое письмо в очереди шли полным перебором.
     *
     * @return array<int, string>
     */
    private function messageIndexes(): array
    {
        return [
            $this->index('idx_messages_status_sent', 'messages', 'status, sent_at'),
            $this->index('idx_messages_status_updated', 'messages', 'status, updated_at'),
            $this->index('idx_messages_status_created', 'messages', 'status, created_at'),
            $this->index('idx_messages_created_status', 'messages', 'created_at, status'),
        ];
    }

    /**
     * Пользователи панели. Появились позже остальных таблиц, поэтому отдельной миграцией.
     *
     * @return array<int, string>
     */
    private function usersSchema(): array
    {
        $id  = $this->id();
        $str = fn (int $length = 191): string => $this->str($length);
        $int = $this->int();
        $dt  = $this->dt();
        $end = $this->tableSuffix();

        return [
            "CREATE TABLE IF NOT EXISTS users (
                id {$id},
                login {$str(191)} NOT NULL,
                name {$str(191)} NULL,
                password_hash {$str(191)} NOT NULL,
                active {$int} NOT NULL DEFAULT 1,
                last_login_at {$dt} NULL,
                last_login_ip {$str(64)} NULL,
                created_at {$dt} NOT NULL,
                updated_at {$dt} NOT NULL
            ){$end}",
            $this->index('idx_users_login', 'users', 'login', true),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function initialSchema(): array
    {
        $id   = $this->id();
        $str  = fn (int $length = 191): string => $this->str($length);
        $text = $this->text();
        $long = $this->longText();
        $int  = $this->int();
        $dt   = $this->dt();
        $end  = $this->tableSuffix();
        $idx  = fn (string $name, string $table, string $columns, bool $unique = false): string
            => $this->index($name, $table, $columns, $unique);

        return [
            // Проекты — это клиенты нашего API. У каждого свой ключ и свои лимиты.
            "CREATE TABLE IF NOT EXISTS projects (
                id {$id},
                name {$str(191)} NOT NULL,
                description {$text} NULL,
                api_key_prefix {$str(32)} NOT NULL,
                api_key_hash {$str(191)} NOT NULL,
                transport_id {$int} NULL,
                default_from_email {$str(191)} NULL,
                default_from_name {$str(191)} NULL,
                rate_limit_hour {$int} NOT NULL DEFAULT 0,
                rate_limit_day {$int} NOT NULL DEFAULT 0,
                webhook_url {$str(500)} NULL,
                webhook_secret {$str(191)} NULL,
                active {$int} NOT NULL DEFAULT 1,
                created_at {$dt} NOT NULL,
                updated_at {$dt} NOT NULL
            ){$end}",
            $idx('idx_projects_name', 'projects', 'name', true),
            $idx('idx_projects_prefix', 'projects', 'api_key_prefix', true),

            // Транспорты — способы отправки: smtp, sendmail, log, null, failover, roundrobin.
            "CREATE TABLE IF NOT EXISTS transports (
                id {$id},
                name {$str(191)} NOT NULL,
                type {$str(32)} NOT NULL,
                settings {$text} NOT NULL,
                from_email {$str(191)} NULL,
                from_name {$str(191)} NULL,
                priority {$int} NOT NULL DEFAULT 100,
                daily_limit {$int} NOT NULL DEFAULT 0,
                is_default {$int} NOT NULL DEFAULT 0,
                active {$int} NOT NULL DEFAULT 1,
                last_used_at {$dt} NULL,
                last_error {$text} NULL,
                created_at {$dt} NOT NULL,
                updated_at {$dt} NOT NULL
            ){$end}",
            $idx('idx_transports_name', 'transports', 'name', true),

            // Письма. Тут же лежит история попыток отправки.
            "CREATE TABLE IF NOT EXISTS messages (
                id {$id},
                uuid {$str(36)} NOT NULL,
                project_id {$int} NULL,
                transport_id {$int} NULL,
                transport_used {$str(191)} NULL,
                status {$str(20)} NOT NULL DEFAULT 'queued',
                priority {$int} NOT NULL DEFAULT 100,
                source {$str(20)} NOT NULL DEFAULT 'api',
                subject {$str(500)} NULL,
                from_email {$str(191)} NULL,
                from_name {$str(191)} NULL,
                reply_to {$str(191)} NULL,
                to_json {$text} NULL,
                cc_json {$text} NULL,
                bcc_json {$text} NULL,
                headers_json {$text} NULL,
                text_body {$long} NULL,
                html_body {$long} NULL,
                attachments_json {$text} NULL,
                raw_mime {$long} NULL,
                envelope_from {$str(191)} NULL,
                envelope_to {$text} NULL,
                template {$str(191)} NULL,
                template_data {$text} NULL,
                meta {$text} NULL,
                tag {$str(191)} NULL,
                idempotency_key {$str(191)} NULL,
                size {$int} NOT NULL DEFAULT 0,
                attempts {$int} NOT NULL DEFAULT 0,
                max_attempts {$int} NOT NULL DEFAULT 5,
                available_at {$dt} NULL,
                locked_at {$dt} NULL,
                locked_by {$str(64)} NULL,
                last_error {$text} NULL,
                sent_at {$dt} NULL,
                created_at {$dt} NOT NULL,
                updated_at {$dt} NOT NULL
            ){$end}",
            $idx('idx_messages_uuid', 'messages', 'uuid', true),
            $idx('idx_messages_queue', 'messages', 'status, available_at'),
            $idx('idx_messages_project', 'messages', 'project_id, created_at'),
            $idx('idx_messages_created', 'messages', 'created_at'),
            $idx('idx_messages_idem', 'messages', 'project_id, idempotency_key'),

            // Что происходило с письмом: принято, попытка, ошибка, отправлено.
            "CREATE TABLE IF NOT EXISTS message_events (
                id {$id},
                message_id {$int} NOT NULL,
                type {$str(32)} NOT NULL,
                message {$text} NULL,
                meta {$text} NULL,
                created_at {$dt} NOT NULL
            ){$end}",
            $idx('idx_events_message', 'message_events', 'message_id, id'),

            // Шаблоны писем с переменными {{ name }}.
            "CREATE TABLE IF NOT EXISTS templates (
                id {$id},
                name {$str(191)} NOT NULL,
                description {$text} NULL,
                subject {$str(500)} NULL,
                html {$long} NULL,
                text {$long} NULL,
                created_at {$dt} NOT NULL,
                updated_at {$dt} NOT NULL
            ){$end}",
            $idx('idx_templates_name', 'templates', 'name', true),

            // Очередь вебхуков: сообщаем проекту, что случилось с письмом.
            "CREATE TABLE IF NOT EXISTS webhook_deliveries (
                id {$id},
                message_id {$int} NULL,
                project_id {$int} NULL,
                url {$str(500)} NOT NULL,
                event {$str(32)} NOT NULL,
                payload {$text} NOT NULL,
                status {$str(20)} NOT NULL DEFAULT 'queued',
                attempts {$int} NOT NULL DEFAULT 0,
                response_code {$int} NULL,
                last_error {$text} NULL,
                available_at {$dt} NULL,
                delivered_at {$dt} NULL,
                created_at {$dt} NOT NULL,
                updated_at {$dt} NOT NULL
            ){$end}",
            $idx('idx_webhooks_queue', 'webhook_deliveries', 'status, available_at'),

            // Счётчики для лимитов отправки.
            "CREATE TABLE IF NOT EXISTS counters (
                counter_key {$str(191)} NOT NULL PRIMARY KEY,
                value {$int} NOT NULL DEFAULT 0,
                expires_at {$dt} NULL,
                updated_at {$dt} NOT NULL
            ){$end}",

            // Служебные значения: heartbeat воркера, версия и т.п.
            "CREATE TABLE IF NOT EXISTS settings (
                setting_key {$str(191)} NOT NULL PRIMARY KEY,
                value {$text} NULL,
                updated_at {$dt} NOT NULL
            ){$end}",
        ];
    }

    // --- Типы колонок под конкретный драйвер ---------------------------------

    /**
     * SQL создания индекса. SQLite умеет IF NOT EXISTS, MySQL — нет,
     * но миграция выполняется один раз, так что для MySQL пишем без него.
     */
    private function index(string $name, string $table, string $columns, bool $unique = false): string
    {
        $unique = $unique ? 'UNIQUE ' : '';

        return $this->db->isSqlite()
            ? "CREATE {$unique}INDEX IF NOT EXISTS {$name} ON {$table} ({$columns})"
            : "CREATE {$unique}INDEX {$name} ON {$table} ({$columns})";
    }

    private function id(): string
    {
        return $this->db->isSqlite()
            ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
            : 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
    }

    private function str(int $length): string
    {
        return $this->db->isSqlite() ? 'TEXT' : 'VARCHAR(' . $length . ')';
    }

    private function text(): string
    {
        return $this->db->isSqlite() ? 'TEXT' : 'TEXT';
    }

    private function longText(): string
    {
        return $this->db->isSqlite() ? 'TEXT' : 'LONGTEXT';
    }

    private function int(): string
    {
        return $this->db->isSqlite() ? 'INTEGER' : 'BIGINT';
    }

    private function dt(): string
    {
        return $this->db->isSqlite() ? 'TEXT' : 'DATETIME';
    }

    private function tableSuffix(): string
    {
        return $this->db->isSqlite() ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }
}
