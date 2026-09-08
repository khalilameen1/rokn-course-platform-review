<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\AdminStudentReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminStudentIdentitySearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_copied_uid_and_hash_search_match_only_the_complete_student_id(): void
    {
        $this->student(123);
        $this->student(1234);
        $this->student(888, ['phone' => '01012399999']);

        foreach (['123', 'UID: 123', '#123', ' uid:123 ', '# 123'] as $search) {
            $expected = $search === '123' ? [888, 123] : [123];
            self::assertSame($expected, $this->search($search), $search);
        }
    }

    public function test_existing_name_email_and_phone_search_is_not_reinterpreted_as_an_id(): void
    {
        $this->student(123);
        $this->student(888, [
            'name' => 'طالب 123', 'email' => 'learner123@example.test', 'phone' => '01012399999',
        ]);
        foreach (['طالب 123', 'learner123@', '01012399999'] as $search) {
            self::assertSame([888], $this->search($search), $search);
        }
        foreach (['ID 123', 'UID: 123 extra', '#123extra', 'UID: 9999999999999999999999999'] as $search) {
            self::assertSame([], $this->search($search), $search);
        }
    }

    public function test_id_search_keeps_student_and_active_filters(): void
    {
        $this->student(123, ['active' => false]);
        $this->student(456, ['role' => 'admin']);
        self::assertSame([], $this->search('UID: 456'));
        self::assertSame([], $this->search('#123', ['active' => '1']));
        self::assertSame([123], $this->search('#123', ['active' => '0']));
    }

    private function search(string $search, array $filters = []): array
    {
        return app(AdminStudentReadService::class)
            ->listing(['search' => $search] + $filters, [])['users']
            ->getCollection()->pluck('id')->all();
    }

    private function student(int $id, array $attributes = []): void
    {
        (new User())->forceFill($attributes + [
            'id' => $id, 'name' => 'Learner', 'email' => strtr((string) $id, '0123456789', 'abcdefghij').'@example.test',
            'password' => 'not-a-login-password', 'role' => 'client', 'active' => true,
        ])->save();
    }
}
