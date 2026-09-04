<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $this->addIndex(
            'wallet_debit_allocations',
            ['course_order_id', 'credit_lot_id'],
            'wallet_allocations_course_report'
        );
        $this->addIndex(
            'playback_sessions',
            ['course_section_id', 'started_at', 'user_id'],
            'playback_sessions_course_report'
        );
    }

    public function down(): void
    {
        $this->dropIndex('playback_sessions', 'playback_sessions_course_report');
        $this->dropIndex('wallet_debit_allocations', 'wallet_allocations_course_report');
    }

    /** @param list<string> $columns */
    private function addIndex(string $tableName, array $columns, string $indexName): void
    {
        if (!Schema::hasTable($tableName) || Schema::hasIndex($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, fn (Blueprint $table) =>
            $table->index($columns, $indexName)
        );
    }

    private function dropIndex(string $tableName, string $indexName): void
    {
        if (!Schema::hasTable($tableName) || !Schema::hasIndex($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, fn (Blueprint $table) =>
            $table->dropIndex($indexName)
        );
    }
};
