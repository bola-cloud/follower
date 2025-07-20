@extends('layouts.admin')

@section('content')
<div class="container py-4">
    <h1 class="mb-4">إدارة الطلبات</h1>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    
    @if($errors->any())
        <div class="alert alert-danger">
            <ul>
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="GET" class="mb-3">
        <div class="input-group">
            <input type="text" name="search" class="form-control" placeholder="ابحث عن مستخدم أو رابط" value="{{ request('search') }}">
            <button class="btn btn-primary">بحث</button>
        </div>
    </form>

    <div class="mb-3">
        <a href="{{ route('admin.orders.create') }}" class="btn btn-success">إضافة طلب جديد</a>
    </div>

    <table class="table table-bordered table-striped">
        <thead>
            <tr>
                <th>#</th>
                <th>المستخدم</th>
                <th>الرابط</th>
                <th>النوع</th>
                <th>الحالة</th>
                <th>التاريخ</th>
                <th>الإجراءات</th>
            </tr>
        </thead>
        <tbody>
            @forelse($orders as $order)
                <tr>
                    <td>{{ $order->id }}</td>
                    <td>{{ $order->user->name }} ({{ $order->user->email }})</td>
                    <td><a href="{{ $order->target_url }}" target="_blank">اذهب الي الرابط </a></td>
                    <td>{{ $order->type }}</td>
                    <td>{{ $order->status }}</td>
                    <td>{{ $order->created_at->format('Y-m-d H:i') }}</td>
                    <td>
                        <a href="{{ route('admin.orders.show', $order->id) }}" class="btn btn-sm btn-info">عرض</a>

                        @if($order->status !== 'completed' && $order->status !== 'paused')
                            <form action="{{ route('admin.orders.complete', $order->id) }}" method="POST" onsubmit="return confirm('هل تريد إكمال الطلب؟')" class="d-inline-block">
                                @csrf
                                <button class="btn btn-sm btn-warning">إكمال</button>
                            </form>

                            <form action="{{ route('admin.orders.cancel', $order->id) }}" method="POST" onsubmit="return confirm('هل تريد إلغاء الطلب؟')" class="d-inline-block">
                                @csrf
                                <button class="btn btn-sm btn-danger">إلغاء</button>
                            </form>
                        @elseif($order->status === 'paused')
                            <span class="badge bg-secondary">موقوف</span>
                        @else
                            <span class="badge bg-success">مكتمل</span>
                        @endif
                    </td>

                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center">لا توجد طلبات.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="mt-3">
        {{ $orders->links('pagination::bootstrap-4') }}
    </div>
</div>
@endsection
