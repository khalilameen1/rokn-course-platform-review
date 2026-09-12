<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Deliberately no grandfathering: existing links also await review.
            $table->string('portfolio_sharing_status', 16)->default('pending')->index();
            $table->unsignedBigInteger('portfolio_sharing_revision')->default(1);
            $table->char('portfolio_approved_hash', 64)->nullable();
            $table->timestamp('portfolio_reviewed_at')->nullable();
            $table->unsignedBigInteger('portfolio_reviewed_by')->nullable();
            $table->text('portfolio_sharing_rejection_reason')->nullable();
        });
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
