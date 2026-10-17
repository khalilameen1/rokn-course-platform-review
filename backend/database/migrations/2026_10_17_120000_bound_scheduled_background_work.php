<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give every scheduled scan an index whose leading columns match its
 * equality predicates (MySQL left-prefix rule), so the scan stops at its
 * LIMIT instead of walking rows that can no longer match.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // courses:resume-checkouts: a per-row retry deadline with backoff, as
        // in Laravel's database queue (jobs.available_at / attempts).
        if (Schema::hasTable('course_checkouts')) {
            if (!Schema::hasColumn('course_checkouts', 'resume_attempts')) {
                Schema::table('course_checkouts', fn (Blueprint $table) =>
                    $table->unsignedSmallInteger('resume_attempts')->default(0)->after('error_code')
                );
            }
            if (!Schema::hasColumn('course_checkouts', 'next_resume_at')) {
                Schema::table('course_checkouts', fn (Blueprint $table) =>
                    $table->timestamp('next_resume_at')->nullable()->after('resume_attempts')
                );
            }
        }
        $this->addIndex('course_checkouts', ['status', 'next_resume_at', 'id'], 'course_checkouts_resume_queue');

        // payments:reconcile-kashier: find the first order inside the provider
        // reconciliation window without scanning the whole order history.
        $this->addIndex('orders', ['payment_method', 'created_at', 'id'], 'orders_provider_reconcile_window');

        // ops:monitor-runtime: actionable dead letters only. NOT IN over the
        // second column becomes index ranges that skip benign failure codes.
        $this->addIndex(
            'student_notifications',
            ['push_sent_at', 'push_failure_code', 'push_failed_at', 'user_id'],
            'student_push_actionable_dead_letters'
        );

        // playback:maintain rollups: un-rolled sessions first, ordered by
        // ended_at, so the locking read never walks already rolled-up rows.
        $this->addIndex(
            'playback_sessions',
            ['metrics_rolled_up_at', 'ended_at', 'id'],
            'playback_sessions_rollup_pending'
        );
    }

    public function down(): void
    {
        $this->dropIndex('playback_sessions', 'playback_sessions_rollup_pending');
        $this->dropIndex('student_notifications', 'student_push_actionable_dead_letters');
        $this->dropIndex('orders', 'orders_provider_reconcile_window');
        $this->dropIndex('course_checkouts', 'course_checkouts_resume_queue');
        foreach (['next_resume_at', 'resume_attempts'] as $column) {
            if (Schema::hasTable('course_checkouts') && Schema::hasColumn('course_checkouts', $column)) {
                Schema::table('course_checkouts', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }

    /** @param list<string> $columns */
    private function addIndex(string $tableName, array $columns, string $indexName): void
    {
        if (
            !Schema::hasTable($tableName)
            || !Schema::hasColumns($tableName, $columns)
            || Schema::hasIndex($tableName, $indexName)
        ) {
            return;
        }

        Schema::table($tableName, fn (Blueprint $table) => $table->index($columns, $indexName));
    }

    private function dropIndex(string $tableName, string $indexName): void
    {
        if (!Schema::hasTable($tableName) || !Schema::hasIndex($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, fn (Blueprint $table) => $table->dropIndex($indexName));
    }
};
