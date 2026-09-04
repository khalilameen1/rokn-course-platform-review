    <!-- Courses Container -->
    <div class="courses-container">
        <!-- Header -->
        <div class="courses-header">
            <h2 class="courses-title">
                <div class="title-icon">
                    <i class="fa fa-book"></i>
                </div>
                قائمة الكورسات
            </h2>
        </div>

        <!-- Filters Section -->
        <form class="filters-section" method="GET" action="{{ route('admin.courses.index') }}">
            <div class="filters-grid">
                <div class="filter-group">
                    <label class="filter-label">البحث في الكورسات</label>
                    <input type="search" name="search" value="{{ $filters['search'] ?? '' }}" id="courseSearch" class="filter-input" placeholder="ابحث باسم الكورس أو الوصف...">
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">التصنيفات </label>
                    <select name="classification_id" id="classificationFilter" class="filter-select" onchange="this.form.submit()">
                        <option value="">جميع التصنيفات</option>
                        @foreach($classificationOptions as $classification)
                            <option value="{{ $classification->id }}" @selected((string) ($filters['classification_id'] ?? '') === (string) $classification->id)>{{ $classification->name_ar }}</option>
                        @endforeach
                    </select>
                </div>
                @if($canViewFinance)
                    <div class="filter-group">
                        <label class="filter-label">الحالة</label>
                        <select name="state" class="filter-select" onchange="this.form.submit()">
                            <option value="active" @selected(($filters['state'] ?? 'active') === 'active')>الكورسات الحالية</option>
                            <option value="archived" @selected(($filters['state'] ?? '') === 'archived')>الأرشيف</option>
                            <option value="all" @selected(($filters['state'] ?? '') === 'all')>الكل</option>
                        </select>
                    </div>
                @endif
                <div class="filter-group">
                    <button type="submit" class="btn-modern btn-primary-modern"><i class="fa fa-search"></i> بحث</button>
                    @if(!empty($filters['search']) || !empty($filters['classification_id']) || (($filters['state'] ?? 'active') !== 'active'))
                        <a href="{{ route('admin.courses.index') }}" class="btn-modern btn-secondary-modern"><i class="fa fa-refresh"></i> إعادة تعيين</a>
                    @endif
                </div>
            </div>
        </form>

        <!-- Courses Grid -->
        @if($courses->count() > 0)
            <div class="courses-grid" id="coursesGrid">
                @foreach($courses as $course)
                    @php
                        $courseWorkspaceId = (int) ($courseAuthoringEntryIds->get($course->id) ?? $course->id);
                        $courseHasActiveDraft = (bool) ($courseHasActiveDrafts->get($course->id) ?? false);
                    @endphp
                    <div class="course-card" data-url="{{ $course->trashed() ? '' : route('admin.courses.show', $courseWorkspaceId) }}" onclick="navigateToCourse(event, this)">
                        <!-- Course Image -->
                        <div class="course-image-container">
                            @if($course->image)
                                <img src="{{ $course->image }}" alt="{{ $course->title }}" class="course-image-img">
                            @else
                                <div class="course-image-placeholder">
                                    <i class="fa fa-book"></i>
                                </div>
                            @endif
                            @if($course->trashed())
                                <div class="course-coming-soon-badge">
                                    <i class="fa fa-archive course-coming-soon-badge__icon"></i> مؤرشف
                                </div>
                            @elseif($course->is_coming_soon)
                                <div class="course-coming-soon-badge">
                                    <i class="fa fa-clock-o course-coming-soon-badge__icon"></i> قريباً
                                </div>
                            @endif
                        </div>

                        <!-- Course Body -->
                        <div class="course-body">
                            <!-- Course Title -->
                            <h3 class="course-title">{{ $course->title }}</h3>

                            <div class="mb-3">
                                @if($course->trashed())
                                    <span class="badge badge-dark px-3 py-2">مؤرشف · محتواه غير متاح للطلاب</span>
                                @elseif($courseHasActiveDraft)
                                    <span class="badge badge-info px-3 py-2">
                                        نسخة منشورة · تعديلات محفوظة كمسودة
                                    </span>
                                @elseif(!$course->is_coming_soon && $course->is_catalog_visible)
                                    <span class="badge badge-success px-3 py-2">منشور</span>
                                @elseif(!$course->is_coming_soon)
                                    <span class="badge badge-secondary px-3 py-2">منشور للطلاب · مخفي من الاكتشاف</span>
                                @elseif($course->is_catalog_visible)
                                    <span class="badge badge-primary px-3 py-2">مُعلن في التطبيق · قريبًا</span>
                                @else
                                    <span class="badge badge-warning px-3 py-2">مسودة</span>
                                @endif
                            </div>

                            <!-- Course Meta -->
                            <div class="course-meta">
                                @if($course->classifications->count() > 0)
                                    <div class="meta-item">
                                        <i class="fa fa-tags meta-icon"></i>
                                        <span>
                                            @foreach($course->classifications as $classification)
                                                <span class="badge badge-light">{{ $classification->name_ar }}</span>
                                            @endforeach
                                        </span>
                                    </div>
                                @endif



                                <div class="meta-item">
                                    <i class="fa fa-money meta-icon"></i>
                                    @php
                                        $activePlanPrices = $course->accessPlans
                                            ->where('is_active', true)
                                            ->pluck('price_coins')
                                            ->map(fn ($price) => (int) $price)
                                            ->sort()
                                            ->values();
                                        $lowestPlanPrice = $activePlanPrices->first();
                                        $highestPlanPrice = $activePlanPrices->last();
                                    @endphp
                                    <span>
                                        @if($activePlanPrices->isEmpty())
                                            لا توجد فئة متاحة
                                        @elseif($lowestPlanPrice === $highestPlanPrice)
                                            {{ number_format($lowestPlanPrice) }} عملة
                                        @else
                                            {{ number_format($lowestPlanPrice) }}–{{ number_format($highestPlanPrice) }} عملة
                                        @endif
                                    </span>
                                </div>
                                <div class="meta-item">
                                    <i class="fa fa-robot meta-icon"></i>
                                    <span>{{ $course->accessPlans->where('is_active', true)->contains(fn ($plan) => (bool) $plan->chat_enabled) ? 'Rokn AI في فئات مختارة' : 'Rokn AI متوقف' }}</span>
                                </div>
                            </div>

                            @if($canViewFinance)
                            <div class="course-finance-summary">
                                <div class="course-finance-summary__grid">
                                    <div>
                                        <strong class="course-finance-summary__value course-finance-summary__value--total">{{ number_format((int) ($course->total_coins_spent ?? 0)) }}</strong>
                                        <small>إجمالي العملات</small>
                                    </div>
                                    <div>
                                        <strong class="course-finance-summary__value course-finance-summary__value--paid">{{ number_format((int) ($course->paid_coins_spent ?? 0)) }}</strong>
                                        <small>عملات مشتراة</small>
                                    </div>
                                    <div>
                                        <strong class="course-finance-summary__value course-finance-summary__value--reward">{{ number_format((int) ($course->reward_coins_spent ?? 0)) }}</strong>
                                        <small>عملات مكافآت</small>
                                    </div>
                                </div>
                                <small class="course-finance-summary__note">
                                    تُستهلك المكافآت أولًا ثم العملات المشتراة. هذه وحدات عملات ركن وليست إيرادًا نقديًا؛ دخل Kashier مستقل في طلبات الباقات.
                                    @if((int) ($course->coin_ledger_incomplete_orders ?? 0) > 0)
                                        <br><span class="text-warning">{{ number_format((int) $course->coin_ledger_incomplete_orders) }} عملية تحتاج ربط الدفتر</span>
                                    @endif
                                </small>
                            </div>
                            @endif

                            <!-- Course Description -->
                            @if($course->description)
                                <div class="course-description">
                                    {{ $course->description }}
                                </div>
                            @endif

                            <!-- Course Stats -->
                            <div class="course-stats">
                                @if($canViewFinance)
                                <div class="stat-mini">
                                    <span class="stat-mini-number">{{ number_format((int) ($course->active_enrollments_count ?? 0)) }}</span>
                                    <span class="stat-mini-label">طلاب فعليون</span>
                                </div>
                                <div class="stat-mini">
                                    <span class="stat-mini-number">{{ $course->ratings_count ? number_format((float) $course->ratings_avg_rating, 1) : '—' }}</span>
                                    <span class="stat-mini-label">تقييم · {{ number_format((int) $course->ratings_count) }}</span>
                                </div>
                                @endif
                                <div class="stat-mini">
                                    <span class="stat-mini-number">{{ number_format((int) ($course->preview_steps_count ?? 0)) }}</span>
                                    <span class="stat-mini-label">مقاطع مجانية</span>
                                </div>
                            </div>
                            <div class="text-muted course-card-footnote mb-3">
                                {{ number_format((int) $course->sections_count) }} أقسام
                                @if($canViewFinance)
                                    · يظهر للطالب {{ number_format((int) ($course->active_enrollments_count ?? 0)) }} طالبًا
                                @endif
                                @if($course->is_main_course) · <strong class="text-primary">الكورس الرئيسي الوحيد</strong> @endif
                            </div>

                            <!-- Course Actions -->
                            <div class="course-actions">
                                @if($course->trashed())
                                    <form action="{{ route('admin.courses.restore', $course->id) }}" method="post">
                                        @csrf
                                        <button type="submit" class="btn-card btn-card-success">
                                            <i class="fa fa-undo"></i>
                                            استعادة كمسودة
                                        </button>
                                    </form>
                                @else
                                <a href="{{ route('admin.courses.show', $courseWorkspaceId) }}" class="btn-card btn-card-primary">
                                    <i class="fa fa-magic"></i>
                                    فتح الاستوديو
                                </a>
                                @if($canViewFinance)
                                    @php($preservesLearnerAccess = $course->published_at !== null || (int) ($course->last_published_authoring_version ?? 0) > 0 || !$course->is_coming_soon)
                                    @if($preservesLearnerAccess && !$course->is_catalog_visible)
                                        <button type="button" class="btn-card btn-card-danger" disabled aria-disabled="true">
                                            <i class="fa fa-eye-slash"></i> مخفي
                                        </button>
                                    @else
                                        <button onclick="deleteCourse({{ $course->id }}, {{ $preservesLearnerAccess ? 'true' : 'false' }})" class="btn-card btn-card-danger">
                                            <i class="fa fa-archive"></i>
                                            {{ $preservesLearnerAccess ? 'إخفاء' : 'أرشفة' }}
                                        </button>
                                    @endif
                                @endif
                                @endif
                            </div>
                        </div>

                        <!-- Hidden Delete Form -->
                        @if(!$course->trashed() && $canViewFinance)
                            <form class="course-delete-form" id="deleteForm{{ $course->id }}" action="{{ route('admin.courses.destroy', $course->id) }}" method="post">
                                <input name="_method" type="hidden" value="DELETE">
                                @csrf
                                <input type="hidden" name="authoring_version" value="{{ $course->authoring_version }}">
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        @else
            <!-- Empty State -->
            <div class="empty-state">
                <div class="empty-icon">
                    <i class="fa fa-book"></i>
                </div>
                <h3 class="empty-title">{{ ($filters['state'] ?? 'active') === 'archived' ? 'لا توجد كورسات مؤرشفة' : 'لا توجد نتائج' }}</h3>
                <p class="empty-description">
                    {{ !empty($filters['search']) || !empty($filters['classification_id']) ? 'غيّر البحث أو الفلاتر لعرض نتائج أخرى.' : (($filters['state'] ?? 'active') === 'archived' ? 'ستظهر هنا الكورسات التي تنقلها إلى الأرشيف.' : 'لم يتم إنشاء أي كورسات بعد.') }}
                </p>
                @if(($filters['state'] ?? 'active') !== 'archived' && empty($filters['search']) && empty($filters['classification_id']))
                    <a href="{{ route('admin.courses.create') }}" class="btn-modern btn-primary-modern">
                    <i class="fa fa-plus"></i>
                    إضافة كورس جديد
                    </a>
                @endif
            </div>
        @endif
    </div>
