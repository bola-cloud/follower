@extends('layouts.admin')

@section('content')
<div class="container py-4">
    <h2 class="mb-4">تفاصيل الطلب رقم #{{ $order->id }}</h2>

    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card mb-4">
        <div class="card-body">
            <p><strong>المستخدم:</strong> {{ $order->user->name }} ({{ $order->user->email }})</p>
            <p><strong>الرابط:</strong> <a href="{{ $order->target_url }}" target="_blank">{{ $order->target_url }}</a></p>
            <p><strong>النوع:</strong> {{ $order->type }}</p>
            <p><strong>الحالة:</strong> {{ $order->status }}</p>
            <p><strong>عدد الإجمالي:</strong> {{ $order->total_count }}</p>
            <p><strong>عدد المنجز:</strong> {{ $order->done_count }}</p>
            <p><strong>تاريخ الإنشاء:</strong> {{ $order->created_at->format('Y-m-d H:i') }}</p>
        </div>
    </div>

    <h4 class="mb-3">المستخدمون الذين قاموا بالإجراء</h4>

    <table class="table table-bordered table-striped">
        <thead>
            <tr>
                <th>الاسم</th>
                <th>البريد الإلكتروني</th>
                <th>تاريخ التنفيذ</th>
            </tr>
        </thead>
        <tbody>
            @forelse($actionUsers as $user)
                <tr>
                    <td>{{ $user->name }}</td>
                    <td>{{ $user->email }}</td>
                    <td>{{ $user->performed_at ? \Carbon\Carbon::parse($user->performed_at)->format('Y-m-d H:i') : \Carbon\Carbon::parse($user->created_at)->format('Y-m-d H:i') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="3" class="text-center">لا يوجد مستخدمون قاموا بالإجراء حتى الآن.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <a href="{{ route('admin.orders.index') }}" class="btn btn-secondary mt-3">العودة</a>
</div>
@endsection
