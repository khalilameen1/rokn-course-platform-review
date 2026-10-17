{{--
    Shared course-compensation form for the order page and a support case.
    The credit is written by OrderLifecycleService::compensateCourseOrder in
    the same request, so the copy, ceiling and confirmation must say that.
    Expects: $action (url), $maxCoins (int > 0), $idPrefix (string),
    optional $hidden (array<string, scalar>), $noteLabel (string).
--}}
<form method="POST" action="{{ $action }}" data-course-compensation>
    @csrf
    @foreach(($hidden ?? []) as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endforeach
    <p class="text-muted mb-2">تُضاف العملات إلى محفظة الطالب فور التأكيد ولا يمكن التراجع عنها، ولا يُلغى وصوله إلى الكورس.</p>
    <p class="mb-2"><strong>الحد الأقصى المتاح للتعويض:</strong> <bdi>{{ number_format((int) $maxCoins) }}</bdi> عملة</p>
    <div class="form-group">
        <label for="{{ $idPrefix }}-amount">عدد العملات</label>
        <input id="{{ $idPrefix }}-amount" name="amount" type="number" min="1" max="{{ (int) $maxCoins }}" class="form-control" value="{{ old('amount') }}" required>
    </div>
    <div class="form-group">
        <label for="{{ $idPrefix }}-note">{{ $noteLabel ?? 'رقم الشكوى وسبب التعويض' }}</label>
        <textarea id="{{ $idPrefix }}-note" name="note" class="form-control" rows="3" minlength="8" maxlength="1000" required>{{ old('note') }}</textarea>
    </div>
    <button type="submit" class="btn btn-outline-warning btn-block action-btn" onclick="var a=this.form.elements.amount.value;return confirm('سيُضاف '+a+' عملة إلى محفظة الطالب الآن ولا يمكن التراجع. هل تحققت من الشكوى والمبلغ؟')">
        <i class="fa fa-life-ring"></i> إضافة التعويض إلى المحفظة الآن
    </button>
</form>
