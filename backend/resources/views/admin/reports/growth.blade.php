@php
    $status = $change['status'] ?? 'unavailable';
    $color = 'text-muted';
    if (!($neutral ?? false) && in_array($status, ['up', 'down'], true)) {
        $favorable = ($status === 'up') !== ($lowerIsBetter ?? false);
        $color = $favorable ? 'text-success' : 'text-danger';
    }
@endphp
<small class="d-block {{ $color }}">
    @if($status === 'new')
        جديد مقارنة بالفترة السابقة
    @elseif($status === 'unavailable')
        المقارنة غير متاحة
    @elseif($status === 'unchanged')
        دون تغير 0٪
    @elseif($change['percentage'] == 0)
        {{ $status === 'up' ? 'ارتفاع' : 'انخفاض' }} أقل من 0٫1٪ عن الفترة السابقة
    @else
        <bdi>{{ $status === 'up' ? '+' : '' }}{{ number_format($change['percentage'], 1) }}٪</bdi> عن الفترة السابقة
    @endif
</small>
