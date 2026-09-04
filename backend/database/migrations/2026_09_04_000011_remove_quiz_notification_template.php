<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('admin_notifications')
            || !Schema::hasColumn('admin_notifications', 'system_key')) {
            return;
        }

        DB::table('admin_notifications')
            ->where('system_key', 'new_quiz')
            ->delete();
    }

    public function down(): void
    {
        // Quiz is not part of the product contract. Do not recreate an inert
        // template when rolling back unrelated deployment code.
    }
};
