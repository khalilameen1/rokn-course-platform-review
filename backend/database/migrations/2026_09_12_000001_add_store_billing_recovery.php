<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_billing_accounts', function (Blueprint $table): void {
            // Persist the existing opaque HMAC, not an email or store identity.
            // Multiple bindings per user preserve already-issued receipts if
            // an operator deliberately rotates the binding key.
            $table->char('google_account_binding', 64)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at');
        });
        Schema::table('store_purchases', function (Blueprint $table): void {
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('finalization_retry_at')->nullable();
            $table->index(['provider', 'finalized_at', 'finalization_retry_at'], 'store_finalization_recovery');
        });
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
