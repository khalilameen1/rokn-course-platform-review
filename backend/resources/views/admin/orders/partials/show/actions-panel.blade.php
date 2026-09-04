        <!-- Actions Panel -->
        <div class="col-lg-4">
            <div class="card actions-card">
                <div class="card-header">
                    <h5 class="actions-card__heading">
                        <i class="fa fa-cogs"></i>
                        إجراءات الطلب
                    </h5>
                </div>
                <div class="card-body">
                    @if($order->status === \App\Models\Order::STATUS_PENDING && $order->requiresProviderVerification())
                        <div class="alert alert-{{ $order->payment_operation_state === 'expired' ? 'secondary' : 'info' }}">
                            <strong>{{ $order->payment_operation_label }}</strong>
                            <div>{{ $order->payment_operation_state === 'expired' ? 'انتهت مهلة المحاولة ويمكن للعميل بدء محاولة جديدة بعد إغلاقها من مسار التسوية.' : 'فتح صفحة الدفع لا يثبت التحصيل. القرار يأتي من دليل المزود أو طابور التسوية.' }}</div>
                        </div>
                    @endif
                    @if(
                        $order->status === \App\Models\Order::STATUS_APPROVED
                        && $order->package_id
                        && in_array($order->payment_method, ['kashier', 'google_play', 'app_store'], true)
                        && $order->gateway_settlement_status !== 'test_purchase'
                        && $order->gateway_net_amount === null
                    )
                        <div class="alert alert-info">
                            <strong>كشف التسوية لم يصل بعد</strong>
                            <div>أدخل أرقام كشف المزود؛ جميع الرسوم والاستقطاعات تُجمع في خانة الرسوم.</div>
                        </div>
                        <form method="POST" action="{{ route('admin.orders.record-settlement', $order) }}">
                            @csrf
                            <div class="form-row">
                                <div class="form-group col-6">
                                    <label for="gross-amount">الإجمالي</label>
                                    <input id="gross-amount" name="gross_amount" type="number" min="0.01" step="0.01" class="form-control" value="{{ old('gross_amount', $order->gateway_gross_amount ?? $order->final_amount) }}" required>
                                </div>
                                <div class="form-group col-6">
                                    <label for="settlement-currency">العملة</label>
                                    <input id="settlement-currency" name="currency" class="form-control text-uppercase" minlength="3" maxlength="3" value="{{ old('currency', $order->gateway_currency ?: 'EGP') }}" required>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group col-6">
                                    <label for="fee-amount">كل الرسوم</label>
                                    <input id="fee-amount" name="fee_amount" type="number" min="0" step="0.01" class="form-control" value="{{ old('fee_amount') }}" required>
                                </div>
                                <div class="form-group col-6">
                                    <label for="net-amount">الصافي</label>
                                    <input id="net-amount" name="net_amount" type="number" min="0" step="0.01" class="form-control" value="{{ old('net_amount') }}" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="settled-at">تاريخ التسوية</label>
                                <input id="settled-at" name="settled_at" type="datetime-local" class="form-control" value="{{ old('settled_at', \App\Support\BusinessClock::now()->format('Y-m-d\\TH:i')) }}" required>
                            </div>
                            <div class="form-group">
                                <label for="provider-reference">مرجع كشف المزود</label>
                                <input id="provider-reference" name="provider_reference" class="form-control" maxlength="191" value="{{ old('provider_reference') }}" required>
                            </div>
                            <button type="submit" class="btn btn-info btn-block action-btn" onclick="return confirm('هل طابقت الإجمالي والرسوم والصافي مع كشف المزود؟ لا يمكن تعديل الأرقام بعد حفظها.')">
                                <i class="fa fa-check-square-o"></i> توثيق التسوية
                            </button>
                        </form>
                        <hr class="order-action-separator">
                    @endif
                    @if(
                        $order->status === \App\Models\Order::STATUS_APPROVED
                        && $order->financial_status === \App\Models\Order::FINANCIAL_SETTLED
                        && $order->course_id
                        && $order->payment_method === \App\Models\Order::PAYMENT_METHOD_WALLET_COINS
                        && $order->wallet_transaction_id
                        && $order->coin_allocation_complete
                        && (int) ($order->ledger_total_coins ?? 0) > 0
                    )
                        <div class="alert alert-light">
                            <strong>تعويض عطل من طرف ركن</strong>
                            <div>يعيد العملات إلى مكوناتها الأصلية ولا يلغي وصول الطالب إلى الكورس.</div>
                        </div>
                        <form method="POST" action="{{ route('admin.orders.compensate-course', $order) }}">
                            @csrf
                            <div class="form-group">
                                <label for="compensation-amount">عدد العملات</label>
                                <input id="compensation-amount" name="amount" type="number" min="1" max="{{ (int) ($order->ledger_total_coins ?? 0) }}" class="form-control" value="{{ old('amount') }}" required>
                            </div>
                            <div class="form-group">
                                <label for="compensation-note">رقم الشكوى وسبب التعويض</label>
                                <textarea id="compensation-note" name="note" class="form-control" rows="3" minlength="8" maxlength="1000" required>{{ old('note') }}</textarea>
                            </div>
                            <button type="submit" class="btn btn-outline-warning btn-block action-btn" onclick="return confirm('هل تحققت من الشكوى والمبلغ؟')">
                                <i class="fa fa-life-ring"></i> إضافة التعويض
                            </button>
                        </form>
                        <hr class="order-action-separator">
                    @endif
                    @if($order->financial_status === \App\Models\Order::FINANCIAL_REVIEW_REQUIRED && $order->package_id)
                        <div class="alert alert-warning">
                            <strong>مراجعة مالية مطلوبة</strong>
                            <div>تم استرداد {{ $order->recovered_coins }} عملة، والمتبقي للمراجعة {{ $order->unrecovered_coins }}.</div>
                        </div>
                        <form method="POST" action="{{ route('admin.orders.resolve-financial-review', $order) }}">
                            @csrf
                            <div class="form-group">
                                <label for="financial-resolution">القرار</label>
                                <select id="financial-resolution" name="resolution" class="form-control" required>
                                    <option value="repaid">تم السداد أو قبول الاعتراض</option>
                                    <option value="waived">إعفاء موثق</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="financial-note">سبب القرار ورقم المرجع</label>
                                <textarea id="financial-note" name="note" class="form-control" rows="3" minlength="5" maxlength="1000" required>{{ old('note') }}</textarea>
                            </div>
                            <button type="submit" class="btn btn-warning btn-block action-btn" onclick="return confirm('هل راجعت مستندات القرار؟ سيؤثر هذا في رصيد الطالب واستحقاقاته.')">
                                <i class="fa fa-balance-scale"></i> اعتماد قرار المراجعة
                            </button>
                        </form>
                        <hr class="order-action-separator">
                    @endif
                    <hr class="order-action-separator">

                    <div class="text-center">
                        <small class="text-muted">معلومات إضافية</small>
                    </div>

                    <div class="order-summary">
                        <div class="order-summary__row">
                            <span class="order-summary__label">رقم الطلب:</span>
                            <strong class="order-summary__value">#{{ $order->id }}</strong>
                        </div>
                        <div class="order-summary__row">
                            <span class="order-summary__label">تاريخ الطلب:</span>
                            <strong class="order-summary__value">{{ \App\Support\BusinessClock::format($order->created_at, 'Y-m-d') }}</strong>
                        </div>
                        <div class="order-summary__row order-summary__row--last">
                            <span class="order-summary__label">الوقت:</span>
                            <strong class="order-summary__value">{{ \App\Support\BusinessClock::format($order->created_at, 'H:i:s') }}</strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>
