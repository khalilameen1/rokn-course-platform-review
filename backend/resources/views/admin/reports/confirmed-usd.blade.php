@if($complete)
    ${{ number_format($amount, 6) }}
@elseif($amount > 0)
    ${{ number_format($amount, 6) }} مؤكد جزئيًا
@else
    <span class="text-warning">بانتظار التأكيد</span>
@endif
