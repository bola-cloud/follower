@extends('layouts.admin')

@section('content')
<div class="container py-4">
    <h1 class="mb-4">طلبات المستخدم: {{ $user->name }}</h1>

    <table class="table table-bordered table-striped">
        <thead>
            <tr>
                <th>#</th>
                <th>الرابط المستهدف</th>
                <th>الحالة</th>
                <th>التكلفة</th>
                <th>تم إنجازه</th>
                <th>تاريخ الإنشاء</th>
            </tr>
        </thead>
        <tbody>
            @forelse($orders as $order)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $order->target_url }}</td>
                    <td>{{ $order->status }}</td>
                    <td>{{ $order->cost }}</td>
                    <td>{{ $order->done_count }} / {{ $order->total_count }}</td>
                    <td>{{ $order->created_at->format('Y-m-d H:i') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center">لا يوجد طلبات لهذا المستخدم.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <a href="{{ route('admin.normal_users.index') }}" class="btn btn-secondary mt-3">رجوع</a>
</div>
@endsection
