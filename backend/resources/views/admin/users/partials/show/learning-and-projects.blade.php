<div class="row" id="studentLearning">
    <div class="col-lg-6 col-md-12">
        <div class="section-card-modern">
            <div class="section-header-modern">
                <h3 class="section-title"><i class="fa fa-play-circle"></i> التعلم الحالي</h3>
                <a href="{{ route('admin.student-progress.show', $user->id) }}" class="btn-action-modern btn-edit">كل التقدم</a>
            </div>
            <div class="section-body">
                @if(($learning['has_enrollment'] ?? false) && is_array($learning['progress'] ?? null))
                    <h4>{{ $learning['course']->title ?? 'كورس غير متاح' }}</h4>
                    <div class="progress-bar-modern mt-3" role="progressbar" aria-valuenow="{{ $learning['progress']['progress_percentage'] }}" aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar-fill" data-progress-value="{{ $learning['progress']['progress_percentage'] }}"></div>
                    </div>
                    <div class="stats-container mt-3">
                        <span class="stat-badge stat-badge-primary">{{ number_format($learning['progress']['progress_percentage']) }}%</span>
                        <span class="stat-badge stat-badge-light">{{ number_format($learning['progress']['completed_sections']) }} من {{ number_format($learning['progress']['total_sections']) }} مقطعًا ومشروعًا</span>
                        @if($learning['progress']['last_activity'])
                            <span class="stat-badge stat-badge-light">آخر نشاط {{ \App\Support\BusinessClock::relative($learning['progress']['last_activity']) }}</span>
                        @endif
                    </div>
                @else
                    <div class="empty-state-modern"><i class="fa fa-play-circle"></i><h4>لا يوجد كورس نشط</h4></div>
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-6 col-md-12" id="studentProjects">
        <div class="section-card-modern">
            <div class="section-header-modern">
                <h3 class="section-title"><i class="fa fa-briefcase"></i> المشاريع</h3>
            </div>
            <div class="section-body">
                <div class="stats-container mb-3">
                    <span class="stat-badge stat-badge-danger">قيد المراجعة {{ number_format((int) $projectStatusCounts->get(\App\Models\ProjectSubmission::STATUS_PENDING, 0)) }}</span>
                    <span class="stat-badge stat-badge-success">اجتاز {{ number_format((int) $projectStatusCounts->get(\App\Models\ProjectSubmission::STATUS_PASSED, 0)) }}</span>
                    <span class="stat-badge stat-badge-light">إعادة {{ number_format((int) $projectStatusCounts->get(\App\Models\ProjectSubmission::STATUS_NEEDS_RESUBMISSION, 0)) }}</span>
                    <span class="stat-badge stat-badge-info">البرتـفوليو {{ number_format((int) $user->portfolio_items_count) }}</span>
                    @if((int) $user->public_portfolio_items_count > 0)
                        <span class="stat-badge stat-badge-light">قابل للمشاركة {{ number_format((int) $user->public_portfolio_items_count) }}</span>
                    @endif
                </div>
                @forelse($projectSubmissions as $submission)
                    @php($projectSection = optional($submission->project)->section)
                    <div class="note-item">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <a href="{{ route('admin.project-submissions.show', $submission) }}" class="user-detail-link">
                                    <strong>{{ optional($projectSection)->title ?: 'مشروع #'.$submission->project_id }}</strong>
                                </a>
                                <div class="note-meta">{{ optional(optional($projectSection)->course)->title ?: 'كورس غير متاح' }}</div>
                            </div>
                            @switch($submission->review_status)
                                @case(\App\Models\ProjectSubmission::STATUS_PASSED)
                                    <span class="stat-badge stat-badge-success">اجتاز</span>
                                    @break
                                @case(\App\Models\ProjectSubmission::STATUS_NEEDS_RESUBMISSION)
                                    <span class="stat-badge stat-badge-danger">يحتاج إعادة</span>
                                    @break
                                @default
                                    <span class="stat-badge stat-badge-light">قيد المراجعة</span>
                            @endswitch
                        </div>
                    </div>
                @empty
                    <div class="empty-state-modern"><i class="fa fa-briefcase"></i><h4>لا توجد محاولات</h4></div>
                @endforelse
                @if($projectSubmissions->hasPages())
                    <div class="d-flex justify-content-center mt-3">{{ $projectSubmissions->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</div>
