@extends('layouts.admin')

@section('content')
<div class="container py-4">
    <h2>إنشاء رموز ترويجية تلقائيًا</h2>

    <form method="POST" action="{{ route('admin.promocodes.store') }}">
        @csrf

        <div class="form-group">
            <label>عدد النقاط لكل رمز</label>
            <input type="number" name="points" class="form-control" required min="1">
        </div>

        <div class="form-group">
            <label>عدد الرموز</label>
            <input type="number" name="count" class="form-control" required min="1" max="100">
        </div>

        <div class="form-group">
            <label>تاريخ الانتهاء (اختياري)</label>
            <input type="datetime-local" name="expires_at" class="form-control">
        </div>

        <button class="btn btn-primary">إنشاء الرموز</button>
        <a href="{{ route('admin.promocodes.index') }}" class="btn btn-secondary">إلغاء</a>
    </form>
</div>
@endsection
