<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The profile GET used to create (or rotate) the unlisted portfolio slug on
 * first read. GET is now a pure read (RFC 9110 safe method) and the slug is
 * created on registration and portfolio writes, so give every live account
 * without a valid slug one now, in the same resumable chunked shape as
 * 2026_10_17_131500_backfill_watch_history_recency. The format matches
 * PortfolioShareIdentityService (rokn- + 24 lowercase alphanumerics).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'portfolio_slug')) {
            return;
        }

        DB::table('users')
            ->whereNull('deleted_at')
            ->select(['id', 'portfolio_slug'])
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    if ($this->isValid((string) $row->portfolio_slug)) {
                        continue;
                    }
                    DB::table('users')
                        ->where('id', $row->id)
                        ->where(function ($unchanged) use ($row): void {
                            $row->portfolio_slug === null
                                ? $unchanged->whereNull('portfolio_slug')
                                : $unchanged->where('portfolio_slug', $row->portfolio_slug);
                        })
                        ->update(['portfolio_slug' => $this->freshSlug()]);
                }
            });
    }

    public function down(): void
    {
        // Share slugs are valid account data; nothing to reverse.
    }

    private function isValid(string $slug): bool
    {
        $slug = strtolower(trim($slug));

        return preg_match('/^rokn-[a-z0-9]{24}$/', $slug) === 1
            || preg_match('/^rokn-[a-f0-9]{32}$/', $slug) === 1;
    }

    private function freshSlug(): string
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $slug = 'rokn-'.Str::lower(Str::random(24));
            if (! DB::table('users')->where('portfolio_slug', $slug)->exists()) {
                return $slug;
            }
        }

        return 'rokn-'.str_replace('-', '', Str::uuid()->toString());
    }
};
