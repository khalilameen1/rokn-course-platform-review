<section class="studio-rail-card studio-rail-card--status" data-studio-summary="readiness">
    <div class="studio-rail-card__heading"><span class="studio-status-dot {{ $course->is_coming_soon ? 'is-draft' : 'is-live' }}"></span><div><small>حالة الكورس</small><h2>{{ !$course->is_coming_soon ? ($course->is_catalog_visible ? 'منشور في التطبيق' : 'منشور للطلاب ومخفي') : ($publishingAudit['ready'] ? 'جاهز للنشر' : 'مسودة غير مكتملة') }}</h2></div></div>
    <div class="studio-readiness"><span><strong>{{ $publishingAudit['counts']['modules'] }}</strong> وحدات</span><span><strong>{{ $publishingAudit['counts']['reels'] }}</strong> مقاطع</span><span><strong>{{ $publishingAudit['counts']['projects'] }}</strong> مشروعات</span></div>
    @if($course->is_coming_soon && !$publishingAudit['ready'])<ul>@foreach(array_slice($publishingAudit['issues'], 0, 4) as $issue)<li>{{ $issue }}</li>@endforeach</ul>@endif
    @if($course->is_coming_soon)
        <button type="button" data-studio-course-open="publish">مراجعة الجاهزية والنشر</button>
    @else
        <form method="POST" action="{{ route('admin.courses.draft.start', $course) }}">
            @csrf
            <button type="submit">بدء تعديل الكورس</button>
        </form>
    @endif
</section>
