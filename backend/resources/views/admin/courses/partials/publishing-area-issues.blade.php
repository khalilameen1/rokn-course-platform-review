@if(isset($publishingAudit) && !empty($publishingAudit['issue_details']))
    @php
        $publishingAreaIssues = collect($publishingAudit['issue_details'])
            ->where('area', $area)
            ->pluck('message')
            ->values();
    @endphp
    @if($publishingAreaIssues->isNotEmpty())
        <div class="alert alert-warning mb-3" role="alert">
            <strong>مطلوب قبل النشر</strong>
            <ul class="mb-0 mt-2 pr-4">
                @foreach($publishingAreaIssues as $publishingAreaIssue)
                    <li>{{ $publishingAreaIssue }}</li>
                @endforeach
            </ul>
        </div>
    @endif
@endif
