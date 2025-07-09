@extends('layouts.admin')

@section('content')
<div class="container py-4">
    <h1 class="mb-4">المستخدمون</h1>

    @if(session('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
    @endif

    <table class="table table-bordered table-striped">
        <thead>
            <tr>
                <th>#</th>
                <th>الاسم</th>
                <th>البريد الإلكتروني</th>
                <th>الرابط الشخصي</th>
                <th>النقاط</th>
                <th>الإجراءات</th>
            </tr>
        </thead>
        <tbody>
            @forelse($users as $user)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $user->name }}</td>
                    <td>{{ $user->email }}</td>
                    <td>
                        @if($user->profile_link)
                            <a href="{{ $user->profile_link }}" target="_blank">عرض</a>
                        @else
                            -
                        @endif
                    </td>
                    <td>{{ $user->points }}</td>
                    <td>
                        <a href="{{ route('admin.normal_users.orders', $user->id) }}" class="btn btn-sm btn-info">
                            عرض الطلبات
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center">لا يوجد مستخدمون حتى الآن.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
