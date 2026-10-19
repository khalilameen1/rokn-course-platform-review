<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\RewardRuleResource\Pages\CreateRewardRule;
use App\Filament\Resources\RewardRuleResource\Pages\EditRewardRule;
use App\Filament\Resources\RewardRuleResource\Pages\ListRewardRules;
use App\Models\CoinEarningMethod;
use App\Models\RewardRule;
use App\Models\Setting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentRewardRuleWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::query()->forceCreate(['role' => 'admin', 'active' => true]), 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_welcome_gift_uses_one_rule_and_invalid_edit_preserves_its_form_and_other_rules(): void
    {
        $rule = RewardRule::query()->where('event_key', 'welcome_bonus')->firstOrFail();
        $other = RewardRule::query()->where('event_key', 'daily_checkin')->firstOrFail();
        $before = $other->getAttributes();
        $methods = CoinEarningMethod::query()->count();
        $editor = Livewire::test(EditRewardRule::class, ['record' => $rule->id]);
        $editor->fillForm(['title_ar' => 'هدية الترحيب', 'coins_amount' => -1])->call('save')
            ->assertHasFormErrors(['coins_amount' => 'min'])->assertSet('data.title_ar', 'هدية الترحيب');
        $editor->fillForm(['coins_amount' => 60, 'is_active' => true])->call('save')->assertHasNoFormErrors();
        self::assertSame(60, RewardRule::configuredAmount('welcome_bonus'));
        self::assertSame($before, $other->fresh()->getAttributes());
        self::assertSame($methods, CoinEarningMethod::query()->count());
        $editor->assertFormFieldIsHidden('interval_count')->assertFormFieldIsHidden('daily_cap')->assertFormFieldIsHidden('rolling_30_day_cap');
    }

    public function test_creation_receipt_and_unique_event_keep_one_rule_with_visible_conditional_fields(): void
    {
        RewardRule::query()->where('event_key', 'study_session')->delete();
        $creator = Livewire::test(CreateRewardRule::class)->fillForm($this->fields());
        $creator->assertFormFieldIsVisible('interval_count')->assertFormFieldIsVisible('daily_cap');
        $creator->call('create')->assertHasNoFormErrors();
        self::assertSame(1, RewardRule::query()->where('event_key', 'study_session')->count());
        Livewire::test(CreateRewardRule::class)->fillForm($this->fields())->call('create')->assertHasFormErrors(['event_key']);
        self::assertSame(6, RewardRule::query()->count());
    }

    public function test_caps_remain_cost_invariants_and_zero_cap_can_pause_grants(): void
    {
        $rule = RewardRule::query()->where('event_key', 'study_session')->firstOrFail();
        Livewire::test(EditRewardRule::class, ['record' => $rule->id])->fillForm([...$this->fields(), 'rolling_30_day_cap' => null])
            ->call('save')->assertHasFormErrors(['rolling_30_day_cap']);
        Livewire::test(EditRewardRule::class, ['record' => $rule->id])->fillForm([...$this->fields(), 'daily_cap' => 5])
            ->call('save')->assertHasFormErrors(['daily_cap']);
        Livewire::test(EditRewardRule::class, ['record' => $rule->id])->fillForm([...$this->fields(), 'rolling_30_day_cap' => 0])
            ->call('save')->assertHasNoFormErrors();
        self::assertSame(0, $rule->fresh()->rolling_30_day_cap);
        $welcome = RewardRule::query()->where('event_key', 'welcome_bonus')->firstOrFail();
        Setting::query()->firstOrCreate([])->update(['reward_balance_cap' => 70, 'recommended_provider_bonus_coins' => 20]);
        Livewire::test(EditRewardRule::class, ['record' => $welcome->id])->fillForm(['coins_amount' => 60, 'is_active' => true])
            ->call('save')->assertHasFormErrors(['coins_amount']);
    }

    public function test_stale_editor_and_delete_confirmation_preserve_newer_configuration(): void
    {
        $rule = RewardRule::query()->where('event_key', 'daily_checkin')->firstOrFail();
        $editor = Livewire::test(EditRewardRule::class, ['record' => $rule->id]);
        $list = Livewire::test(ListRewardRules::class)->mountTableAction('remove', $rule);
        $rule->update(['title_ar' => 'القيمة الأحدث']);
        $editor->fillForm(['title_ar' => 'قديم'])->call('save');
        $list->callMountedTableAction();
        self::assertSame('القيمة الأحدث', $rule->fresh()->title_ar);
        Livewire::test(ListRewardRules::class)->callTableAction('remove', $rule->fresh());
        self::assertFalse(RewardRule::query()->whereKey($rule->id)->exists());
    }

    public function test_unknown_events_are_not_editable_and_moderators_have_no_reward_access(): void
    {
        $unknown = RewardRule::query()->create([...$this->fields(), 'event_key' => 'unknown_legacy']);
        Livewire::test(ListRewardRules::class)->assertCanNotSeeTableRecords([$unknown]);
        Livewire::test(EditRewardRule::class, ['record' => $unknown->id])->assertNotFound();
        auth('web')->user()->forceFill(['role' => 'moderator'])->save();
        Livewire::test(ListRewardRules::class)->assertForbidden();
        Livewire::test(CreateRewardRule::class)->assertForbidden();
    }

    private function fields(): array
    {
        return ['event_key' => 'study_session', 'title_ar' => 'مكافأة الدراسة', 'title_en' => 'Study reward',
            'coins_amount' => 10, 'interval_count' => 5, 'daily_cap' => 50, 'rolling_30_day_cap' => 100, 'is_active' => true, 'sort_order' => 100];
    }
}
