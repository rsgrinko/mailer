<?php

declare(strict_types=1);

namespace Mailer\Migrations;

use Mailer\Domain\Permission;
use Mailer\Storage\Migration;
use Mailer\Storage\Schema\Blueprint;

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
 */
final class Access extends Migration
{
    public function up(): void
    {
        $this->create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('permissions');
            $table->integer('is_system')->default(0);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->unique('idx_roles_name', 'name');
        });

        $now = $this->now();

        $this->statement(
            'INSERT INTO roles (name, description, permissions, is_system, created_at, updated_at)
                VALUES (:name, :description, :permissions, 1, :created_at, :updated_at)',
            [
                'name'        => 'Администратор',
                'description' => 'Полный доступ ко всем данным и настройкам сервиса',
                'permissions' => (string) json_encode(Permission::admin()),
                'created_at'  => $now,
                'updated_at'  => $now,
            ]
        );

        $this->statement(
            'INSERT INTO roles (name, description, permissions, is_system, created_at, updated_at)
                VALUES (:name, :description, :permissions, 0, :created_at, :updated_at)',
            [
                'name'        => 'Пользователь',
                'description' => 'Свои проекты, транспорты, шаблоны и письма',
                'permissions' => (string) json_encode(Permission::user()),
                'created_at'  => $now,
                'updated_at'  => $now,
            ]
        );

        $this->table('users', function (Blueprint $table) {
            $table->integer('role_id')->nullable();
        });

        $this->table('projects', function (Blueprint $table) {
            $table->integer('owner_id')->default(0);
            $table->index('idx_projects_owner', 'owner_id');
        });

        $this->table('transports', function (Blueprint $table) {
            $table->integer('owner_id')->default(0);
            $table->integer('shared')->default(0);
            $table->index('idx_transports_owner', 'owner_id');
        });

        $this->table('templates', function (Blueprint $table) {
            $table->integer('owner_id')->default(0);
            $table->index('idx_templates_owner', 'owner_id');
        });

        $this->table('messages', function (Blueprint $table) {
            $table->integer('owner_id')->default(0);
            $table->index('idx_messages_owner', 'owner_id, created_at');
        });

        // Те, кто уже работал в панели, ничего не теряют
        $this->statement(
            'UPDATE users SET role_id = (SELECT id FROM roles WHERE is_system = 1 ORDER BY id LIMIT 1)'
        );
        $this->statement('UPDATE transports SET shared = 1');
    }

    public function down(): void
    {
        $this->table('messages', function (Blueprint $table) {
            $table->dropIndex('idx_messages_owner');
            $table->dropColumn('owner_id');
        });

        $this->table('templates', function (Blueprint $table) {
            $table->dropIndex('idx_templates_owner');
            $table->dropColumn('owner_id');
        });

        $this->table('transports', function (Blueprint $table) {
            $table->dropIndex('idx_transports_owner');
            $table->dropColumn('owner_id');
            $table->dropColumn('shared');
        });

        $this->table('projects', function (Blueprint $table) {
            $table->dropIndex('idx_projects_owner');
            $table->dropColumn('owner_id');
        });

        $this->table('users', function (Blueprint $table) {
            $table->dropColumn('role_id');
        });

        $this->drop('roles');
    }
}
