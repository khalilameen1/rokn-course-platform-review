<?php

namespace App\Http\Controllers\Admin;

use App\Filament\Resources\CoinEarningMethodResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CoinEarningMethodProfileRequest;
use App\Http\Requests\Admin\EditorVersionRequest;
use App\Http\Requests\Admin\RewardRuleProfileRequest;
use App\Http\Requests\Admin\RewardSettingsRequest;
use App\Models\CoinEarningMethod;
use App\Models\RewardRule;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminEconomyReadService;
use App\Services\AdminRewardAuthoringService;
use App\Support\RewardConfigurationVersion;

class CoinEarningMethodController extends Controller
{
    public function __construct(private readonly AdminRewardAuthoringService $authoring) {}

    public function index(AdminEconomyReadService $economy)
    {
        $data = $economy->rewards();
        $setting = $data['setting'];
        $socialProviderLabels = $data['socialProviderLabels'];
        $settingsEditorVersion = RewardConfigurationVersion::settings($setting);

        return view('admin.coin_earning_methods.index', compact(
            'setting', 'socialProviderLabels', 'settingsEditorVersion'
        ));
    }

    public function updateSettings(RewardSettingsRequest $request)
    {
        $validated = $request->validated();

        $editorVersion = (string) $validated['editor_version'];
        unset($validated['editor_version']);
        $this->authoring->updateSettings($validated, $editorVersion);

        return redirect()->route('admin.coin-earning-methods.index')
            ->with('success', 'تم تحديث قواعد ومكافآت العملات بنجاح');
    }

    public function create()
    {
        return redirect(CoinEarningMethodResource::getUrl('create'));
    }

    public function store(CoinEarningMethodProfileRequest $request, AdminAuthoringCreateIntentService $createIntents)
    {
        $payload = $request->authoringData();
        $this->authoring->createMethod($payload, function (CoinEarningMethod $method) use ($request, $createIntents): void {
            $createIntents->completeRedirect(
                $request,
                route('admin.coin-earning-methods.index'),
                302,
                CoinEarningMethod::class,
                $method->id
            );
        });

        return redirect()->route('admin.coin-earning-methods.index')
            ->with('success', 'تم إضافة طريقة ربح العملات بنجاح');
    }

    public function edit(CoinEarningMethod $coinEarningMethod)
    {
        return redirect(CoinEarningMethodResource::getUrl('edit', ['record' => $coinEarningMethod->id]));
    }

    public function update(CoinEarningMethodProfileRequest $request, CoinEarningMethod $coinEarningMethod)
    {
        $payload = $request->authoringData();
        $editorVersion = (string) $request->validated('editor_version');
        $this->authoring->updateMethod($coinEarningMethod, $payload, $editorVersion);

        return redirect()->route('admin.coin-earning-methods.index')
            ->with('success', 'تم تحديث طريقة ربح العملات بنجاح');
    }

    public function destroy(EditorVersionRequest $request, CoinEarningMethod $coinEarningMethod)
    {
        $validated = $request->validated();
        $this->authoring->deleteMethod($coinEarningMethod, (string) $validated['editor_version']);

        return redirect()->route('admin.coin-earning-methods.index')
            ->with('success', 'تم حذف طريقة ربح العملات بنجاح');
    }

    public function storeRewardRule(
        RewardRuleProfileRequest $request,
        AdminAuthoringCreateIntentService $createIntents
    ) {
        $payload = $request->authoringData();
        $this->authoring->createRule($payload, function (RewardRule $rule) use ($request, $createIntents): void {
            $createIntents->completeRedirect(
                $request,
                route('admin.coin-earning-methods.index'),
                302,
                RewardRule::class,
                $rule->id
            );
        });

        return redirect()->route('admin.coin-earning-methods.index')
            ->with('success', 'تمت إضافة قاعدة المكافأة وربطها بالحدث.');
    }

    public function updateRewardRule(RewardRuleProfileRequest $request, RewardRule $rewardRule)
    {
        $version = $request->validated();
        $payload = $request->authoringData();
        $this->authoring->updateRule($rewardRule, $payload, (string) $version['editor_version']);

        return redirect()->route('admin.coin-earning-methods.index')
            ->with('success', 'تم تحديث قاعدة المكافأة.');
    }

    public function destroyRewardRule(EditorVersionRequest $request, RewardRule $rewardRule)
    {
        $validated = $request->validated();
        $this->authoring->deleteRule($rewardRule, (string) $validated['editor_version']);

        return redirect()->route('admin.coin-earning-methods.index')
            ->with('success', 'تم حذف القاعدة وإيقاف مكافأتها فورًا.');
    }
}
