<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void { $this->replaceCheck(true); }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') return;
        if (DB::table('ai_usage_events')->where('feature', 'project_review')->exists()) {
            throw new RuntimeException('Cannot remove platform review accounting while its usage history exists.');
        }
        $this->replaceCheck(false);
    }

    private function replaceCheck(bool $includeReview): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') return;
        $name = 'ai_usage_events_state_check';
        $exists = DB::table('information_schema.table_constraints')
            ->where('constraint_schema', DB::getDatabaseName())->where('table_name', 'ai_usage_events')
            ->where('constraint_name', $name)->where('constraint_type', 'CHECK')->exists();
        if ($exists) DB::statement("ALTER TABLE `ai_usage_events` DROP CHECK `{$name}`");
        $features = "'course_chat', 'project_feedback', 'project_followup'".($includeReview ? ", 'project_review'" : '');
        DB::statement("ALTER TABLE `ai_usage_events` ADD CONSTRAINT `{$name}` CHECK ("
            ."feature IN ({$features}) AND status IN ('reserved', 'completed', 'failed', 'expired', 'cancelled'))");
    }
};
