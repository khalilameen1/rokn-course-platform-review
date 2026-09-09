<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasTable('portfolio_deleted_uploads')) {
            Schema::create('portfolio_deleted_uploads', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('portfolio_item_id')->constrained('portfolio_items')->cascadeOnDelete();
                $table->uuid('client_request_id');
                $table->unique(['portfolio_item_id', 'client_request_id'], 'portfolio_deleted_upload_request_unique');
            });
        }

        // MySQL can commit CREATE before the separate foreign/index DDL.
        // Resume those constraints too, without replacing existing receipts.
        $hasPortfolioReference = collect(Schema::getForeignKeys('portfolio_deleted_uploads'))->contains(
            static fn (array $key): bool => $key['columns'] === ['portfolio_item_id']
                && $key['foreign_table'] === 'portfolio_items'
                && $key['foreign_columns'] === ['id']
                && $key['on_delete'] === 'cascade'
        );
        if (!$hasPortfolioReference) {
            Schema::table('portfolio_deleted_uploads', function (Blueprint $table): void {
                $table->foreign('portfolio_item_id')->references('id')->on('portfolio_items')->cascadeOnDelete();
            });
        }
        if (!Schema::hasIndex('portfolio_deleted_uploads', ['portfolio_item_id', 'client_request_id'], 'unique')) {
            Schema::table('portfolio_deleted_uploads', function (Blueprint $table): void {
                $table->unique(['portfolio_item_id', 'client_request_id'], 'portfolio_deleted_upload_request_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_deleted_uploads');
    }
};
