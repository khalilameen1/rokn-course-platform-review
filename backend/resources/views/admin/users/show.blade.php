@extends('admin.layouts.app')
@section('page.title', 'الطلاب | '.$user->name)

@section('styles')
@include('admin.users.partials._dynamic_styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/users-show.css') }}">
@endsection

@section('content')
<div class="user-detail-container animated fadeIn admin-page users-page">
    @include('admin.users.partials.show.operations')

    <div class="row">
        <div class="col-lg-4 col-md-12">
            @include('admin.users.partials.show.profile')
        </div>
        <div class="col-lg-8 col-md-12">
            @include('admin.users.partials.show.notification')
            @include('admin.users.partials.show.notes')
        </div>
    </div>

    @include('admin.users.partials.show.learning-and-projects')
    @include('admin.users.partials.show.purchases')
</div>

@include('admin.users.partials.show.note-modal')
@endsection
