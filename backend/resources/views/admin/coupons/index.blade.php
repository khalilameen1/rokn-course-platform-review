@extends('admin.layouts.app')

@section('page.title', 'الكوبونات')


@section('content')
    <div class="admin-page row">
        <div class="col-md-10 offset-md-1">
            <div class="card">
                <div class="card-header"><i class="fa fa-th-large"></i><strong class="card-title pr-2">الكوبون</strong>
                    <div class="pull-left"><a href="{{ route('admin.coupons.create') }}"> إضافة كوبون <i class="fa fa-plus-square-o"></i></a></div>
                </div>
                <div class="card-body card-block">
                    @foreach($coupons as $coupon)
                        <div class="row connection-block align-items-start py-3 border-bottom">
                        <div class="col-12 col-md-2 mb-2 mb-md-0 text-right">
                            <strong>{{ $coupon->name_ar }}</strong>
                        </div>
                        <div class="col-12 col-sm-6 col-md-2 mb-2 mb-md-0 text-right">
                           <span class="d-block admin-value--ltr" title="كود الكوبون">{{ $coupon->code }}</span>
                        </div>
                        <div class="col-12 col-md-4 mb-2 mb-md-0 text-right">
                           <span class="d-block" title="نسبة الخصم">{{ (int) $coupon->balance }}٪ خصم</span>
                           <span class="d-block" title="مرات الاستخدام">{{ (int) $coupon->redemptions_count }} استخدام</span>
                           <span class="d-block" title="النطاق">{{ $coupon->course?->name_ar ?: 'كل الكورسات' }}</span>
                           <span class="d-block" title="الحد">{{ $coupon->max_redemptions ? $coupon->redemptions_count . ' / ' . $coupon->max_redemptions : 'بلا حد كلي' }}</span>
                           <span class="d-block" title="تاريخ الانتهاء">{{ optional($coupon->expiry_date)->format("Y-m-d") ?: 'بلا تاريخ' }}</span>
                        </div>
                        <div class="col-12 col-sm-6 col-md-1 mb-2 mb-md-0 text-right">
                           <span title="حالة الكوبون">{{ $coupon->active ? 'مفعل' : 'غير مفعل' }}</span>
                        </div>
                        <div class="col-12 col-md-3 admin-actions justify-content-md-end">
                            <a href="{{ route('admin.coupons.edit', $coupon->id) }}" class="btn btn-sm btn-primary"><i class="fa fa-pencil-square"></i>&nbsp; تعديل</a>
                            <button type="submit" form="deleteForm{{$coupon->id}}" class="btn btn-sm btn-danger"><i class="fa fa-close"></i>&nbsp; حذف</button>
                        </div>
                            <form class="d-none" id="deleteForm{{$coupon->id}}" action="{{ route('admin.coupons.destroy', $coupon->id) }}" method="post">
                                <input name="_method" type="hidden" value="DELETE">
                                @csrf
                                <input type="hidden" name="editor_version" value="{{ $editorVersions->get($coupon->id) }}">
                            </form>
                    </div>
                    @endforeach
                    @if($coupons->hasPages())
                        <div class="mt-3">{{ $coupons->links() }}</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
