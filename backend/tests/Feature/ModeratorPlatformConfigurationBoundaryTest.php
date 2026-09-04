<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\AdminPermissionMatrix;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ModeratorPlatformConfigurationBoundaryTest extends TestCase
{
    public function test_global_progression_ladder_is_administrator_only(): void
    {
        $matrix = app(AdminPermissionMatrix::class);
        $index = Route::getRoutes()->getByName('admin.levels.index');

        self::assertNotNull($index);
        self::assertSame(['GET', 'HEAD'], $index->methods());
        self::assertContains('admin.only', $index->gatherMiddleware());
        self::assertFalse($matrix->allows('moderator', 'admin.levels.index', 'GET'));

        $mutations = [
            'admin.levels.create' => 'GET',
            'admin.levels.store' => 'POST',
            'admin.levels.edit' => 'GET',
            'admin.levels.update' => 'PATCH',
            'admin.levels.destroy' => 'DELETE',
        ];

        foreach ($mutations as $name => $method) {
            $route = Route::getRoutes()->getByName($name);

            self::assertNotNull($route, $name);
            self::assertContains('admin.only', $route->gatherMiddleware(), $name);
            self::assertContains('admin.mfa', $route->gatherMiddleware(), $name);
            self::assertFalse($matrix->allows('moderator', $name, $method), $name);
        }

        self::assertNull(Route::getRoutes()->getByName('admin.levels.show'));
    }

    public function test_global_course_taxonomy_pages_are_administrator_only(): void
    {
        $matrix = app(AdminPermissionMatrix::class);

        foreach (['classifications', 'paths'] as $resource) {
            $index = Route::getRoutes()->getByName("admin.{$resource}.index");
            self::assertNotNull($index);
            self::assertContains('admin.only', $index->gatherMiddleware());
            self::assertFalse($matrix->allows('moderator', "admin.{$resource}.index", 'GET'));

            foreach ([
                'create' => 'GET',
                'store' => 'POST',
                'edit' => 'GET',
                'update' => 'PATCH',
                'destroy' => 'DELETE',
            ] as $action => $method) {
                $name = "admin.{$resource}.{$action}";
                $route = Route::getRoutes()->getByName($name);
                self::assertNotNull($route, $name);
                self::assertContains('admin.only', $route->gatherMiddleware(), $name);
                self::assertFalse($matrix->allows('moderator', $name, $method), $name);
            }
        }
    }
}
