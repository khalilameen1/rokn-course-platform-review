@extends('admin.layouts.app')

@section('page.title', 'مركز تشغيل المنتج')

@section('content')
<div class="admin-page">
    <div class="card admin-hero mb-4">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center admin-gap">
                <div>
                    <h1 class="h3 mb-2">مركز تشغيل ركن</h1>
                    <p class="admin-hero__description mb-0">صورة واحدة لما يراه الطالب وما يحتاج متابعة من الفريق</p>
                </div>
                <span class="badge {{ $launchReady ? 'badge-success' : 'badge-danger' }} p-2">
                    {{ $launchReady ? 'جاهز للإطلاق' : 'الإطلاق الكامل غير جاهز' }}
                </span>
            </div>
        </div>
    </div>

    <div class="card admin-card mb-4 {{ $operationalIncidents->where('severity', 'critical')->isNotEmpty() || $runtimeHeartbeatFailures->isNotEmpty() ? 'border-danger' : ($operationalIncidents->isNotEmpty() ? 'border-warning' : '') }}">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 admin-gap">
                <div>
                    <h2 class="h5 mb-1">التشغيل تحت الضغط</h2>
                    <small class="text-muted">العامل والجدولة والمهام المتأخرة والأعطال التي لا يراها الطالب</small>
                </div>
                <span class="badge {{ $operationalIncidents->isEmpty() && $runtimeHeartbeatFailures->isEmpty() ? 'badge-success' : 'badge-danger' }} p-2">
                    {{ $operationalIncidents->isEmpty() && $runtimeHeartbeatFailures->isEmpty() ? 'لا توجد أعطال مفتوحة' : 'التشغيل يحتاج متابعة' }}
                </span>
            </div>

            <div class="row mb-3">
                <div class="col-md-3 mb-2">
                    <span class="badge {{ data_get($runtime, 'scheduler.healthy') ? 'badge-success' : 'badge-danger' }} ml-2">
                        {{ data_get($runtime, 'scheduler.healthy') ? 'يعمل' : 'متوقف' }}
                    </span>
                    <strong>الجدولة</strong>
                    <small class="d-block text-muted mt-1">آخر نبض {{ data_get($runtime, 'scheduler.last_heartbeat_at') ? \App\Support\BusinessClock::relative(data_get($runtime, 'scheduler.last_heartbeat_at')) : 'لم يصل' }}</small>
                </div>
                @foreach(data_get($runtime, 'queues', []) as $queue => $state)
                    <div class="col-md-3 mb-2">
                        <span class="badge {{ $state['healthy'] ? 'badge-success' : 'badge-danger' }} ml-2">
                            {{ $state['healthy'] ? 'يعمل' : 'متأخر' }}
                        </span>
                        <strong>{{ $queue }}</strong>
                        <small class="d-block text-muted mt-1">
                            في الانتظار {{ $state['size'] === null ? 'غير متاح' : number_format($state['size']) }}
                            · آخر نبض {{ $state['last_heartbeat_at'] ? \App\Support\BusinessClock::relative($state['last_heartbeat_at']) : 'لم يصل' }}
                        </small>
                    </div>
                @endforeach
            </div>

            @if($operationalIncidents->isNotEmpty())
                <div class="table-responsive mb-4"><table class="table table-sm admin-table mb-0">
                    <thead><tr><th>الحالة</th><th>العطل</th><th>الأثر</th><th>بدأ</th><th>آخر رصد</th></tr></thead>
                    <tbody>@foreach($operationalIncidents as $incident)
                        <tr>
                            <td><span class="badge {{ $incident->severity === 'critical' ? 'badge-danger' : 'badge-warning' }}">{{ $incident->severity === 'critical' ? 'حرج' : 'تنبيه' }}</span></td>
                            <td><strong>{{ $incident->summary }}</strong><br><small class="text-muted">{{ $incident->code }}</small></td>
                            <td>{{ number_format($incident->affected_count) }}</td>
                            <td>{{ \App\Support\BusinessClock::relative($incident->first_seen_at) }}</td>
                            <td>{{ \App\Support\BusinessClock::relative($incident->last_seen_at) }}</td>
                        </tr>
                    @endforeach</tbody>
                </table></div>
            @endif

            <div class="row">
                <div class="col-lg-5 mb-3">
                    <h3 class="h6">قائمة فشل العامل</h3>
                    <p class="mb-2">الإجمالي <strong>{{ number_format(data_get($runtime, 'failed_jobs.failed_jobs', 0)) }}</strong></p>
                    @forelse(data_get($runtime, 'failed_jobs.by_queue', []) as $queue)
                        <div class="d-flex justify-content-between border-bottom py-2"><span>{{ $queue['queue'] }}</span><strong>{{ number_format($queue['count']) }}</strong></div>
                    @empty
                        <div class="alert alert-success mb-0">لا توجد مهام وصلت إلى قائمة الفشل</div>
                    @endforelse
                    @if(data_get($runtime, 'failed_jobs.failed_jobs', 0) > 0)
                        <small class="d-block text-muted mt-2">لا يوجد زر إعادة عام لأن بعض المهام غير قابلة للتكرار بأمان. أصلح السبب ثم أعد المهمة المعروفة فقط.</small>
                        @foreach(data_get($runtime, 'failed_jobs.recent', []) as $job)
                            <form method="POST" action="{{ route('admin.product-operations.failed-jobs.acknowledge', $job['id']) }}" class="border rounded p-2 mt-2">
                                @csrf
                                <div class="d-flex justify-content-between"><strong>{{ $job['queue'] }}</strong><small>#{{ $job['id'] }}</small></div>
                                <input class="form-control form-control-sm my-2" name="reason" minlength="8" maxlength="190" placeholder="ما الذي عولج" required>
                                <button class="btn btn-sm btn-outline-secondary" type="submit">إغلاق دون إعادة</button>
                            </form>
                        @endforeach
                    @endif
                </div>
                <div class="col-lg-7 mb-3">
                    <h3 class="h6">webhooks</h3>
                    <div class="mb-2">
                        <span class="badge badge-secondary ml-2">ينتظر {{ number_format(data_get($runtime, 'outbox.pending', 0)) }}</span>
                        <span class="badge badge-warning ml-2">محجوب بالترتيب {{ number_format(data_get($runtime, 'outbox.blocked', 0)) }}</span>
                        <span class="badge badge-dark ml-2">تم تجاوزه {{ number_format(data_get($runtime, 'outbox.skipped', 0)) }}</span>
                        <span class="badge badge-danger">فشل {{ number_format(data_get($runtime, 'outbox.failed', 0)) }}</span>
                    </div>
                    @forelse(data_get($runtime, 'outbox.failed_events', []) as $event)
                        <div class="border rounded p-2 mb-2">
                            <div class="d-flex flex-wrap justify-content-between admin-gap">
                                <div><strong>{{ $event->topic }}</strong><br><small class="text-muted">حدث #{{ $event->id }} · {{ number_format($event->attempts) }} محاولات</small></div>
                                <form method="POST" action="{{ route('admin.product-operations.outbox.retry', $event->id) }}" class="form-inline" onsubmit="return confirm('إعادة هذا الحدث بنفس هويته؟')">
                                    @csrf
                                    <input class="form-control form-control-sm ml-2" name="reason" minlength="8" maxlength="190" placeholder="سبب الإعادة" required>
                                    <button class="btn btn-sm btn-outline-primary" type="submit">إعادة آمنة</button>
                                </form>
                                <form method="POST" action="{{ route('admin.product-operations.outbox.skip', $event->id) }}" class="form-inline mt-2" onsubmit="return confirm('تجاوز هذا الحدث يسمح لما بعده بالوصول قبله؟')">
                                    @csrf
                                    <input class="form-control form-control-sm ml-2" name="reason" minlength="8" maxlength="190" placeholder="سبب التجاوز" required>
                                    <button class="btn btn-sm btn-outline-danger" type="submit">تجاوز موثق</button>
                                </form>
                            </div>
                        </div>
                    @empty
                        <div class="alert alert-success mb-0">لا توجد أحداث webhook فاشلة</div>
                    @endforelse
                </div>
            </div>
            <div class="d-flex flex-wrap admin-gap border-top pt-3">
                <span class="badge badge-warning p-2">Push متعطل {{ number_format(data_get($runtime, 'notifications.failed_pushes', 0)) }}</span>
                <span class="badge badge-warning p-2">شهادات معلقة {{ number_format(data_get($runtime, 'certificates.pending_certificates', 0)) }}</span>
                <span class="badge badge-danger p-2">استعادة شهادة فشلت {{ number_format(data_get($runtime, 'certificates.failed_certificates', 0)) }}</span>
                <span class="badge badge-warning p-2">AI معلق {{ number_format(data_get($runtime, 'ai.stale_messages', 0)) }}</span>
                <span class="badge badge-warning p-2">تنظيف متعطل {{ number_format(data_get($runtime, 'cleanup.failed_files', 0) + data_get($runtime, 'cleanup.stale_files', 0) + data_get($runtime, 'cleanup.failed_bunny', 0)) }}</span>
                <span class="badge badge-warning p-2">دفع يحتاج تدخل {{ number_format(data_get($runtime, 'payment_callbacks.review_required', 0) + data_get($runtime, 'payment_callbacks.stalled_store_events', 0)) }}</span>
                <span class="badge badge-secondary p-2">حدود الاستخدام اليوم {{ number_format(data_get($runtime, 'rate_limits.last_24h', 0)) }} طلب · {{ number_format(data_get($runtime, 'rate_limits.affected_actors', 0)) }} مصدر</span>
            </div>
            @if(data_get($runtime, 'rate_limits.top_routes', []))
                <div class="table-responsive mt-3"><table class="table table-sm admin-table mb-0">
                    <thead><tr><th>المسار الأكثر رفضًا خلال 24 ساعة</th><th>الطلبات</th><th>المصادر</th></tr></thead>
                    <tbody>@foreach(data_get($runtime, 'rate_limits.top_routes', []) as $limitedRoute)
                        <tr>
                            <td>{{ $limitedRoute['route'] }}</td>
                            <td>{{ number_format($limitedRoute['hits']) }}</td>
                            <td>{{ number_format($limitedRoute['actors']) }}</td>
                        </tr>
                    @endforeach</tbody>
                </table></div>
            @endif

            <div class="border-top mt-3 pt-3">
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 admin-gap">
                    <div>
                        <h3 class="h6 mb-1">أعطال التطبيق الفعلية</h3>
                        <small class="text-muted">المستخدم والإصدار والمسار والسبب كما وصل من هاتفه</small>
                    </div>
                    <span class="badge {{ $clientFailuresLastDay > 0 ? 'badge-warning' : 'badge-success' }} p-2">آخر 24 ساعة {{ number_format($clientFailuresLastDay) }}</span>
                </div>
                @if($recentClientFailures->isEmpty())
                    <div class="alert alert-success mb-0">لم يصل عطل من التطبيق</div>
                @else
                    <div class="table-responsive"><table class="table table-sm admin-table mb-0">
                        <thead><tr><th>المستخدم</th><th>الإصدار</th><th>المكان</th><th>السبب</th><th>الوقت</th></tr></thead>
                        <tbody>@foreach($recentClientFailures as $failure)
                            <tr>
                                <td>
                                    @if($failure->user)
                                        <a href="{{ route('admin.users.show', $failure->user_id) }}">{{ $failure->user->name ?: '#'.$failure->user_id }}</a>
                                        <br><small>{{ $failure->user->email }}</small>
                                    @else
                                        زائر
                                    @endif
                                </td>
                                <td>{{ $failure->app_version ?: '—' }}<br><small>build {{ $failure->build_number ?: '—' }}</small></td>
                                <td><code>{{ $failure->endpoint ?: $failure->screen_key ?: '—' }}</code></td>
                                <td><strong>{{ $failure->error_code ?: 'UNKNOWN_ERROR' }}</strong><br><small>{{ $failure->request_id ?: '' }}</small></td>
                                <td>{{ \App\Support\BusinessClock::relative($failure->occurred_at) ?: '—' }}</td>
                            </tr>
                        @endforeach</tbody>
                    </table></div>
                @endif
            </div>
        </div>
    </div>

    <div class="card admin-card mb-4 {{ $financialAnomalies->isNotEmpty() ? 'border-danger' : '' }}">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 admin-gap">
                <div>
                    <h2 class="h5 mb-1">مراجعة تكلفة الخدمات المدفوعة</h2>
                    <small class="text-muted">إذا لم يطابق الدفع حد الفئة تتوقف الخدمات ذات التكلفة لهذا الطالب فقط</small>
                </div>
                <span class="badge {{ $financialAnomalies->isEmpty() ? 'badge-success' : 'badge-danger' }} p-2">
                    {{ $financialAnomalies->isEmpty() ? 'لا توجد فروق' : number_format($counts['financial_anomalies']).' تنبيه مفتوح' }}
                </span>
            </div>
            @if($financialAnomalies->isEmpty())
                <div class="alert alert-success mb-0">كل عمليات شراء الفئات المدفوعة مطابقة للحد الأدنى المحفوظ في عقودها.</div>
            @else
                <div class="alert alert-danger">
                    تم عزل مزايا التكلفة المتغيرة للحسابات التالية تلقائيًا. لا يوجد إيقاف عام للطلاب الدافعين.
                </div>
                <div class="table-responsive"><table class="table table-sm admin-table mb-0">
                    <thead><tr><th>الطالب</th><th>الكورس والفئة</th><th>المفروض مدفوع</th><th>الفعلي</th><th>الطلب</th><th>اكتُشف</th></tr></thead>
                    <tbody>@foreach($financialAnomalies as $anomaly)
                        <tr>
                            <td>
                                <a href="{{ route('admin.users.show', $anomaly->user_id) }}">{{ $anomaly->user?->name ?: '#'.$anomaly->user_id }}</a>
                                <br><small>{{ $anomaly->user?->email }}</small>
                            </td>
                            <td>
                                {{ $anomaly->course?->name_ar ?: $anomaly->course?->name_en }}
                                <br><small>{{ data_get($anomaly->metadata, 'plan_code', '—') }}</small>
                            </td>
                            <td><strong>{{ number_format($anomaly->expected_paid_coins) }}</strong> عملة</td>
                            <td class="text-danger"><strong>{{ number_format($anomaly->actual_paid_coins) }}</strong> عملة</td>
                            <td>{{ $anomaly->order?->order_ref ?: '#'.($anomaly->order_id ?: '—') }}</td>
                            <td>{{ \App\Support\BusinessClock::relative($anomaly->detected_at) }}</td>
                        </tr>
                    @endforeach</tbody>
                </table></div>
            @endif
        </div>
    </div>

    <div class="card admin-card mb-4 {{ $storeNotificationReviews->isNotEmpty() ? 'border-warning' : '' }}">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 admin-gap">
                <div>
                    <h2 class="h5 mb-1">مراجعة إشعارات المتاجر</h2>
                    <small class="text-muted">استردادات أو رسائل لم تُطابق عملية شراء معروفة. تُعالج الاستردادات المطابقة تلقائيًا دون تعطيل بقية الطلاب.</small>
                </div>
                <span class="badge {{ $storeNotificationReviews->isEmpty() ? 'badge-success' : 'badge-warning' }} p-2">
                    {{ $storeNotificationReviews->isEmpty() ? 'لا توجد حالات معلقة' : number_format($counts['store_notification_reviews']).' حالة تحتاج مراجعة' }}
                </span>
            </div>
            @if($storeNotificationReviews->isEmpty())
                <div class="alert alert-success mb-0">كل إشعارات Google Play وApp Store الواردة عولجت أو صُنفت تلقائيًا.</div>
            @else
                <div class="table-responsive"><table class="table table-sm admin-table mb-0">
                    <thead><tr><th>المتجر</th><th>الحدث</th><th>المرجع الآمن</th><th>سبب المراجعة</th><th>وقت الاستلام</th></tr></thead>
                    <tbody>@foreach($storeNotificationReviews as $event)
                        <tr>
                            <td>{{ $event->provider === 'google_play' ? 'Google Play' : 'App Store' }}</td>
                            <td>{{ $event->event_type ?: 'غير محدد' }}</td>
                            <td><code>{{ $event->event_id }}</code></td>
                            <td>{{ $event->error_code ?: 'تحتاج قرارًا تشغيليًا' }}</td>
                            <td>{{ \App\Support\BusinessClock::relative($event->received_at) ?: '—' }}</td>
                        </tr>
                    @endforeach</tbody>
                </table></div>
            @endif
        </div>
    </div>

    <div class="card admin-card mb-4"><div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 admin-gap">
            <div><h2 class="h5 mb-1">جاهزية الوسائط</h2><small class="text-muted">حالة تجهيز الفيديو قبل النشر، لا مجرد وجود رابط.</small></div>
            <div>
                <span class="badge badge-success p-2 ml-2">جاهز {{ number_format($counts['media_ready']) }}</span>
                <span class="badge badge-warning p-2 ml-2">يحتاج متابعة {{ number_format($counts['media_attention']) }}</span>
                <span class="badge badge-primary p-2">جلسات اليوم {{ number_format($counts['playback_sessions_today']) }}</span>
            </div>
        </div>
        @if($mediaAttention->isEmpty())
            <div class="alert alert-success mb-0">كل الفيديوهات المسجلة جاهزة.</div>
        @else
            <div class="table-responsive"><table class="table table-sm admin-table mb-0">
                <thead><tr><th>الكورس</th><th>المقطع</th><th>الحالة</th><th>آخر فحص</th><th></th></tr></thead>
                <tbody>@foreach($mediaAttention as $lesson)
                    <tr>
                        <td>{{ $lesson->course?->name_ar ?: $lesson->course?->name_en }}</td>
                        <td>{{ $lesson->title }}</td>
                        @php
                            $runtimeStatus = $lesson->mediaState?->status ?: 'unknown';
                            $integrityStatus = $lesson->mediaState?->integrity_status;
                            $displayStatus = $lesson->video_source_type !== 'bunny' || blank($lesson->bunny_video_id)
                                ? 'misconfigured'
                                : (in_array($integrityStatus, ['attention', 'quarantined'], true)
                                    ? $integrityStatus
                                    : $runtimeStatus);
                        @endphp
                        <td>@include('admin.partials.status-badge', ['badgeStatus' => $displayStatus, 'badgeTone' => in_array($displayStatus, ['failed', 'quarantined', 'misconfigured'], true) ? 'danger' : 'warning'])</td>
                        <td>{{ \App\Support\BusinessClock::relative($lesson->mediaState?->last_probe_at) ?: '—' }}</td>
                        <td><form method="POST" action="{{ route('admin.media-health.probe', $lesson) }}">@csrf<button class="btn btn-sm btn-outline-primary">فحص الآن</button></form></td>
                    </tr>
                @endforeach</tbody>
            </table></div>
        @endif
    </div></div>

    @include('admin.partials.playback-operations-summary')

    <div class="card admin-card mb-4">
        <div class="card-header">
            <h2 class="h5 mb-1">بوابات تشغيل المنتج</h2>
            <small class="text-muted">إيقاف آمن أو طرح تدريجي دون إصدار نسخة تطبيق جديدة. كل تغيير يتطلب سببًا ويُسجّل باسم المسؤول.</small>
        </div>
        <div class="table-responsive">
            <table class="table admin-table mb-0 text-right">
                <thead class="thead-light"><tr><th>الميزة</th><th>الحالة الحالية</th><th>آخر قرار</th><th class="admin-table__wide-action">تغيير مضبوط</th></tr></thead>
                <tbody>
                @foreach($featureFlags as $key => $feature)
                    @php
                        $labels = [
                            'checkout' => 'شحن العملات والدفع',
                            'playback' => 'تشغيل الفيديو المحمي',
                            'project_uploads' => 'رفع المشاريع',
                            'ai_chat' => 'Rokn AI',
                        ];
                    @endphp
                    <tr>
                        <td>
                            <strong>{{ $labels[$key] ?? $key }}</strong><br>
                            <small class="text-muted">{{ $feature['description'] }}</small>
                        </td>
                        <td>
                            <span class="badge {{ $feature['enabled'] ? 'badge-success' : 'badge-danger' }}">
                                {{ $feature['enabled'] ? 'مفعّلة' : 'متوقفة' }}
                            </span>
                            <div class="small mt-1">{{ $feature['rollout_percentage'] }}٪ من المستخدمين</div>
                            @if($feature['expires_at'])
                                <small class="text-muted">تنتهي: {{ $feature['expires_at'] }}</small>
                            @endif
                        </td>
                        <td>
                            <small class="d-block"><strong>{{ $feature['owner'] ?: 'إعداد النشر الافتراضي' }}</strong></small>
                            <small class="text-muted">{{ $feature['reason'] ?: 'لا يوجد تجاوز تشغيلي محفوظ' }}</small>
                        </td>
                        <td>
                            <form method="POST" action="{{ route('admin.product-operations.features.update', $key) }}" onsubmit="return confirm('تطبيق هذا التغيير على المستخدمين؟')">
                                @csrf
                                <input type="hidden" name="editor_version" value="{{ $feature['editor_version'] }}">
                                <div class="form-row align-items-end">
                                    <div class="col-md-3 mb-2">
                                        <label class="small">الحالة</label>
                                        <select class="form-control form-control-sm" name="enabled" required>
                                            <option value="1" {{ $feature['enabled'] ? 'selected' : '' }}>تشغيل</option>
                                            <option value="0" {{ !$feature['enabled'] ? 'selected' : '' }}>إيقاف</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3 mb-2">
                                        <label class="small">نسبة الطرح</label>
                                        <input class="form-control form-control-sm" name="rollout_percentage" type="number" min="0" max="100" value="{{ $feature['rollout_percentage'] }}" required>
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <label class="small">ينتهي تلقائيًا (اختياري)</label>
                                        <input class="form-control form-control-sm" name="expires_at" type="datetime-local">
                                    </div>
                                    <div class="col-md-9 mb-2">
                                        <label class="small">سبب تشغيلي واضح</label>
                                        <input class="form-control form-control-sm" name="reason" minlength="8" maxlength="255" placeholder="مثال: عطل مزود الدفع INC-204" required>
                                    </div>
                                    <div class="col-md-3 mb-2">
                                        <button class="btn btn-sm btn-primary btn-block" type="submit">تطبيق موثّق</button>
                                    </div>
                                </div>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="row mb-4">
        @foreach([
            ['الكورسات المنشورة', $counts['published'], 'fa-play-circle'],
            ['بطاقات قريبًا', $counts['coming_soon'], 'fa-clock-o'],
            ['الباقات', $counts['packages'], 'fa-cubes'],
            ['مهام ربح فعالة', $counts['reward_tasks'], 'fa-gift'],
            ['منح مؤسسية', $counts['grants'], 'fa-university'],
            ['طلاب فعّلوا منحة', $counts['grant_claims'], 'fa-graduation-cap'],
            ['ترقيات المسار الكامل', $counts['grant_upgrades'], 'fa-certificate'],
            ['مشاريع تنتظر المراجعة', $counts['pending_projects'], 'fa-tasks'],
            ['شهادات صادرة', $counts['certificates'], 'fa-certificate'],
            ['شهادات معلقة', $counts['certificates_pending'], 'fa-clock-o'],
            ['شهادات ملغاة', $counts['certificates_revoked'], 'fa-ban'],
            ['أعمال Portfolio', $counts['portfolio_items'], 'fa-briefcase'],
        ] as [$label, $value, $icon])
            <div class="col-xl-3 col-md-4 col-sm-6 mb-3">
                @include('admin.partials.metric-card', [
                    'metricLabel' => $label,
                    'metricValue' => number_format($value),
                    'metricIcon' => $icon,
                ])
            </div>
        @endforeach
    </div>

    <div class="row mb-4">
        <div class="col-lg-7 mb-3"><div class="card admin-card h-100"><div class="card-body">
            <h2 class="h5 mb-3">جاهزية التشغيل</h2><div class="row">
            @foreach([
                'hero' => 'كورس رئيسي واحد', 'published_course' => 'كورس ظاهر فعليًا في التطبيق',
                'auth_methods' => 'طرق الدخول المعلنة ظاهرة وجاهزة',
                'packages' => 'باقات قابلة للشراء', 'reward_tasks' => 'مهام ربح فعالة',
                'support' => 'دعم واتساب',
                'external_monitoring' => 'Sentry وNightwatch مربوطان',
            ] as $key => $label)
                <div class="col-md-6 mb-2"><span class="badge {{ $readiness[$key] ? 'badge-success' : 'badge-danger' }} ml-2">{{ $readiness[$key] ? 'جاهز' : 'ناقص' }}</span>{{ $label }}</div>
            @endforeach
            </div>
            @php
                $infrastructure = [
                    ['Bunny Stream', data_get($capabilityReport, 'capabilities.bunny.stream')],
                    ['رفع الفيديو إلى Bunny', data_get($capabilityReport, 'capabilities.bunny.upload')],
                    ['تشغيل الفيديو من CDN', data_get($capabilityReport, 'capabilities.bunny.playback')],
                    ['توقيع روابط التشغيل', data_get($capabilityReport, 'capabilities.bunny.signing')],
                    ['صور وملفات Bunny', data_get($capabilityReport, 'capabilities.bunny.assets')],
                    ['الدفع عبر Kashier', data_get($capabilityReport, 'capabilities.payment.kashier')],
                    ['الدفع عبر Google Play', data_get($capabilityReport, 'capabilities.payment.google_play')],
                    ['الدفع عبر App Store', data_get($capabilityReport, 'capabilities.payment.app_store')],
                    ['Rokn AI', data_get($capabilityReport, 'capabilities.ai')],
                    ['البريد التشغيلي', data_get($capabilityReport, 'capabilities.mail')],
                    ['إشعارات Firebase', data_get($capabilityReport, 'capabilities.push')],
                    ['تسجيل Google', data_get($capabilityReport, 'capabilities.social.google')],
                    ['تسجيل Facebook', data_get($capabilityReport, 'capabilities.social.facebook')],
                    ['تسجيل TikTok', data_get($capabilityReport, 'capabilities.social.tiktok')],
                    ['تسجيل Apple', data_get($capabilityReport, 'capabilities.social.apple')],
                    ['روابط عودة تسجيل الدخول', data_get($capabilityReport, 'capabilities.social.callbacks')],
                    ['حفظ جلسة تسجيل الدخول', data_get($capabilityReport, 'capabilities.social.handoff')],
                    ['فتح التطبيق على Android', data_get($capabilityReport, 'capabilities.app_links.android')],
                    ['فتح التطبيق على Apple', data_get($capabilityReport, 'capabilities.app_links.apple')],
                    ['عامل المهام Queue', data_get($capabilityReport, 'capabilities.queue')],
                    ['النسخ المطلوبة للتطبيق', $mobileReleaseCapability],
                    ['النسخ الاحتياطي والاستعادة', data_get($capabilityReport, 'capabilities.recovery')],
                ];
            @endphp
            <hr>
            <h3 class="h6 mb-3">جاهزية البنية — كل قدرة مستقلة</h3>
            @foreach($infrastructure as [$label, $capability])
                <div class="d-flex align-items-start mb-3 admin-gap">
                    @php($optional = data_get($capability, 'required') === false)
                    <span class="badge {{ $optional ? 'badge-secondary' : (data_get($capability, 'ready') ? 'badge-success' : 'badge-danger') }} mt-1">
                        {{ $optional ? 'غير معلن' : (data_get($capability, 'ready') ? 'الإعداد مكتمل' : 'ناقص') }}
                    </span>
                    <div><strong class="d-block">{{ $label }}</strong><small class="text-muted">{{ data_get($capability, 'reason', 'لم يتم الفحص') }}</small></div>
                </div>
            @endforeach
            <hr>
            <h3 class="h6 mb-3">آخر نجاح حقيقي رصدته المنصة</h3>
            <div class="row">
                @foreach($providerEvidence as $evidence)
                    <div class="col-md-6 mb-3">
                        <span class="badge {{ $evidence['last_success_at'] ? 'badge-success' : 'badge-secondary' }} ml-2">
                            {{ $evidence['last_success_at'] ? 'نجح' : 'لم يُرصد' }}
                        </span>
                        <strong>{{ $evidence['label'] }}</strong>
                        <small class="d-block text-muted mt-1">
                            {{ $evidence['last_success_at'] ? \App\Support\BusinessClock::relative($evidence['last_success_at']) : 'لا توجد عملية ناجحة مسجلة بعد' }}
                        </small>
                    </div>
                @endforeach
            </div>
            <small class="text-muted">وجود الإعداد لا يثبت عمل الخدمة لذلك نفصل بين جاهزية الإعداد وآخر عملية نجحت فعليًا</small>
        </div></div></div>
        <div class="col-lg-5 mb-3"><div class="card admin-card h-100"><div class="card-body">
            <h2 class="h5 mb-3">فصل الإيراد عن المكافآت</h2>
            <p class="mb-2">إجمالي التحصيل المؤكد بكل القنوات <strong class="float-left">{{ number_format($finance['cash_revenue'], 2) }} جنيه</strong></p>
            @if($finance['cash_revenue_catalog_estimate'] > 0)<p class="mb-2 text-warning">تقدير الكتالوج خارج الإجمالي <strong class="float-left">{{ number_format($finance['cash_revenue_catalog_estimate'], 2) }} جنيه</strong></p>@endif
            <p class="mb-2">الصافي المؤكد من كشوف التسوية <strong class="float-left">{{ number_format($finance['confirmed_net_revenue'], 2) }} جنيه</strong></p>
            <p class="mb-2">الصافي الحالي (يشمل التقديري) <strong class="float-left">{{ number_format($finance['estimated_net_revenue'], 2) }} جنيه</strong></p>
            <p class="mb-2">عمليات تنتظر كشف التسوية <strong class="float-left">{{ number_format($finance['pending_settlements']) }}</strong></p>
            <p class="mb-2">عملات مشتراة استُهلكت <strong class="float-left">{{ number_format($finance['course_paid_coins']) }}</strong></p>
            <p class="mb-2">عملات مكافآت استُهلكت <strong class="float-left">{{ number_format($finance['course_reward_coins']) }}</strong></p>
            @if($finance['course_ledger_incomplete_orders'] > 0)<p class="mb-2 text-warning">عمليات كورس تحتاج ربط الدفتر <strong class="float-left">{{ number_format($finance['course_ledger_incomplete_orders']) }}</strong></p>@endif
            <p class="mb-2">ترقيات المنح — مدفوعة / مكافآت <strong class="float-left">{{ number_format($finance['grant_upgrade_paid_coins']) }} / {{ number_format($finance['grant_upgrade_reward_coins']) }}</strong></p>
            <p class="mb-0">استرداد أو مراجعة <strong class="float-left">{{ number_format($finance['refunds']) }}</strong></p>
        </div></div></div>
    </div>

    <div class="card admin-card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h2 class="h5 mb-0">الكورسات كما يديرها المنتج</h2><a href="{{ route('admin.courses.index') }}" class="btn btn-sm btn-primary">إدارة الكورسات</a>
        </div>
        <div class="table-responsive"><table class="table admin-table mb-0 text-right">
            <thead class="thead-light"><tr><th>الكورس</th><th>الحالة</th><th>الخريطة</th><th>الطلاب</th><th>التقييم</th><th>مدفوعة / مكافآت</th><th>Rokn AI</th></tr></thead>
            <tbody>@forelse($courses as $course)<tr>
                <td><a href="{{ route('admin.courses.show', $course) }}"><strong>{{ $course->name_ar }}</strong></a>@if($course->is_main_course)<br><small class="text-primary">الكورس الرئيسي</small>@endif</td>
                <td>{{ $course->is_coming_soon ? ($course->is_catalog_visible ? 'قريبًا ظاهر' : 'مسودة مخفية') : 'منشور' }}<br><small>{{ (int)$course->price === 0 ? 'مجاني' : number_format($course->price).' عملة' }}</small></td>
                <td>{{ number_format($course->modules_count) }} وحدة<br><small>{{ number_format($course->sections_count) }} عنصرًا</small></td>
                <td>{{ number_format($course->active_enrollments_count) }}</td>
                <td>{{ $course->ratings_count ? number_format((float)$course->ratings_avg_rating, 1).' / ٥' : 'لا يوجد' }}<br><small>{{ number_format($course->ratings_count) }} تقييم</small></td>
                <td>
                    {{ number_format((int)$course->paid_coins_spent) }} / {{ number_format((int)$course->reward_coins_spent) }}
                    @if((int) $course->coin_ledger_incomplete_orders > 0)<br><small class="text-warning">{{ number_format((int) $course->coin_ledger_incomplete_orders) }} غير مكتملة</small>@endif
                </td>
                <td>{{ (int) $course->ai_plans_count > 0 ? 'حسب الفئة' : 'غير مشمول' }}</td>
            </tr>@empty<tr><td colspan="7" class="text-center text-muted py-5">لا توجد كورسات بعد</td></tr>@endforelse</tbody>
        </table></div>
    </div>

    <div class="card admin-card"><div class="card-body">
        <h2 class="h5 mb-3">اختصارات التشغيل</h2><div class="admin-actions">
            <a class="btn btn-outline-primary" href="{{ route('admin.orders.index') }}">الطلبات والاسترداد</a>
            <a class="btn btn-outline-primary" href="{{ route('admin.packages.index') }}">باقات العملات</a>
            <a class="btn btn-outline-primary" href="{{ route('admin.coin-earning-methods.index') }}">مهام الربح وروابطها</a>
            <a class="btn btn-outline-primary" href="{{ route('admin.course-codes.index') }}">الأكواد والمنح</a>
            <a class="btn btn-outline-primary" href="{{ route('admin.project-submissions.index') }}">المشاريع</a>
            <a class="btn btn-outline-primary" href="{{ route('admin.levels.index') }}">الشارات</a>
            <a class="btn btn-outline-primary" href="{{ route('admin.notifications.index') }}">الإشعارات</a>
            <a class="btn btn-outline-primary" href="{{ route('admin.settings') }}">إعدادات التطبيق وRokn AI</a>
        </div>
    </div></div>

    <div class="card admin-card mt-3"><div class="card-body">
        <h2 class="h5 mb-3">جلسات التشغيل والحساب</h2>
        <div class="admin-actions">
            <a class="btn btn-outline-primary" href="{{ route('admin.playback-operations.index') }}">مراقبة تشغيل الفيديو</a>
            <a class="btn btn-outline-primary" href="{{ route('admin.user-sessions.index') }}">أجهزة وجلسات المستخدمين</a>
        </div>
    </div></div>
</div>
@endsection
