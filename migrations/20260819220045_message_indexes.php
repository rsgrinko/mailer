<?php

declare(strict_types=1);

namespace Mailer\Migrations;

use Mailer\Storage\Migration;
use Mailer\Storage\Schema\Blueprint;

/**
 * Индексы под запросы дашборда и списков. Без них при десятках тысяч писем
 * обзор считался сотнями миллисекунд: «отправлено сегодня», «ошибок сегодня»,
 * график за две недели и самое старое письмо в очереди шли полным перебором.
 */
final class MessageIndexes extends Migration
{
    public function up(): void
    {
        $this->table('messages', function (Blueprint $table) {
            $table->index('idx_messages_status_sent', 'status, sent_at');
            $table->index('idx_messages_status_updated', 'status, updated_at');
            $table->index('idx_messages_status_created', 'status, created_at');
            $table->index('idx_messages_created_status', 'created_at, status');
        });
    }

    public function down(): void
    {
        $this->table('messages', function (Blueprint $table) {
            $table->dropIndex('idx_messages_status_sent');
            $table->dropIndex('idx_messages_status_updated');
            $table->dropIndex('idx_messages_status_created');
            $table->dropIndex('idx_messages_created_status');
        });
    }
}
