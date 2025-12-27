@extends('layouts.admin')

@section('content')
<div class="container py-4" dir="rtl">
    <div class="card">
        <div class="card-body">
            <h3 class="text-right">{{ $notification->title }}</h3>
            <p class="text-muted text-right">{{ $notification->body }}</p>

            <hr>

            <p><strong>الحالة:</strong> {{ $notification->status }}</p>
            <p><strong>تاريخ الإرسال:</strong> {{ $notification->sent_at ? $notification->sent_at->format('Y-m-d H:i') : '-' }}</p>

            <a href="{{ route('admin.notifications.index') }}" class="btn btn-light">رجوع</a>
            <form action="{{ route('admin.notifications.resend', $notification) }}" method="POST" class="d-inline">
                @csrf
                <button class="btn btn-warning">أعد الإرسال</button>
            </form>
        </div>
    </div>
</div>

@endsection
