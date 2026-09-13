<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasColumn('users', 'portfolio_sharing_suspended_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->timestamp('portfolio_sharing_suspended_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('portfolio_sharing_suspended_at');
        });
    }
};
