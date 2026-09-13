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
        if (!Schema::hasTable('store_billing_accounts')) {
            Schema::create('store_billing_accounts', function (Blueprint $table): void {
                // Persist the existing opaque HMAC, not an email or store identity.
                // Multiple bindings per user preserve already-issued receipts if
                // an operator deliberately rotates the binding key.
                $table->char('google_account_binding', 64)->primary();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->timestamp('created_at');
            });
        }

        // MySQL commits CREATE (including the primary key) before Laravel's
        // separate foreign-key statement. Repair that boundary without
        // replacing persisted bindings or changing their uniqueness.
        $hasUserReference = collect(Schema::getForeignKeys('store_billing_accounts'))->contains(
            static fn (array $key): bool => $key['columns'] === ['user_id']
                && $key['foreign_table'] === 'users'
                && $key['foreign_columns'] === ['id']
                && $key['on_delete'] === 'cascade'
        );
        if (!$hasUserReference) {
            Schema::table('store_billing_accounts', function (Blueprint $table): void {
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
        if (!Schema::hasColumn('store_purchases', 'finalized_at')) {
            Schema::table('store_purchases', function (Blueprint $table): void {
                $table->timestamp('finalized_at')->nullable();
            });
        }
        if (!Schema::hasColumn('store_purchases', 'finalization_retry_at')) {
            Schema::table('store_purchases', function (Blueprint $table): void {
                $table->timestamp('finalization_retry_at')->nullable();
            });
        }
        if (!Schema::hasIndex('store_purchases', 'store_finalization_recovery')) {
            Schema::table('store_purchases', function (Blueprint $table): void {
                $table->index(['provider', 'finalized_at', 'finalization_retry_at'], 'store_finalization_recovery');
            });
        }
    }

    public function down(): void
    {
        Schema::table('store_purchases', function (Blueprint $table): void {
            $table->dropIndex('store_finalization_recovery');
            $table->dropColumn(['finalized_at', 'finalization_retry_at']);
        });
        Schema::dropIfExists('store_billing_accounts');
    }
};
