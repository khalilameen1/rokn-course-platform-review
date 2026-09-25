<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\Course;
use App\Models\CourseCode;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminCourseCodeFormsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('testing', app()->environment());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->assertExitCode(0);
        \Tests\Support\ProductionCourseCodeSchema::applySqliteBridge();
        Http::preventStrayRequests();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs(User::query()->forceCreate([
            'name_ar' => 'مدير الاختبار', 'email' => Str::uuid().'@rokn.test',
            'role' => 'admin', 'active' => true,
        ]), 'web');
    }

    public function test_forms_keep_old_course_input_and_do_not_load_or_offer_unsupported_lessons(): void
    {
        $original = $this->course();
        $selected = $this->course();
        $code = CourseCode::query()->create([
            'code' => 'FORM-TEST', 'type' => 'course', 'course_id' => $original->id,
            'max_uses' => 10, 'is_active' => true,
        ]);
        $lessonQueries = [];
        DB::listen(static function (QueryExecuted $query) use (&$lessonQueries): void {
            if (preg_match('/\bfrom\s+["`]?lessons["`]?\b/i', $query->sql)) {
                $lessonQueries[] = $query->sql;
            }
        });
        foreach ([route('admin.course-codes.create'), route('admin.course-codes.edit', $code)] as $url) {
            $response = $this->withSession(['_old_input' => [
                'type' => 'course', 'course_id' => (string) $selected->id,
            ]])->get($url)->assertOk()
                ->assertDontSee('id="lesson_id"', false)
                ->assertDontSee('name="lesson_ids[]"', false)
                ->assertDontSee('loadLessons()', false)
                ->assertDontSee(".trigger('change')", false);
            $xpath = $this->document($response->getContent());
            self::assertSame(1, $xpath->query('//select[@id="course_id" and @required]')->length);
            self::assertSame((string) $selected->id,
                $xpath->evaluate('string(//select[@id="course_id"]/option[@selected]/@value)'));
        }
        $this->get(route('admin.course-codes.index'))->assertOk();
        self::assertSame([], $lessonQueries, 'Course-code pages must not fetch the entire lesson catalogue.');
        Http::assertNothingSent();
    }

    public function test_edit_without_validation_errors_keeps_the_existing_course_selected(): void
    {
        $course = $this->course();
        $code = CourseCode::query()->create([
            'code' => 'EXISTING-FORM', 'type' => 'course', 'course_id' => $course->id,
            'max_uses' => 10, 'is_active' => true,
        ]);
        $response = $this->get(route('admin.course-codes.edit', $code))->assertOk();
        $xpath = $this->document($response->getContent());
        self::assertSame((string) $course->id,
            $xpath->evaluate('string(//select[@id="course_id"]/option[@selected]/@value)'));
        self::assertSame('course', $xpath->evaluate('string(//select[@id="type"]/option[@selected]/@value)'));
    }

    private function course(): Course
    {
        return Course::query()->forceCreate([
            'tenant_id' => 1, 'name_ar' => 'كورس المنحة', 'price' => 100,
            'is_coming_soon' => false, 'is_catalog_visible' => true,
        ]);
    }

    private function document(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return new DOMXPath($document);
    }
}
