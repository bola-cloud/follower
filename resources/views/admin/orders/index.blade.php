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

    <form method="GET" class="row g-2 mb-3 align-items-end">
        <div class="col-md-4">
            <input type="text" name="search" class="form-control" placeholder="ابحث عن مستخدم أو رابط" value="{{ request('search') }}">
        </div>
        <div class="col-md-3">
            <div class="form-group">
                <label for="status" class="form-label">الحالة</label>
                <select name="status" class="form-control">
                    <option value="">كل الحالات</option>
                    <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>نشط</option>
                    <option value="completed" {{ request('status') == 'completed' ? 'selected' : '' }}>مكتمل</option>
                    <option value="paused" {{ request('status') == 'paused' ? 'selected' : '' }}>موقوف</option>
                </select>
            </div>
        </div>
        <div class="col-md-2">
            <button class="btn btn-primary w-100">بحث</button>
        </div>
        <div class="col-md-3 text-end">
            <a href="{{ route('admin.orders.create') }}" class="btn btn-success w-100">إضافة طلب جديد</a>
        </div>
    </form>

    <div class="table-responsive shadow rounded">
        <table class="table table-bordered table-striped align-middle mb-0">
            <thead class="table-dark">
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
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <img src="{{ $order->user->profile_photo_url ?? 'https://ui-avatars.com/api/?name=' . urlencode($order->user->name) }}" alt="avatar" class="rounded-circle" width="32" height="32">
                                <div>
                                    <strong>{{ $order->user->name }}</strong><br>
                                    <small class="text-muted">{{ $order->user->email }}</small>
                                </div>
                            </div>
                        </td>
                        <td><a href="{{ $order->target_url }}" target="_blank" class="text-primary text-decoration-underline">اذهب الي الرابط</a></td>
                        <td><span class="badge bg-info text-dark">{{ $order->type }}</span></td>
                        <td>
                            @if($order->status === 'active')
                                <span class="badge bg-primary">نشط</span>
                            @elseif($order->status === 'completed')
                                <span class="badge bg-success">مكتمل</span>
                            @elseif($order->status === 'paused')
                                <span class="badge bg-secondary">موقوف</span>
                            @else
                                <span class="badge bg-light text-dark">{{ $order->status }}</span>
                            @endif
                        </td>
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
    </div>

    <div class="mt-3">
        {{ $orders->links('pagination::bootstrap-4') }}
    </div>
</div>
@endsection
