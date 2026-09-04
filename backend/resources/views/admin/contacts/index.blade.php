@extends('admin.layouts.app')
@section('page.title', 'رسائل الدعم')

@section('styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/admin-learning-views.css') }}">
@endsection

@section('content')

<div class="contacts-wrapper admin-learning admin-learning--contacts">
    <div class="contacts-header">
        <h1>
            <i class="fa fa-envelope"></i>
            رسائل الموقع وطلبات حذف الحساب
        </h1>
    </div>

    @include('admin.partials.support-inbox-tabs', ['supportSource' => 'website'])

    <div class="contacts-card">
        <div class="table-responsive">
            <table id="bootstrap-data-table" class="modern-table table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الاسم</th>
                        <th>الجوال</th>
                        <th>البريد</th>
                        <th>التاريخ</th>
                        <th>حالة الرسالة</th>
                        <th>نوع الطلب</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($contacts as $contact)
                        <tr>
                            <th>{{ $contact->id }}</th>
                            <td>{{ $contact->name }}</td>
                            <td><a href="tel:{{ $contact->phone }}">{{ $contact->phone }}</a></td>
                            <td><a href="mailto:{{ $contact->email }}">{{ $contact->email }}</a></td>
                            <td>{{ $contact->created_at->isoFormat('LLL') }}</td>
                            <td>
                                <span class="status-badge {{ $contact->read ? 'read' : 'unread' }}">
                                    <i class="fa {{ $contact->read ? 'fa-check-circle' : 'fa-exclamation-circle' }}"></i>
                                    {{ $contact->read ? 'مقروءة' : 'رسالة جديدة' }}
                                </span>
                            </td>
                            <td>
                                @if($contact->isAccountDeletionRequest())
                                    <span class="badge badge-{{ $contact->isResolved() ? 'success' : 'warning' }}">
                                        حذف حساب · {{ $contact->isResolved() ? 'منفذ' : 'معلق' }}
                                    </span>
                                @else
                                    <span class="text-muted">رسالة عامة</span>
                                @endif
                            </td>
                            <td>
                                <div class="dropdown action-dropdown">
                                    <button class="action-btn" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                        <i class="fa fa-ellipsis-v"></i>
                                    </button>
                                    <div class="dropdown-menu dropdown-menu-right">
                                        <form action="{{ route('admin.contacts.read', $contact->id) }}" method="POST" class="m-0">
                                            @csrf
                                            <input type="hidden" name="editor_version" value="{{ $editorVersions[$contact->id] }}">
                                            <button type="submit" class="dropdown-item border-0 bg-transparent text-right w-100">
                                                <i class="fa fa-eye"></i>
                                                <span>مشاهدة التفاصيل</span>
                                            </button>
                                        </form>
                                        @unless($contact->isAccountDeletionRequest())
                                            <a class="dropdown-item text-danger" href="#" onclick="event.preventDefault(); if(confirm('هل أنت متأكد من الحذف؟')) document.getElementById('deleteForm{{$contact->id}}').submit();">
                                                <i class="fa fa-trash"></i>
                                                <span>حذف</span>
                                            </a>
                                            <form class="admin-inline-hidden" id="deleteForm{{$contact->id}}" action="{{ route('admin.contacts.destroy', $contact->id) }}" method="post">
                                                <input name="_method" type="hidden" value="DELETE">
                                                @csrf
                                                <input type="hidden" name="editor_version" value="{{ $editorVersions[$contact->id] }}">
                                            </form>
                                        @endunless
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="d-flex justify-content-center mt-3">
            {{ $contacts->links() }}
        </div>
    </div>
</div>
@endsection
