<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Services\PortfolioShareIdentityService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * GET /user/profile and GET /portfolio-profile are safe reads (RFC 9110). The
 * unlisted portfolio slug is created on registration and portfolio writes,
 * and the release migration gives existing accounts one.
 */
final class ProfileReadWritePathTest extends ApiTestCase
{
    public function test_profile_reads_write_nothing_and_never_expose_a_missing_or_legacy_slug(): void
    {
        foreach ([null, 'my-custom-alias'] as $stored) {
            DB::table('users')->where('id', $this->user->id)->update(['portfolio_slug' => $stored]);
            $user = $this->user->fresh();

            $queries = $this->recordQueries(function () use ($user): void {
                $this->actingAs($user, 'api')->getJson('/api/v1/user/profile')
                    ->assertOk()
                    ->assertJsonPath('data.portfolio_slug', null)
                    ->assertJsonPath('data.portfolio_url', null);
                $this->actingAs($user, 'api')->getJson('/api/v1/portfolio-profile')
                    ->assertOk()
                    ->assertJsonPath('data.slug', null)
                    ->assertJsonPath('data.public_url', null)
                    ->assertJsonPath('data.sharing_status', 'pending');
            });

            self::assertSame([], $this->writes($queries));
            self::assertSame($stored, DB::table('users')->where('id', $this->user->id)->value('portfolio_slug'));
        }
    }

    public function test_the_first_portfolio_write_creates_the_slug_the_reads_then_return(): void
    {
        DB::table('course_enrollments')->insert([
            'user_id' => $this->user->id, 'course_id' => $this->courseId, 'is_active' => true,
        ]);
        self::assertNull($this->user->fresh()->portfolio_slug);

        $this->actingAs($this->user->fresh(), 'api')->postJson('/api/v1/portfolio', [
            'client_request_id' => (string) Str::uuid(),
            'title' => 'First project',
            'is_public' => false,
        ])->assertOk();

        $slug = (string) $this->user->fresh()->portfolio_slug;
        self::assertTrue(app(PortfolioShareIdentityService::class)->isValidUnlistedSlug($slug));
        $this->actingAs($this->user->fresh(), 'api')->getJson('/api/v1/user/profile')
            ->assertOk()->assertJsonPath('data.portfolio_slug', $slug);
        $this->actingAs($this->user->fresh(), 'api')->getJson('/api/v1/portfolio-profile')
            ->assertOk()->assertJsonPath('data.slug', $slug);
    }

    public function test_a_portfolio_profile_write_creates_the_slug(): void
    {
        $this->actingAs($this->user->fresh(), 'api')->putJson('/api/v1/portfolio-profile', [
            'portfolio_headline' => 'Product designer',
        ])->assertOk()->assertJsonPath('data.headline', 'Product designer');

        $slug = (string) $this->user->fresh()->portfolio_slug;
        self::assertTrue(app(PortfolioShareIdentityService::class)->isValidUnlistedSlug($slug));
    }

    public function test_release_migration_gives_live_accounts_a_slug_once(): void
    {
        $valid = 'rokn-'.str_repeat('a', 24);
        DB::table('users')->where('id', $this->user->id)->update(['portfolio_slug' => null]);
        $legacy = $this->insertUser('legacy@rokn.test', 'my-custom-alias');
        $kept = $this->insertUser('kept@rokn.test', $valid);
        $deleted = $this->insertUser('deleted@rokn.test', null, now());

        $migration = require database_path('migrations/2026_10_18_100000_backfill_portfolio_share_slugs.php');
        $migration->up();
        $first = DB::table('users')->orderBy('id')->pluck('portfolio_slug', 'id')->all();
        $migration->up();

        $identity = app(PortfolioShareIdentityService::class);
        self::assertTrue($identity->isValidUnlistedSlug((string) $first[$this->user->id]));
        self::assertTrue($identity->isValidUnlistedSlug((string) $first[$legacy]));
        self::assertSame($valid, $first[$kept]);
        self::assertNull($first[$deleted]);
        self::assertNotSame($first[$this->user->id], $first[$legacy]);
        self::assertSame($first, DB::table('users')->orderBy('id')->pluck('portfolio_slug', 'id')->all());
    }

    private function insertUser(string $email, ?string $slug, mixed $deletedAt = null): int
    {
        return (int) DB::table('users')->insertGetId([
            'name' => 'Learner', 'email' => $email, 'password' => 'x', 'active' => true,
            'portfolio_slug' => $slug, 'deleted_at' => $deletedAt,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return list<string> */
    private function recordQueries(callable $callback): array
    {
        $queries = [];
        $recording = true;
        DB::listen(function (QueryExecuted $query) use (&$queries, &$recording): void {
            if ($recording) {
                $queries[] = $query->sql;
            }
        });
        try {
            $callback();
        } finally {
            $recording = false;
        }

        return $queries;
    }

    /** @param list<string> $queries @return list<string> */
    private function writes(array $queries): array
    {
        return array_values(array_filter(
            $queries,
            fn (string $sql): bool => preg_match('/^\s*(insert|update|delete|replace)\b/i', $sql) === 1
        ));
    }
}
