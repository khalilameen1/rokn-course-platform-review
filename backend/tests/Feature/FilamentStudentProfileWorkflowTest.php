<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\StudentResource;
use App\Filament\Resources\StudentResource\Pages\CreateStudent;
use App\Filament\Resources\StudentResource\Pages\EditStudent;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentStudentProfileWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('public');
        $this->actingAs($this->user('admin'), 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
        $this->withoutMiddleware(RequireAdminMfa::class);
    }

    public function test_native_creation_normalizes_identity_and_replays_the_same_receipt_with_its_photo(): void
    {
        $page = Livewire::test(CreateStudent::class);
        $page->assertFormFieldExists('phone', fn (TextInput $field): bool => $field->getExtraInputAttributes()['dir'] === 'ltr')
            ->assertFormFieldExists('image', fn (FileUpload $field): bool => $field->getVisibleJs() === null)
            ->assertFormFieldDoesNotExist('password')->assertFormFieldDoesNotExist('role');
        $data = ['name' => 'طالب جديد', 'email' => ' NEW@native.test ', 'phone' => '٠١٠٠٠٠٠٠٠٠١',
            'image' => UploadedFile::fake()->image('student.png', 24, 24)];
        $page->fillForm($data)->call('create')->assertHasNoFormErrors();
        $student = User::query()->where('email', 'new@native.test')->firstOrFail();
        self::assertSame('01000000001', $student->phone);
        self::assertSame('client', $student->role);
        self::assertNull($student->email_verified_at);
        self::assertNotNull($student->portfolio_slug);
        self::assertSame(1, $student->allPhotos()->count());
        $page->call('create')->assertHasNoFormErrors();
        self::assertSame(1, User::withTrashed()->where('authoring_request_id', $page->instance()->creationIntent)->count());
        self::assertSame(1, $student->allPhotos()->count());
    }

    public function test_native_profile_update_invalidates_verification_and_refuses_stale_editor(): void
    {
        $student = $this->user();
        $student->forceFill(['email_verified_at' => now()])->save();
        $stale = Livewire::test(EditStudent::class, ['record' => $student->id]);
        Livewire::test(EditStudent::class, ['record' => $student->id])->fillForm([
            'name' => 'اسم معدل', 'email' => 'updated@native.test', 'phone' => '01000000002',
        ])->call('save')->assertHasNoFormErrors();
        self::assertNull($student->fresh()->email_verified_at);
        $stale->fillForm(['name' => 'لا يستبدل الأحدث', 'email' => 'stale@native.test', 'phone' => '01000000003'])->call('save');
        self::assertSame('updated@native.test', $student->fresh()->email);
        self::assertSame('اسم معدل', $student->fresh()->getRawOriginal('name'));
    }

    public function test_duplicate_contact_validation_and_staff_boundary_are_kept(): void
    {
        $student = $this->user();
        Livewire::test(CreateStudent::class)->fillForm(['name' => 'طالب آخر', 'email' => $student->email, 'phone' => $student->phone])
            ->call('create')->assertHasFormErrors(['email' => 'unique', 'phone' => 'unique']);
        Livewire::test(EditStudent::class, ['record' => auth('web')->id()])->assertNotFound();
        self::assertFalse(StudentResource::canDelete($student));
        $this->actingAs($this->user('moderator'), 'web');
        Livewire::test(CreateStudent::class)->assertForbidden();
        Livewire::test(EditStudent::class, ['record' => $student->id])->assertForbidden();
    }

    public function test_saved_editor_urls_redirect_to_the_single_native_editor(): void
    {
        $student = $this->user();
        $this->get(route('admin.users.create'))->assertRedirect(StudentResource::getUrl('create'));
        $this->get(route('admin.users.edit', $student))->assertRedirect(StudentResource::getUrl('edit', ['record' => $student->id]));
    }

    private function user(string $role = 'client'): User
    {
        return User::query()->forceCreate(['name' => 'حساب '.$role, 'email' => Str::uuid().'@student-native.test',
            'phone' => '2010'.random_int(10000000, 99999999), 'role' => $role, 'active' => true]);
    }
}
