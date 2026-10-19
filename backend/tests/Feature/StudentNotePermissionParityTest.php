<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\StudentResource\Pages\ViewStudent;
use App\Filament\Resources\StudentResource\RelationManagers\NotesRelationManager;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\StaffMember;
use App\Models\User;
use App\Models\UserNote;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class StudentNotePermissionParityTest extends TestCase
{
    use RefreshDatabase;

    public static function interfaces(): array
    {
        return [['classic'], ['native']];
    }

    #[DataProvider('interfaces')]
    public function test_student_manager_can_delete_another_staff_members_note_in_both_interfaces(string $interface): void
    {
        [$student, $note] = $this->fixture(true);
        if ($interface === 'classic') {
            $this->delete(route('admin.users.notes.delete', $note))->assertRedirect()->assertSessionHas('success');
        } else {
            Livewire::test(NotesRelationManager::class, ['ownerRecord' => $student, 'pageClass' => ViewStudent::class])
                ->callAction(TestAction::make('removeNote')->table($note))->assertHasNoActionErrors();
        }
        self::assertFalse(UserNote::query()->whereKey($note->id)->exists());
    }

    #[DataProvider('interfaces')]
    public function test_read_only_operator_cannot_delete_even_their_own_note(string $interface): void
    {
        [$student, $note] = $this->fixture(false);
        $note->update(['created_by' => auth('web')->id()]);
        if ($interface === 'classic') {
            $this->delete(route('admin.users.notes.delete', $note))->assertForbidden();
        } else {
            Livewire::test(NotesRelationManager::class, ['ownerRecord' => $student, 'pageClass' => ViewStudent::class])
                ->assertActionHidden(TestAction::make('removeNote')->table($note));
        }
        self::assertTrue(UserNote::query()->whereKey($note->id)->exists());
    }

    private function fixture(bool $manage): array
    {
        $operator = User::query()->forceCreate(['role' => 'moderator', 'active' => true]);
        $member = StaffMember::query()->create(['user_id' => $operator->id]);
        foreach ($manage ? ['students.read', 'students.manage'] : ['students.read'] as $permission) {
            $member->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($operator, 'web')->withoutMiddleware(RequireAdminMfa::class);
        Filament::setCurrentPanel(Filament::getPanel('operations'));
        $student = User::query()->forceCreate(['role' => 'client', 'active' => true]);
        $author = User::query()->forceCreate(['role' => 'admin', 'active' => true]);
        $note = UserNote::query()->create(['user_id' => $student->id, 'created_by' => $author->id, 'note' => 'متابعة تعليمية']);

        return [$student, $note];
    }
}
