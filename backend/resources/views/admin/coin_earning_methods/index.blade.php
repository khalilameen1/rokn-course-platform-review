@extends('admin.layouts.app')

@section('page.title', 'طرق ربح العملات')

@section('styles')
<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/admin-learning-views.css') }}">
@endsection

@section('content')
<div class="fade-in admin-learning admin-learning--coins admin-page">
    @include('admin.partials.page-header', [
        'pageTitle' => 'طرق ربح العملات',
        'pageDescription' => 'إدارة طرق الربح وقواعد المكافآت وحدود استخدامها',
        'pageIcon' => 'fa-money',
        'pageActionUrl' => route('admin.coin-earning-methods.create'),
        'pageActionLabel' => 'إضافة طريقة جديدة',
        'pageActionIcon' => 'fa-plus',
    ])

    {{-- How to Use Coins Settings --}}
    <div class="card shadow-sm border-0 mb-4 coin-panel">
        <div class="card-header bg-white py-3 d-flex align-items-center">
            <i class="fa fa-info-circle coin-accent-icon ml-2"></i>
            <h6 class="mb-0 font-weight-bold">نص "كيفية استخدام العملات"</h6>
        </div>
        <div class="card-body p-4">
            <form action="{{ route('admin.coin-earning-methods.update-settings') }}" method="POST">
                @csrf
                <input type="hidden" name="editor_version" value="{{ $settingsEditorVersion }}">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label font-weight-bold" for="how_to_use_coins_ar">بالعربية</label>
                        <textarea id="how_to_use_coins_ar" name="how_to_use_coins_ar" rows="4" maxlength="12000" dir="rtl"
                            class="form-control @error('how_to_use_coins_ar') is-invalid @enderror"
                            placeholder="اشرح للطالب كيف يمكنه استخدام عملاته...">{{ old('how_to_use_coins_ar', $setting?->how_to_use_coins_ar) }}</textarea>
                        @error('how_to_use_coins_ar')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label font-weight-bold" for="how_to_use_coins_en">بالإنجليزية</label>
                        <textarea id="how_to_use_coins_en" name="how_to_use_coins_en" rows="4" maxlength="12000" dir="ltr"
                            class="form-control @error('how_to_use_coins_en') is-invalid @enderror"
                            placeholder="Explain to the student how they can use their coins...">{{ old('how_to_use_coins_en', $setting?->how_to_use_coins_en) }}</textarea>
                        @error('how_to_use_coins_en')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
                <div class="row">
                    @foreach([
                        'reward_balance_cap' => ['أقصى رصيد مكافآت', 1200, 0],
                        'max_reward_contribution_per_course' => ['أقصى مكافآت في كورس واحد', 1200, 0],
                    ] as $field => [$label, $fallback, $minimum])
                        <div class="col-md-6 col-lg-4 mb-3">
                            <label class="form-label font-weight-bold" for="{{ $field }}">{{ $label }}</label>
                            <input
                                type="number"
                                name="{{ $field }}"
                                id="{{ $field }}"
                                min="{{ $minimum }}"
                                step="1"
                                inputmode="numeric"
                                value="{{ old($field, $setting?->{$field} ?? $fallback) }}"
                                class="form-control @error($field) is-invalid @enderror"
                                required>
                            @error($field)<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    @endforeach
                </div>
                <hr>
                <h6 class="font-weight-bold mb-3">عرض التسجيل الموصى به</h6>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="font-weight-bold" for="recommended_social_provider">المنصة المميزة أعلى خيارات الدخول</label>
                        <select class="form-control" id="recommended_social_provider" name="recommended_social_provider" required>
                            @foreach($socialProviderLabels as $provider => $label)
                                <option value="{{ $provider }}" {{ old('recommended_social_provider', $setting?->recommended_social_provider ?? config('social_auth.recommended_provider')) === $provider ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="font-weight-bold" for="recommended_provider_bonus_coins">عملات إضافية فوق هدية التسجيل</label>
                        <input class="form-control" id="recommended_provider_bonus_coins" min="0" name="recommended_provider_bonus_coins" required type="number" value="{{ old('recommended_provider_bonus_coins', $setting?->recommended_provider_bonus_coins ?? 0) }}">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="font-weight-bold" for="recommended_provider_badge_ar">النص الظاهر فوق المنصة</label>
                        <input class="form-control" id="recommended_provider_badge_ar" maxlength="255" name="recommended_provider_badge_ar" placeholder="الأفضل: بيانات أقل تكتبها ومكافأة أكبر" value="{{ old('recommended_provider_badge_ar', $setting?->recommended_provider_badge_ar) }}">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="font-weight-bold" for="recommended_provider_badge_en">English badge</label>
                        <input class="form-control" dir="ltr" id="recommended_provider_badge_en" maxlength="255" name="recommended_provider_badge_en" value="{{ old('recommended_provider_badge_en', $setting?->recommended_provider_badge_en) }}">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary px-4 coin-form-action">
                    <i class="fa fa-save ml-1"></i> حفظ قواعد استخدام العملات
                </button>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-4 coin-panel">
        <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center">
                <i class="fa fa-bolt coin-accent-icon ml-2"></i>
                <div>
                    <h6 class="mb-1 font-weight-bold">مكافآت الأحداث داخل التطبيق</h6>
                    <p class="mb-0 text-muted small">كل قاعدة قابلة للإضافة والتعديل والتعطيل والحذف. حذف القاعدة يوقف الحدث ولا يمس العملات التي استلمها المستخدمون.</p>
                </div>
            </div>
        </div>
        <div class="card-body p-4">
            @php
                $restoreRewardRuleCreate = old('authoring_request_id') !== null
                    && old('editor_version') === null
                    && old('event_key') !== null;
                $failedRewardRuleVersion = (string) old('editor_version', '');
                $availableRewardEvents = collect($rewardEvents)->except($rewardRules->pluck('event_key')->all());
            @endphp
            @if($availableRewardEvents->isNotEmpty())
            <form id="reward-rule-create" action="{{ route('admin.reward-rules.store') }}" method="POST" class="border rounded p-3 mb-4">
                @csrf
                <input type="hidden" name="authoring_request_id" value="{{ old('authoring_request_id', (string) \Illuminate\Support\Str::uuid()) }}">
                <h6 class="font-weight-bold mb-3">إضافة قاعدة</h6>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="font-weight-bold" for="create-reward-event">الحدث</label>
                        <select id="create-reward-event" name="event_key" class="form-control" required>
                            <option value="">اختر الحدث</option>
                            @foreach($availableRewardEvents as $eventKey => $eventLabel)
                                <option value="{{ $eventKey }}" {{ $restoreRewardRuleCreate && (string) old('event_key') === (string) $eventKey ? 'selected' : '' }}>{{ $eventLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4 mb-3"><label class="font-weight-bold" for="create-reward-title-ar">اسم القاعدة بالعربية</label><input id="create-reward-title-ar" name="title_ar" maxlength="255" value="{{ $restoreRewardRuleCreate ? old('title_ar') : '' }}" class="form-control" required></div>
                    <div class="col-md-4 mb-3"><label class="font-weight-bold" for="create-reward-title-en">اسم القاعدة بالإنجليزية</label><input id="create-reward-title-en" name="title_en" dir="ltr" maxlength="255" value="{{ $restoreRewardRuleCreate ? old('title_en') : '' }}" class="form-control"></div>
                    <div class="col-md-4 mb-3"><label class="font-weight-bold" for="create-reward-coins">عدد العملات</label><input id="create-reward-coins" type="number" min="0" name="coins_amount" value="{{ $restoreRewardRuleCreate ? old('coins_amount') : '' }}" class="form-control" required></div>
                    <div class="col-md-4 mb-3" data-reward-field="interval"><label class="font-weight-bold" for="create-reward-interval">مدة الاستمرارية بالأيام أو الدراسة بالدقائق</label><input id="create-reward-interval" type="number" min="1" name="interval_count" value="{{ $restoreRewardRuleCreate ? old('interval_count', 1) : 1 }}" class="form-control" required></div>
                    <div class="col-md-4 mb-3" data-reward-field="daily"><label class="font-weight-bold" for="create-reward-daily">حد مكافآت الدراسة يوميًا</label><input id="create-reward-daily" type="number" min="0" name="daily_cap" value="{{ $restoreRewardRuleCreate ? old('daily_cap') : '' }}" class="form-control"></div>
                    <div class="col-md-4 mb-3" data-reward-field="rolling"><label class="font-weight-bold" for="create-reward-rolling">الحد خلال 30 يومًا</label><input id="create-reward-rolling" type="number" min="0" name="rolling_30_day_cap" value="{{ $restoreRewardRuleCreate ? old('rolling_30_day_cap') : '' }}" class="form-control"></div>
                </div>
                <input type="hidden" name="is_active" value="1">
                <button class="btn btn-primary px-4"><i class="fa fa-plus ml-1"></i> إضافة القاعدة</button>
            </form>
            @else
                <p class="text-muted mb-4">كل الأحداث لها قواعد بالفعل ويمكنك تعديلها أو تعطيلها أدناه</p>
            @endif

            <div class="row">
                @forelse($rewardRules as $rule)
                    @php
                        $ruleEditorVersion = (string) $rewardRuleEditorVersions->get($rule->id);
                        $restoreThisRewardRule = $failedRewardRuleVersion !== ''
                            && hash_equals($ruleEditorVersion, $failedRewardRuleVersion);
                    @endphp
                    <div class="col-12 mb-3">
                        <div class="reward-rule-card">
                        <form id="reward-rule-{{ $rule->id }}" action="{{ route('admin.reward-rules.update', $rule) }}" method="POST" class="reward-rule-form">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="editor_version" value="{{ $ruleEditorVersion }}">
                            <input type="hidden" name="event_key" value="{{ $rule->event_key }}">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <strong>{{ $rewardEvents[$rule->event_key] ?? $rule->event_key }}</strong>
                                <label class="mb-0" for="reward-{{ $rule->id }}-active"><input type="hidden" name="is_active" value="0"><input id="reward-{{ $rule->id }}-active" type="checkbox" name="is_active" value="1" {{ ($restoreThisRewardRule ? old('is_active', '0') : $rule->is_active) ? 'checked' : '' }}> نشطة</label>
                            </div>
                            <div class="reward-rule-fields">
                                <div class="reward-rule-field"><label for="reward-{{ $rule->id }}-title-ar">اسم القاعدة بالعربية</label><input id="reward-{{ $rule->id }}-title-ar" name="title_ar" maxlength="255" value="{{ $restoreThisRewardRule ? old('title_ar', $rule->title_ar) : $rule->title_ar }}" class="form-control" required></div>
                                <div class="reward-rule-field"><label for="reward-{{ $rule->id }}-title-en">اسم القاعدة بالإنجليزية</label><input id="reward-{{ $rule->id }}-title-en" name="title_en" dir="ltr" maxlength="255" value="{{ $restoreThisRewardRule ? old('title_en', $rule->title_en) : $rule->title_en }}" class="form-control"></div>
                                <div class="reward-rule-field"><label for="reward-{{ $rule->id }}-coins">العملات</label><input id="reward-{{ $rule->id }}-coins" type="number" min="0" name="coins_amount" value="{{ $restoreThisRewardRule ? old('coins_amount', $rule->coins_amount) : $rule->coins_amount }}" class="form-control" required></div>
                                @if(in_array($rule->event_key, ['streak_milestone', 'study_session'], true))
                                    <div class="reward-rule-field"><label for="reward-{{ $rule->id }}-interval">{{ $rule->event_key === 'streak_milestone' ? 'مدة الاستمرارية بالأيام' : 'مدة الدراسة بالدقائق' }}</label><input id="reward-{{ $rule->id }}-interval" type="number" min="{{ $rule->event_key === 'streak_milestone' ? 2 : 1 }}" name="interval_count" value="{{ $restoreThisRewardRule ? old('interval_count', $rule->interval_count) : $rule->interval_count }}" class="form-control" required></div>
                                @endif
                                @if($rule->event_key === 'study_session')
                                    <div class="reward-rule-field"><label for="reward-{{ $rule->id }}-daily">حد مكافآت الدراسة يوميًا</label><input id="reward-{{ $rule->id }}-daily" type="number" min="0" name="daily_cap" value="{{ $restoreThisRewardRule ? old('daily_cap', $rule->daily_cap) : $rule->daily_cap }}" class="form-control"></div>
                                @endif
                                @if($rule->event_key !== 'welcome_bonus')
                                    <div class="reward-rule-field"><label for="reward-{{ $rule->id }}-rolling">{{ $rule->event_key === 'first_project_passed' ? 'سقف مكافأة أول مشروع' : 'حد 30 يومًا' }}</label><input id="reward-{{ $rule->id }}-rolling" type="number" min="0" name="rolling_30_day_cap" value="{{ $restoreThisRewardRule ? old('rolling_30_day_cap', $rule->rolling_30_day_cap) : $rule->rolling_30_day_cap }}" class="form-control"></div>
                                @endif
                            </div>
                            <input type="hidden" name="sort_order" value="{{ $rule->sort_order }}">
                        </form>
                        <div class="reward-rule-actions">
                            <button type="submit" form="reward-rule-{{ $rule->id }}" class="btn btn-sm btn-outline-primary"><i class="fa fa-save ml-1"></i> حفظ</button>
                            <form action="{{ route('admin.reward-rules.destroy', $rule) }}" method="POST" onsubmit="return confirm('حذف القاعدة سيوقف هذه المكافأة فورًا. هل أنت متأكد؟')">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="editor_version" value="{{ $rewardRuleEditorVersions->get($rule->id) }}">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fa fa-trash ml-1"></i> حذف القاعدة</button>
                            </form>
                        </div>
                        </div>
                    </div>
                @empty
                    <div class="col-12 text-center text-muted py-4">لا توجد مكافآت أحداث نشطة أو محفوظة.</div>
                @endforelse
            </div>
        </div>
    </div>

    <h2 class="h5 mb-3">مهام ربح العملات</h2>
    <div class="row">
        @forelse($methods as $method)
            <div class="col-md-6 col-lg-4">
                <div class="method-card">
                    <div class="method-card__head mb-3">
                        <h5 class="method-card__title mb-0 font-weight-bold">{{ $method->learnerTitleAr() }}</h5>
                        <div class="method-card__badges">
                            @include('admin.partials.status-badge', [
                                'badgeStatus' => $method->is_active ? 'active' : 'unknown',
                                'badgeLabel' => $method->is_active ? 'نشط' : 'غير نشط',
                                'badgeTone' => $method->is_active ? 'success' : 'danger',
                            ])
                            @include('admin.partials.status-badge', [
                                'badgeStatus' => 'unknown',
                                'badgeLabel' => 'مرة واحدة',
                                'badgeTone' => 'muted',
                            ])
                        </div>
                    </div>
                    <p class="text-muted mb-2">{{ $method->title_en }}</p>
                    <div class="d-flex justify-content-between align-items-center mt-4">
                        <div class="h4 mb-0 method-card__amount">
                            {{ $method->coins_amount }} <span class="small">عملة</span>
                        </div>
                        <div class="btn-group">
                            <a href="{{ route('admin.coin-earning-methods.edit', $method->id) }}" class="btn btn-sm btn-outline-primary">
                                <i class="fa fa-edit"></i>
                            </a>
                            <form action="{{ route('admin.coin-earning-methods.destroy', $method->id) }}" method="POST" onsubmit="return confirm('هل أنت متأكد من الحذف؟')">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="editor_version" value="{{ $methodEditorVersions->get($method->id) }}">
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="fa fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                    @if($method->action_key)
                        <div class="mt-2 small text-muted">
                            <code class="bg-light px-2 py-1 rounded">{{ $method->action_key }}</code>
                        </div>
                    @endif
                    <div class="mt-2 small text-muted">
                        المطالبات: {{ number_format($method->user_earnings_count) }}
                        من {{ $method->total_claim_limit ? number_format($method->total_claim_limit) : 'بلا سقف' }}
                        @if($method->campaign_key)
                            · الحملة <code>{{ $method->campaign_key }}</code>
                        @endif
                    </div>
                    @if($method->starts_at || $method->ends_at)
                        <div class="mt-1 small text-muted">
                            {{ $method->starts_at ? \App\Support\BusinessClock::format($method->starts_at) : 'الآن' }}
                            —
                            {{ $method->ends_at ? \App\Support\BusinessClock::format($method->ends_at) : 'مفتوحة' }}
                            · توقيت القاهرة
                        </div>
                    @endif
                    <div class="mt-2 small text-muted">
                        @if($method->requires_external_visit)
                            <i class="fa fa-external-link ml-1"></i>
                            خطوتان · عودة بعد {{ $method->verification_delay_seconds }} ثوانٍ
                            @if($method->resolvedActionUrl())
                                <a href="{{ $method->resolvedActionUrl() }}" target="_blank" rel="noopener noreferrer" class="mr-2">فحص الرابط</a>
                            @endif
                        @else
                            <i class="fa fa-check-circle ml-1"></i> مطالبة مباشرة داخل التطبيق
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12 text-center py-5">
                <div class="text-muted">
                    <i class="fa fa-info-circle fa-3x mb-3"></i>
                    <h4>لا توجد طرق ربح مضافة حالياً</h4>
                </div>
            </div>
        @endforelse
    </div>

    <div class="d-flex justify-content-center mt-4">
        {{ $methods->links() }}
    </div>
</div>
@endsection

@section('scripts')
<script src="{{ versioned_asset('admin/assets/js/reward-rule-form.js') }}" defer></script>
@endsection
