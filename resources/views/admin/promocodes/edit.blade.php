@extends('layouts.admin')

@section('content')
<div class="container py-4">
    <h2>تعديل الرمز الترويجي</h2>

    <form action="{{ route('admin.promocodes.update', $promocode->id) }}" method="POST">
        @csrf
        @method('PUT')

        <div class="form-group">
            <label>كود الترويج</label>
            <input type="text" class="form-control" value="{{ $promocode->code }}" disabled>
        </div>

        <div class="form-group">
            <label>النقاط</label>
            <input type="number" name="points" class="form-control" value="{{ old('points', $promocode->points) }}" required>
        </div>

        <div class="form-group">
            <label>تاريخ الانتهاء</label>
            <input type="datetime-local" name="expires_at" class="form-control"
                   value="{{ $promocode->expires_at ? \Carbon\Carbon::parse($promocode->expires_at)->format('Y-m-d\TH:i') : '' }}">
        </div>

        <button type="submit" class="btn btn-primary">تحديث</button>
        <a href="{{ route('admin.promocodes.index') }}" class="btn btn-secondary">رجوع</a>
    </form>
</div>
@endsection
