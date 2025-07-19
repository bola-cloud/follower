@extends('layouts.admin')

@section('content')
<div class="container py-4">
    <h1 class="mb-4">المستخدمون</h1>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <!-- Search Form -->
    <form method="GET" class="mb-3">
        <div class="input-group">
            <input type="text" name="search" class="form-control" placeholder="ابحث بالاسم أو البريد" value="{{ request('search') }}">
            <div class="input-group-append">
                <button class="btn btn-primary" type="submit">بحث</button>
            </div>
        </div>
    </form>

    <table class="table table-bordered table-striped">
        <thead>
            <tr>
                <th>#</th>
                <th>الاسم</th>
                <th>الرابط الشخصي</th>
                <th> الايميل </th>
                <th>النقاط</th>
                <th>الإجراءات</th>
            </tr>
        </thead>
        <tbody>
            @forelse($users as $user)
                <tr>
                    <td>{{ $loop->iteration + (($users->currentPage() - 1) * $users->perPage()) }}</td>
                    <td>{{ $user->name }}</td>
                    <td>
                        @if($user->profile_link)
                            <a href="{{"https://www.instagram.com/" . $user->profile_link }}" target="_blank">عرض</a>
                        @else
                            -
                        @endif
                    </td>
                    <td>{{ $user->email ? $user->email : "--"  }}</td>
                    <td>{{ $user->points }}</td>
                    <td>
                        <a href="{{ route('admin.normal_users.orders', $user->id) }}" class="btn btn-sm btn-info">
                            عرض الطلبات
                        </a>
                        <!-- Button trigger modal -->
                        <button type="button" class="btn btn-sm btn-warning" data-toggle="modal" data-target="#addPointsModal{{ $user->id }}">
                            إضافة نقاط
                        </button>

                        <!-- Modal -->
                        <div class="modal fade" id="addPointsModal{{ $user->id }}" tabindex="-1" role="dialog" aria-labelledby="addPointsModalLabel{{ $user->id }}" aria-hidden="true">
                            <div class="modal-dialog" role="document">
                                <form action="{{ route('admin.normal_users.add_points', $user->id) }}" method="POST">
                                    @csrf
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="addPointsModalLabel{{ $user->id }}">إضافة نقاط للمستخدم</h5>
                                            <button type="button" class="close" data-dismiss="modal" aria-label="إغلاق">
                                                <span aria-hidden="true">&times;</span>
                                            </button>
                                        </div>
                                        <div class="modal-body">
                                            <label>عدد النقاط:</label>
                                            <input type="number" name="points" class="form-control" required min="1">
                                        </div>
                                        <div class="modal-footer">
                                            <button type="submit" class="btn btn-primary">حفظ</button>
                                            <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center">لا يوجد مستخدمون حتى الآن.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <!-- Pagination -->
    <div class="d-flex justify-content-center">
        {{ $users->appends(['search' => $search])->links('pagination::bootstrap-4') }}
    </div>
</div>
@endsection
