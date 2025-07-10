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
            <label for="type" class="form-label">نوع الطلب</label>
            <select name="type" id="type" class="form-control @error('type') is-invalid @enderror" required>
                <option value="follow" {{ old('type') == 'follow' ? 'selected' : '' }}>متابعة</option>
                <option value="like" {{ old('type') == 'like' ? 'selected' : '' }}>إعجاب</option>
            </select>
            @error('type')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="total_count" class="form-label">العدد الإجمالي</label>
            <input type="number" name="total_count" id="total_count" class="form-control @error('total_count') is-invalid @enderror" required min="1" value="{{ old('total_count') }}">
            @error('total_count')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="target_url" class="form-label">الرابط المستهدف</label>
            <input type="url" name="target_url" id="target_url" class="form-control @error('target_url') is-invalid @enderror" required value="{{ old('target_url') }}">
            @error('target_url')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <button type="submit" class="btn btn-primary">إنشاء الطلب</button>
    </form>
</div>
@endsection
