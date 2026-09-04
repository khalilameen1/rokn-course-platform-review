    <!-- Filters Card -->
    <div class="row">
        <div class="col-12">
            <div class="filter-section">
                <div class="filter-section-header" onclick="toggleFilterSection()">
                    <h6>
                        <i class="fa fa-filter"></i>
                        البحث والتصفية
                    </h6>
                    <i class="fa fa-chevron-down toggle-icon" id="filter-toggle-icon"></i>
                </div>
                <div class="filter-section-body" id="filter-section-body">
                    <form method="GET" action="{{ route('admin.orders.index') }}">
                        @if(!empty($filters['package_id']))
                            <input type="hidden" name="package_id" value="{{ $filters['package_id'] }}">
                            <div class="alert alert-info py-2">تعرض القائمة عمليات باقة واحدة</div>
                        @endif
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label class="filter-label">حالة عملية الدفع</label>
                                <select name="state" class="form-control filter-control">
                                    <option value="">جميع الحالات</option>
                                    @foreach($operationStateLabels as $value => $label)
                                        <option value="{{ $value }}" @selected(($filters['state'] ?? '') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="filter-label">طريقة الدفع</label>
                                <select name="payment_method" class="form-control filter-control">
                                    <option value="">جميع الطرق</option>
                                    @if(isset($paymentMethodOptions) && $paymentMethodOptions->count() > 0)
                                        @foreach($paymentMethodOptions as $method => $label)
                                            <option value="{{ $method }}" @selected(($filters['payment_method'] ?? '') === $method)>{{ $label }}</option>
                                        @endforeach
                                    @endif
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="filter-label">البحث في المستخدمين</label>
                                <input type="text" name="user_search" class="form-control filter-control" value="{{ $filters['user_search'] ?? '' }}" maxlength="120" placeholder="اسم أو بريد أو هاتف">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="filter-label">البحث في الدورات</label>
                                <input type="text" name="course_search" class="form-control filter-control" value="{{ $filters['course_search'] ?? '' }}" maxlength="120" placeholder="اسم الدورة">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label class="filter-label">من تاريخ</label>
                                <input type="date" name="date_from" class="form-control filter-control" value="{{ $filters['date_from'] ?? '' }}">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="filter-label">إلى تاريخ</label>
                                <input type="date" name="date_to" class="form-control filter-control" value="{{ $filters['date_to'] ?? '' }}">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="filter-label">أقل مبلغ</label>
                                <input type="number" name="amount_min" class="form-control filter-control" value="{{ $filters['amount_min'] ?? '' }}" min="0" step="0.01" placeholder="0.00">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="filter-label">أعلى مبلغ</label>
                                <input type="number" name="amount_max" class="form-control filter-control" value="{{ $filters['amount_max'] ?? '' }}" min="0" step="0.01" placeholder="9999.99">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-12">
                                <button type="submit" class="btn btn-apply-filter">
                                    <i class="fa fa-search"></i> تطبيق الفلاتر
                                </button>
                                <a href="{{ route('admin.orders.index') }}" class="btn btn-reset-filter">
                                    <i class="fa fa-refresh"></i> إعادة تعيين
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
