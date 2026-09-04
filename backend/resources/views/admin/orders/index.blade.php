@extends('admin.layouts.app')
@section('page.title', 'إدارة الطلبات')

@section('styles')
{{-- Include Dynamic Theme Styles --}}
@include('admin.orders.partials._dynamic_styles')

<link rel="stylesheet" href="{{ asset('admin/assets/css/orders-index.css') }}">
@endsection

@section('content')
<div class="admin-page orders-container">
    @include('admin.payments.partials.navigation')

    @include('admin.orders.partials.index.statistics')

    @include('admin.orders.partials.index.payment-channel-report')

    @include('admin.orders.partials.index.filters')

    @include('admin.orders.partials.index.orders-table')
</div>

    @include('admin.orders.partials.index.payment-modal')
@endsection

@section('scripts')
@include('admin.orders.partials.index.scripts')
@endsection
