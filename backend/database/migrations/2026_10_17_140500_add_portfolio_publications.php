<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Draft/published portfolio model (CMS publish workflow, App Store Connect
 * "the live version stays on sale while the new version is in review"):
 * the last admin-approved content is stored and served publicly while a newer
 * draft waits for review. Owner-controlled presentation (hide, cover) lives on
 * the item row so privacy removals apply without another review.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasTable('portfolio_publications')) {
            Schema::create('portfolio_publications', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
                $table->unsignedInteger('revision');
                $table->char('content_hash', 64);
                $table->longText('content');
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('portfolio_items')) {
            return;
        }
        if (!Schema::hasColumn('portfolio_items', 'public_id')) {
            Schema::table('portfolio_items', fn (Blueprint $table) =>
                $table->uuid('public_id')->nullable()->after('id')
            );
        }
        if (!Schema::hasColumn('portfolio_items', 'hidden_at')) {
            Schema::table('portfolio_items', fn (Blueprint $table) =>
                $table->timestamp('hidden_at')->nullable()->after('is_public')
            );
        }
        if (!Schema::hasColumn('portfolio_items', 'cover_media_id')) {
            Schema::table('portfolio_items', fn (Blueprint $table) =>
                $table->unsignedBigInteger('cover_media_id')->nullable()->after('hidden_at')
            );
        }

        // Resumable backfill: only rows still missing an identity are touched.
        DB::table('portfolio_items')->whereNull('public_id')->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('portfolio_items')->where('id', $row->id)->whereNull('public_id')
                        ->update(['public_id' => (string) Str::uuid()]);
                }
            });

        if (!Schema::hasIndex('portfolio_items', 'portfolio_items_public_id_unique')) {
            Schema::table('portfolio_items', fn (Blueprint $table) =>
                $table->unique('public_id', 'portfolio_items_public_id_unique')
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('portfolio_items')) {
            if (Schema::hasIndex('portfolio_items', 'portfolio_items_public_id_unique')) {
                Schema::table('portfolio_items', fn (Blueprint $table) =>
                    $table->dropUnique('portfolio_items_public_id_unique')
                );
            }
            foreach (['cover_media_id', 'hidden_at', 'public_id'] as $column) {
                if (Schema::hasColumn('portfolio_items', $column)) {
                    Schema::table('portfolio_items', fn (Blueprint $table) => $table->dropColumn($column));
                }
            }
        }
        Schema::dropIfExists('portfolio_publications');
    }
};
