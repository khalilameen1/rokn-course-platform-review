@extends('admin.layouts.app')

@section('page.title', 'تفاصيل المعلم')

@section('content')
<div class="admin-page animated fadeIn">
    <div class="row">
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <strong class="card-title">معلومات المعلم</strong>
                </div>
                <div class="card-body">
                    <div class="mx-auto d-block">
                        <img class="rounded-circle mx-auto d-block" src="{{ $teacher->profile_image_url ?? asset('assets/images/users/user.png') }}" alt="{{ $teacher->name_ar }}" width="120">
                        <h5 class="text-sm-center mt-2 mb-1">
                            {{ $teacher->name_ar }}
                            @if($teacher->name_en)
                                <br><small class="text-muted">{{ $teacher->name_en }}</small>
                            @endif
                        </h5>
                        <div class="location text-sm-center text-muted">
                            <i class="fa fa-briefcase"></i> {{ $teacher->job_title }}
                        </div>
                    </div>
                    <hr>
                    <div class="card-text">
                        @if($teacher->bio_ar)
                            <p><strong>نبذة (عربي):</strong><br>{{ $teacher->bio_ar }}</p>
                        @endif
                        @if($teacher->bio_en)
                            <p><strong>نبذة (إنجليزي):</strong><br>{{ $teacher->bio_en }}</p>
                        @endif
                    </div>
                </div>
                @if($canManageCredentials)
                <div class="card-footer">
                    <strong class="card-title">بيانات التواصل</strong>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item">
                            <i class="fa fa-envelope-o"></i> {{ $teacher->email }}
                        </li>
                        <li class="list-group-item">
                            <i class="fa fa-phone"></i> {{ $teacher->phone }}
                        </li>
                        <li class="list-group-item">
                            <i class="fa fa-calendar"></i> انضم في: {{ $teacher->created_at->format('Y-m-d') }}
                        </li>
                    </ul>
                </div>
                @endif
            </div>
        </div>

        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <strong class="card-title">الكورسات المسندة</strong>
                </div>
                <div class="card-body">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>اسم الكورس</th>
                                @if($canViewEnrollmentCounts)<th>عدد الطلاب</th>@endif
                                <th>تاريخ الإنشاء</th>
                                <th>العمليات</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($courses as $course)
                            <tr>
                                <td>{{ $course->title }}</td>
                                @if($canViewEnrollmentCounts)<td>{{ number_format($course->active_enrollments_count) }}</td>@endif
                                <td>{{ $course->created_at->format('Y-m-d') }}</td>
                                <td>
                                    <a href="{{ route('admin.courses.show', $course->id) }}" class="btn btn-primary btn-sm">
                                        <i class="fa fa-eye"></i> عرض
                                    </a>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="{{ $canViewEnrollmentCounts ? 4 : 3 }}" class="text-center">لا يوجد كورسات حالياً</td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                    <div class="mt-3">
                        {{ $courses->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
