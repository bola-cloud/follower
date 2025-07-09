@extends('layouts.admin')

@section('content')
<div class="container">
    <h1 class="my-4">المستخدمون العاديون</h1>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <table class="table table-bordered table-striped">
        <thead>
            <tr>
                <th>#</th>
                <th>الاسم</th>
                <th>رابط الملف الشخصي</th>
                <th>النقاط</th>
                <th>تاريخ التسجيل</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($users as $user)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $user->name }}</td>
                    <td>
                        @if($user->profile_link)
                            <a href="{{ $user->profile_link }}" target="_blank">{{ $user->profile_link }}</a>
                        @else
                            -
                        @endif
                    </td>
                    <td>{{ $user->points }}</td>
                    <td>{{ $user->created_at->format('Y-m-d') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="text-center">لا يوجد مستخدمون عاديون بعد.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
