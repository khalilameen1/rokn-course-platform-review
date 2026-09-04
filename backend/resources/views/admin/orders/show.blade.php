@extends('admin.layouts.app')
@section('page.title', 'مشاهدة الطلب #' . $order->id)

@section('styles')
{{-- Include Dynamic Theme Styles --}}
@include('admin.orders.partials._dynamic_styles')

<link rel="stylesheet" href="{{ versioned_asset('admin/assets/css/orders-show.css') }}">
@endsection

@section('content')
<div class="admin-page orders-show-page">
    @include('admin.payments.partials.navigation')

    <div class="mb-3">
        <a href="{{ route('admin.orders.index') }}" class="btn btn-outline-secondary">
            <i class="fa fa-arrow-right"></i> العودة للقائمة
        </a>
    </div>

    <div class="row">
        @include('admin.orders.partials.show.order-information')

        @include('admin.orders.partials.show.actions-panel')
    </div>

    @include('admin.orders.partials.show.screenshot-modal')
</div>
@endsection

@section('scripts')
@include('admin.orders.partials.show.scripts')
@endsection
