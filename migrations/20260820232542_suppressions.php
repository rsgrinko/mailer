<?php

declare(strict_types=1);

namespace Mailer\Migrations;

use Mailer\Storage\Migration;
use Mailer\Storage\Schema\Blueprint;

/**
 * Стоп-лист адресов: кому больше не пишем. Сюда попадают отказы почтовых серверов,
 * жалобы на спам и отписки, а руками — всё остальное.
 *
 * `project_id` пустой — адрес закрыт для всех проектов: несуществующего ящика
 * не существует ни для кого. Заполненный ограничивает запрет одним проектом:
 * отписка от рассылки одного приложения не должна отменять письма другого.
 *
 * `expires_at` — для мягких отказов вроде «ящик переполнен»: через срок адрес
 * снова разблокируется сам.
 */
final class Suppressions extends Migration
{
    public function up(): void
    {
        $this->create('suppressions', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->integer('project_id')->nullable();
            $table->integer('owner_id')->default(0);
            $table->string('reason', 32);
            $table->string('source', 32);
            $table->integer('message_id')->nullable();
            $table->text('note')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->index('idx_suppressions_email', 'email');
            $table->index('idx_suppressions_project', 'project_id');
            $table->index('idx_suppressions_owner', 'owner_id, created_at');
        });
    }

    public function down(): void
    {
        $this->drop('suppressions');
    }
}
