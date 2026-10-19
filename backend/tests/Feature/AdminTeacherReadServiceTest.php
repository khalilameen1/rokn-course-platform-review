<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\AdminTeacherReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AdminTeacherReadServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_listing_loads_portraits_in_a_fixed_number_of_queries_as_the_list_grows(): void
    {
        $this->teacher('first');
        [$firstCount, $firstQueries] = $this->readListing();

        foreach (['second', 'third', 'fourth', 'fifth'] as $name) {
            $this->teacher($name);
        }
        [$largerCount, $largerQueries] = $this->readListing();

        self::assertSame(1, $firstCount);
        self::assertSame(5, $largerCount);
        self::assertGreaterThan(0, $firstQueries);
        self::assertSame($firstQueries, $largerQueries, 'Listing must not add a portrait query for each teacher.');
    }

    private function teacher(string $name): void
    {
        $teacher = new User;
        $teacher->forceFill([
            'name_ar' => 'محاضر '.$name, 'email' => $name.'@teacher.test',
            'role' => 'teacher', 'active' => true,
        ])->save();
        $teacher->allPhotos()->create(['path' => 'users/'.$name.'.jpg', 'type' => 'featured']);
    }

    /** @return array{int,int} */
    private function readListing(): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $teachers = app(AdminTeacherReadService::class)->listing('', true, []);
            foreach ($teachers as $teacher) {
                self::assertNotNull($teacher->profile_image_url);
            }

            return [$teachers->total(), count(DB::getQueryLog())];
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}
