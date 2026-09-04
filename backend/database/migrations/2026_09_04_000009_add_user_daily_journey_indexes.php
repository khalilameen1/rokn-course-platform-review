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
            'course_enrollments',
            ['user_id', 'is_active', 'expires_at', 'access_granted_at', 'id'],
            'course_enrollments_user_learning_timeline'
        );
        $this->addIndex(
            'orders',
            ['user_id', 'payment_method', 'status', 'created_at', 'id'],
            'orders_user_payment_state_timeline'
        );
        $this->addIndex(
            'wallet_transactions',
            ['user_id', 'occurred_at', 'id'],
            'wallet_transactions_user_timeline_v2'
        );
    }

    public function down(): void
    {
        $this->dropIndex('wallet_transactions', 'wallet_transactions_user_timeline_v2');
        $this->dropIndex('orders', 'orders_user_payment_state_timeline');
        $this->dropIndex('course_enrollments', 'course_enrollments_user_learning_timeline');
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
