@if(!empty($project['verified']))
<div class="verified">
    <span class="verified-mark" aria-hidden="true">&#10003;</span>
    <span>مشروع اتراجع في ركن</span>
    @if(!empty($project['certificate_url']))<a href="{{ $project['certificate_url'] }}" rel="noopener">عرض الشهادة</a>@endif
</div>
@endif
