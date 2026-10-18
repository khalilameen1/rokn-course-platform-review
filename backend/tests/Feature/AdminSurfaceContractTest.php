<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class AdminSurfaceContractTest extends TestCase
{
    public function test_every_dashboard_route_targets_a_real_controller_method(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = $route->getName();
            if (! is_string($name) || ! str_starts_with($name, 'admin.')) {
                continue;
            }

            $action = $route->getActionName();
            if ($action === 'Closure' || ! str_contains($action, '@')) {
                continue;
            }
            [$controller, $method] = explode('@', $action, 2);
            self::assertTrue(class_exists($controller), "{$name} targets missing {$controller}");
            self::assertTrue(method_exists($controller, $method), "{$name} targets missing {$controller}@{$method}");
        }
    }

    public function test_every_static_admin_view_reference_has_a_template(): void
    {
        $pattern = '/view\(\s*[\'\"]([^\'\"]+)[\'\"]/';
        foreach (File::allFiles(app_path('Http/Controllers/Admin')) as $file) {
            $source = File::get($file->getPathname());
            preg_match_all($pattern, $source, $matches);
            foreach (array_unique($matches[1] ?? []) as $viewName) {
                self::assertTrue(
                    view()->exists($viewName),
                    "{$file->getFilename()} references missing view {$viewName}"
                );
            }
        }
    }

    public function test_every_static_dashboard_link_names_a_real_route(): void
    {
        $pattern = '/route\(\s*[\'\"](admin\.[^\'\"]+)[\'\"]/';
        foreach (File::allFiles(resource_path('views/admin')) as $file) {
            $source = File::get($file->getPathname());
            preg_match_all($pattern, $source, $matches);
            foreach (array_unique($matches[1] ?? []) as $routeName) {
                self::assertTrue(
                    Route::has($routeName),
                    "{$file->getFilename()} references missing route {$routeName}"
                );
            }
        }
    }
}
