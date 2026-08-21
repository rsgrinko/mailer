<?php

declare(strict_types=1);

namespace Mailer\Migrations;

use Mailer\Storage\Migration;
use Mailer\Storage\Schema\Blueprint;

/**
 * Полнотекстовый индекс для поиска по письмам. Есть только в MySQL —
 * в SQLite полнотекста нет, там поиск остаётся на LIKE (см. MessageRepository).
 */
final class MessageFulltext extends Migration
{
    public function up(): void
    {
        $this->table('messages', function (Blueprint $table) {
            $table->fulltext('ft_messages_search', 'subject, to_json, from_email');
        });
    }

    public function down(): void
    {
        if ($this->isSqlite()) {
            return;
        }

        $this->table('messages', function (Blueprint $table) {
            $table->dropIndex('ft_messages_search');
        });
    }
}
