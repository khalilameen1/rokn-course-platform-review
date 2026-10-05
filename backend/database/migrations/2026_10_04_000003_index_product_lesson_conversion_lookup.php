<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_events', function (Blueprint $table): void {
            $table->index(['actor_key', 'course_id', 'lesson_id', 'event_name', 'occurred_at'],
                'product_lesson_conversion_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('product_events', function (Blueprint $table): void {
            $table->dropIndex('product_lesson_conversion_lookup');
        });
    }
};
