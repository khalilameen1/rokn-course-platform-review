<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\CoinEarningMethodResource;
use App\Filament\Resources\CoinEarningMethodResource\Pages\CreateCoinEarningMethod;
use App\Filament\Resources\CoinEarningMethodResource\Pages\EditCoinEarningMethod;
use App\Filament\Resources\CoinEarningMethodResource\Pages\ListCoinEarningMethods;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\CoinEarningMethod;
use App\Models\User;
use App\Models\UserCoinEarning;
use App\Models\UserCoinTaskAttempt;
use App\Support\BusinessClock;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentRewardTaskWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::query()->forceCreate(['role' => 'admin', 'active' => true]), 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_task_round_trips_cairo_time_and_never_becomes_repeatable(): void
    {
        $start = BusinessClock::now()->addDay()->format('Y-m-d\TH:i');
        $end = BusinessClock::now()->addDays(2)->format('Y-m-d\TH:i');
        Livewire::test(CreateCoinEarningMethod::class)->fillForm([...$this->fields(), 'starts_at' => $start, 'ends_at' => $end])
            ->call('create')->assertHasNoFormErrors();
        $method = CoinEarningMethod::query()->where('campaign_key', 'native-task-1')->sole();
        self::assertSame(BusinessClock::localInputToUtc($start)->getTimestamp(), $method->starts_at->getTimestamp());
        self::assertFalse($method->is_repeatable);
        Livewire::test(EditCoinEarningMethod::class, ['record' => $method->id])->assertSet('data.starts_at', $start)
            ->fillForm(['title_ar' => 'عنوان جديد'])->call('save')->assertHasNoFormErrors();
        self::assertSame($start, BusinessClock::forDateTimeInput($method->fresh()->starts_at));
        self::assertSame('عنوان جديد', $method->fresh()->title_ar);
    }

    public function test_invalid_windows_and_automatic_action_keys_have_visible_form_errors(): void
    {
        $start = BusinessClock::now()->format('Y-m-d\TH:i');
        foreach (CoinEarningMethod::AUTOMATIC_ACTION_KEYS as $key) {
            Livewire::test(CreateCoinEarningMethod::class)->fillForm([...$this->fields(), 'action_key' => ' '.$key.' '])
                ->call('create')->assertHasFormErrors(['action_key']);
        }
        Livewire::test(CreateCoinEarningMethod::class)->fillForm([...$this->fields(), 'starts_at' => $start, 'ends_at' => $start])
            ->call('create')->assertHasFormErrors(['ends_at']);
        Livewire::test(CreateCoinEarningMethod::class)->fillForm([...$this->fields(), 'starts_at' => null, 'ends_at' => $start])
            ->call('create')->assertHasNoFormErrors();
    }

    public function test_started_task_contract_is_immutable_but_can_be_disabled_and_deleted_with_history_retained(): void
    {
        Livewire::test(CreateCoinEarningMethod::class)->fillForm($this->fields())->call('create')->assertHasNoFormErrors();
        $method = CoinEarningMethod::query()->where('campaign_key', 'native-task-1')->sole();
        $attempt = UserCoinTaskAttempt::query()->create(['public_id' => (string) Str::uuid(), 'user_id' => auth('web')->id(),
            'coin_earning_method_id' => $method->id, 'status' => 'started', 'started_at' => now(), 'claim_available_at' => now()]);
        $earning = UserCoinEarning::query()->create(['user_id' => auth('web')->id(), 'coin_earning_method_id' => $method->id, 'amount' => 10]);
        Livewire::test(EditCoinEarningMethod::class, ['record' => $method->id])->fillForm(['coins_amount' => 20])
            ->call('save')->assertHasFormErrors(['coins_amount']);
        Livewire::test(EditCoinEarningMethod::class, ['record' => $method->id])->fillForm(['is_active' => false])
            ->call('save')->assertHasNoFormErrors();
        self::assertFalse($method->fresh()->is_active);
        Livewire::test(ListCoinEarningMethods::class)->callTableAction('remove', $method->fresh());
        self::assertTrue($method->fresh()->trashed());
        self::assertNotNull($attempt->fresh());
        self::assertSame(10, (int) $earning->fresh()->amount);
    }

    public function test_destination_admission_is_shared_and_broken_task_can_be_stopped(): void
    {
        Livewire::test(CreateCoinEarningMethod::class)->fillForm([...$this->fields(), 'action_url' => 'https://untrusted.example/path'])
            ->call('create')->assertHasFormErrors(['action_url']);
        $method = CoinEarningMethod::query()->create([...$this->fields(), 'action_url' => null]);
        Livewire::test(EditCoinEarningMethod::class, ['record' => $method->id])->fillForm(['is_active' => false])
            ->call('save')->assertHasNoFormErrors();
        self::assertFalse($method->fresh()->is_active);
    }

    public function test_stale_editor_and_confirmation_do_not_replace_new_task_values(): void
    {
        $method = CoinEarningMethod::query()->create($this->fields());
        $editor = Livewire::test(EditCoinEarningMethod::class, ['record' => $method->id]);
        $list = Livewire::test(ListCoinEarningMethods::class)->mountTableAction('remove', $method);
        $method->update(['title_ar' => 'الأحدث']);
        $editor->fillForm(['title_ar' => 'قديم'])->call('save');
        $list->callMountedTableAction();
        self::assertSame('الأحدث', $method->fresh()->title_ar);
        self::assertFalse($method->fresh()->trashed());
    }

    public function test_native_list_excludes_automatic_rows_and_bookmarks_redirect(): void
    {
        $this->withoutMiddleware(RequireAdminMfa::class);
        $method = CoinEarningMethod::query()->create($this->fields());
        $automatic = CoinEarningMethod::query()->firstOrCreate(['action_key' => 'register'], [...$this->fields(), 'campaign_key' => null]);
        Livewire::test(ListCoinEarningMethods::class)->assertCanSeeTableRecords([$method])->assertCanNotSeeTableRecords([$automatic]);
        $this->get(route('admin.coin-earning-methods.create'))->assertRedirect(CoinEarningMethodResource::getUrl('create'));
        $this->get(route('admin.coin-earning-methods.edit', $method))->assertRedirect(CoinEarningMethodResource::getUrl('edit', ['record' => $method->id]));
        auth('web')->user()->forceFill(['role' => 'moderator'])->save();
        Livewire::test(ListCoinEarningMethods::class)->assertForbidden();
        Livewire::test(CreateCoinEarningMethod::class)->assertForbidden();
    }

    private function fields(): array
    {
        return ['title_ar' => 'تابعنا على إنستجرام', 'title_en' => 'Follow our Instagram', 'coins_amount' => 10,
            'action_key' => 'follow_instagram_native', 'campaign_key' => 'native-task-1', 'action_url' => 'https://instagram.com/rokn.app',
            'requires_external_visit' => true, 'verification_delay_seconds' => 3, 'sort_order' => 100, 'is_active' => true];
    }
}
