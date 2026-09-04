<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdminStudentWorkspaceContractTest extends TestCase
{
    public function test_http_controller_delegates_student_reads_to_one_read_service(): void
    {
        $controller = $this->source('app/Http/Controllers/Admin/UsersController.php');

        self::assertStringContainsString('AdminStudentReadService $students', $controller);
        self::assertStringContainsString('$students->listing(', $controller);
        self::assertStringContainsString('$students->workspace(', $controller);
        self::assertStringNotContainsString('Order::where(', $controller);
        self::assertStringNotContainsString('Bill::where(', $controller);
        self::assertStringNotContainsString('ProjectSubmission::query()', $controller);
    }

    public function test_student_workspace_reads_are_scoped_and_do_not_build_profit_reports(): void
    {
        $service = $this->source('app/Services/AdminStudentReadService.php');

        self::assertStringContainsString("->with(['photo', 'latestNote'])", $service);
        self::assertGreaterThanOrEqual(2, substr_count($service, "where('user_id', \$user->id)"));
        self::assertStringContainsString("'projects_page'", $service);
        self::assertStringContainsString("'orders_page'", $service);
        self::assertStringContainsString("'notes_page'", $service);
        self::assertStringNotContainsString('pendingCheckoutSummary(', $service);
        self::assertStringNotContainsString('->summary(null, null', $service);
        self::assertStringNotContainsString('Bill::', $service);
    }

    public function test_student_view_has_one_workspace_and_no_parallel_bill_report(): void
    {
        $show = $this->source('resources/views/admin/users/show.blade.php');
        $purchases = $this->source('resources/views/admin/users/partials/show/purchases.blade.php');
        $learning = $this->source('resources/views/admin/users/partials/show/learning-and-projects.blade.php');

        self::assertStringContainsString("partials.show.learning-and-projects", $show);
        self::assertStringContainsString("partials.show.purchases", $show);
        self::assertStringContainsString('projects_page', $this->source('app/Services/AdminStudentReadService.php'));
        self::assertStringContainsString('admin.project-submissions.show', $learning);
        self::assertStringContainsString('admin.student-progress.show', $learning);
        self::assertStringContainsString('admin.orders.show', $purchases);
        self::assertStringNotContainsString('admin.bills.show', $show.$purchases.$learning);
        self::assertStringNotContainsString('confirmed_egp', $show.$purchases.$learning);
    }

    public function test_student_list_uses_bounded_note_and_photo_reads(): void
    {
        $user = $this->source('app/Models/User.php');
        $index = $this->source('resources/views/admin/users/index.blade.php');
        $runtime = $this->source('public/admin/assets/js/users-index.js');

        self::assertStringContainsString('latestOfMany()', $user);
        self::assertStringNotContainsString('addNoteModal-{{', $index);
        self::assertStringContainsString('data-note-action', $index);
        self::assertStringContainsString("getElementById('addNoteModal')", $runtime);
    }

    private function source(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/'.$relativePath);
        self::assertNotFalse($source, $relativePath);

        return $source;
    }
}
