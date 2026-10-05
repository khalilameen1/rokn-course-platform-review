<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Preserve the v60 server schedule until the learner chooses a time.
            $table->unsignedTinyInteger('learning_reminder_hour')->default(20);
            $table->string('learning_reminder_timezone', 64)->default('Africa/Cairo');
            $table->index(['notifications_status', 'learning_reminder_timezone', 'learning_reminder_hour'],
                'users_learning_reminder_due');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_learning_reminder_due');
            $table->dropColumn(['learning_reminder_hour', 'learning_reminder_timezone']);
        });
    }
};
