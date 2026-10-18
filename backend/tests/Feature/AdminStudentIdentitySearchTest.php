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

        // A bare number is an exact id or a phone *prefix* (index-friendly);
        // it no longer scans for the digits anywhere inside a phone number.
        foreach (['123', 'UID: 123', '#123', ' uid:123 ', '# 123'] as $search) {
            self::assertSame([123], $this->search($search), $search);
        }
    }

    public function test_phone_and_email_use_prefix_matches_and_local_numbers_find_stored_international_form(): void
    {
        $this->student(501, ['phone' => '201012345678', 'email' => 'mona.ali@example.test']);
        $this->student(502, ['phone' => '201099999999', 'email' => 'ali@example.test']);

        self::assertSame([501], $this->search('01012345678'));
        self::assertSame([501], $this->search('+20 101 234'));
        self::assertSame([501], $this->search('MONA.ALI@example.test'));
        self::assertSame([502], $this->search('ali@'));
        self::assertSame([], $this->search('2345678'));
    }

    public function test_wildcards_in_input_are_literal(): void
    {
        $this->student(601, ['name' => 'Learner 100% ready']);
        $this->student(602, ['name' => 'Learner_x']);
        $this->student(603, ['name' => 'Learner plain']);

        self::assertSame([601], $this->search('100%'));
        self::assertSame([602], $this->search('r_x'));
        // Only the rows that really contain the character, not every row.
        self::assertSame([601], $this->search('%'));
        self::assertSame([602], $this->search('_'));
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
        (new User)->forceFill($attributes + [
            'id' => $id, 'name' => 'Learner', 'email' => strtr((string) $id, '0123456789', 'abcdefghij').'@example.test',
            'password' => 'not-a-login-password', 'role' => 'client', 'active' => true,
        ])->save();
    }
}
