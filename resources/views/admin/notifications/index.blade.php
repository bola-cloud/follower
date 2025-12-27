@extends('layouts.admin')

@section('content')
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="mb-0">الإشعارات</h1>
            <p class="text-muted mb-0">سجل الإشعارات المرسلة</p>
        </div>
        <div>
            <a href="{{ route('admin.notifications.create') }}" class="btn btn-primary">إنشاء إشعار</a>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped mb-0">
                    <thead>
                        <tr>
                            <th>العنوان</th>
                            <th>النص</th>
                            <th>الحالة</th>
                            <th>تاريخ الإرسال</th>
                            <th class="text-right">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($notifications as $n)
                            <tr>
                                <td>{{ $n->title }}</td>
                                <td>{{ \Illuminate\\Support\\Str::limit($n->body, 80) }}</td>
                                <td>{{ $n->status }}</td>
                                <td>{{ $n->sent_at ? $n->sent_at->format('Y-m-d H:i') : '-' }}</td>
                                <td class="text-right">
                                    <a href="{{ route('admin.notifications.show', $n) }}" class="btn btn-sm btn-info">عرض</a>
                                    <form action="{{ route('admin.notifications.resend', $n) }}" method="POST" class="d-inline">
                                        @csrf
                                        <button class="btn btn-sm btn-warning">أعد الإرسال</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer">
            {{ $notifications->links() }}
        </div>
    </div>
</div>

@endsection
