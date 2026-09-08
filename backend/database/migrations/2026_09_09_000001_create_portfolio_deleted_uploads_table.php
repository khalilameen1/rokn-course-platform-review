<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_deleted_uploads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portfolio_item_id')->constrained('portfolio_items')->cascadeOnDelete();
            $table->uuid('client_request_id');
            $table->unique(['portfolio_item_id', 'client_request_id'], 'portfolio_deleted_upload_request_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_deleted_uploads');
    }
};
