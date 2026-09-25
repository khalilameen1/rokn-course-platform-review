<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

final class AdminTransactionBoundaryTest extends TestCase
{
    public function test_bulk_course_code_creation_uses_an_automatic_transaction_boundary(): void
    {
        $source = file_get_contents(
            app_path('Services/AdminCourseCodeAuthoringService.php')
        );

        self::assertIsString($source);
        self::assertStringContainsString(
            'DB::transaction(function () use ($payload, $count, $completeIntent)',
            $source
        );
        self::assertStringContainsString('$completeIntent($first);', $source);
        self::assertStringNotContainsString('Illuminate\\Http\\Request', $source);
        self::assertStringContainsString(
            '$this->authoring->createBatch(',
            file_get_contents(app_path('Http/Controllers/Admin/CourseCodeController.php'))
        );
        self::assertStringNotContainsString('DB::beginTransaction()', $source);
        self::assertStringNotContainsString('DB::commit()', $source);
        self::assertStringNotContainsString('DB::rollBack()', $source);
    }
}
