<label for="report-period">الفترة</label>
<select id="report-period" name="period" class="form-control">
    @foreach(\App\Support\ReportPeriod::labels() as $key => $label)
        <option value="{{ $key }}" @selected($period->key === $key)>{{ $label }}</option>
    @endforeach
</select>
