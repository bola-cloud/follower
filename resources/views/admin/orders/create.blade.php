@extends('layouts.admin')

@section('content')
<div class="container py-4">
    <h1 class="mb-4">إضافة طلب جديد</h1>

    @if($errors->any())
        <div class="alert alert-danger">
            <ul>
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('admin.orders.store') }}" method="POST">
        @csrf

        <div class="mb-3">
            <label for="user_id" class="form-label">المستخدم</label>
            <select name="user_id" id="user_id" class="form-control" required>
                @foreach(\App\Models\User::where('type', 'user')->get() as $user)
                    <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                @endforeach
            </select>
        </div>

        <div class="mb-3">
            <label for="type" class="form-label">نوع الطلب</label>
            <select name="type" id="type" class="form-control" required>
                <option value="follow">متابعة</option>
                <option value="like">إعجاب</option>
            </select>
        </div>

        <div class="mb-3">
            <label for="total_count" class="form-label">العدد الإجمالي</label>
            <input type="number" name="total_count" id="total_count" class="form-control" required min="1">
        </div>

        <div class="mb-3">
            <label for="target_url" class="form-label">الرابط المستهدف</label>
            <input type="url" name="target_url" id="target_url" class="form-control" required>
        </div>

        <div class="mb-3">
            <label for="cost" class="form-label">التكلفة (اختياري)</label>
            <input type="number" name="cost" id="cost" class="form-control">
        </div>

        <button type="submit" class="btn btn-primary">إنشاء الطلب</button>
    </form>
</div>
@endsection
