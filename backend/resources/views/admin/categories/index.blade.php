@extends('admin.layouts.app')

@section('page.title', 'الأقسام')


@section('content')
    @php($isAdministrator = strtolower(trim((string) auth()->user()?->role)) === 'admin')
    <div class="admin-page row">
        <div class="col-md-10 offset-md-1">
            <div class="card">
                <div class="card-header"><i class="fa fa-th-large"></i><strong class="card-title pr-2">القسم</strong>
                    @if($isAdministrator)
                        <div class="pull-left"><a href="{{ route('admin.categories.create') }}"> إضافة قسم <i class="fa fa-plus-square-o"></i></a></div>
                    @endif
                </div>
                <div class="card-body card-block">
                    @foreach($categories as $categoriy)
                        <div class="row connection-block align-items-center py-3 border-bottom">
                        <div class="col-12 col-sm-7 col-md-9 d-flex align-items-center text-right">
                            @if($categoriy->image)
                                <img src="{{ $categoriy->image }}" alt="" width="44" height="44" class="rounded ml-2" />
                            @else
                                <span class="admin-page__icon ml-2" aria-hidden="true"><i class="fa fa-folder-open-o"></i></span>
                            @endif
                            <strong>{{ $categoriy->name }}</strong>
                        </div>
                        @if($isAdministrator)
                            <div class="col-12 col-sm-5 col-md-3 admin-actions justify-content-sm-end mt-2 mt-sm-0">
                                <a href="{{ route('admin.categories.edit', $categoriy->id) }}" class="btn btn-sm btn-primary"><i class="fa fa-pencil-square"></i>&nbsp; تعديل</a>
                                <button type="submit" form="deleteForm{{$categoriy->id}}" class="btn btn-sm btn-danger" onclick="return confirm('حذف هذا القسم؟')"><i class="fa fa-close"></i>&nbsp; حذف</button>
                            </div>
                            <form class="d-none" id="deleteForm{{$categoriy->id}}" action="{{ route('admin.categories.destroy', $categoriy->id) }}" method="post">
                                <input name="_method" type="hidden" value="DELETE">
                                @csrf
                            </form>
                        @endif
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
@endsection
