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
        if (
            Schema::hasTable('social_oauth_attempts')
            && !Schema::hasColumn('social_oauth_attempts', 'completion_claim_id')
        ) {
            Schema::table('social_oauth_attempts', function (Blueprint $table): void {
                $table->uuid('completion_claim_id')
                    ->nullable()
                    ->after('completion_processing_at')
                    ->index();
            });
        }
    }

    public function down(): void
    {
        if (
            Schema::hasTable('social_oauth_attempts')
            && Schema::hasColumn('social_oauth_attempts', 'completion_claim_id')
        ) {
            Schema::table('social_oauth_attempts', function (Blueprint $table): void {
                $table->dropIndex(['completion_claim_id']);
                $table->dropColumn('completion_claim_id');
            });
        }
    }
};
