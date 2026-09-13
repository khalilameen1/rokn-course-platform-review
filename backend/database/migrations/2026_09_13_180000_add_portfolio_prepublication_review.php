<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasColumn('users', 'portfolio_sharing_status')) {
            Schema::table('users', function (Blueprint $table): void {
                // Deliberately no grandfathering: existing links also await review.
                $table->string('portfolio_sharing_status', 16)->default('pending');
            });
        }
        if (!Schema::hasColumn('users', 'portfolio_sharing_revision')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->unsignedBigInteger('portfolio_sharing_revision')->default(1);
            });
        }
        if (!Schema::hasColumn('users', 'portfolio_approved_hash')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->char('portfolio_approved_hash', 64)->nullable();
            });
        }
        if (!Schema::hasColumn('users', 'portfolio_reviewed_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->timestamp('portfolio_reviewed_at')->nullable();
            });
        }
        if (!Schema::hasColumn('users', 'portfolio_reviewed_by')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->unsignedBigInteger('portfolio_reviewed_by')->nullable();
            });
        }
        if (!Schema::hasColumn('users', 'portfolio_sharing_rejection_reason')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->text('portfolio_sharing_rejection_reason')->nullable();
            });
        }
        if (!Schema::hasIndex('users', 'users_portfolio_sharing_status_index')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->index('portfolio_sharing_status');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['portfolio_sharing_status']);
            $table->dropColumn([
                'portfolio_sharing_status', 'portfolio_sharing_revision',
                'portfolio_approved_hash', 'portfolio_reviewed_at',
                'portfolio_reviewed_by', 'portfolio_sharing_rejection_reason',
            ]);
        });
    }
};
