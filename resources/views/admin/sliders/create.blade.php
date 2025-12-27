@extends('layouts.admin')

@section('title','Create Slider')

@section('content')
<div class="container py-4">
    <div class="mb-3">
        <h1 class="mb-0">إضافة شريحة جديدة</h1>
        <p class="text-muted">أنشئ شريحة جديدة لواجهة الصفحة الرئيسية</p>
    </div>

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card">
        <div class="card-body">
            <form action="{{ route('admin.sliders.store') }}" method="POST" enctype="multipart/form-data">
                @csrf

                <div class="form-group">
                    <label>العنوان</label>
                    <input type="text" name="title" class="form-control" value="{{ old('title') }}">
                </div>

                <div class="form-group">
                    <label>الوصف</label>
                    <textarea name="description" rows="3" class="form-control">{{ old('description') }}</textarea>
                </div>

                <div class="form-group">
                    <label>الصورة</label>
                    <input type="file" name="image" accept="image/*" class="form-control-file">
                </div>

                <div class="form-row">
                    <div class="form-group col-md-2">
                        <label>الترتيب</label>
                        <input type="number" name="order" class="form-control" value="{{ old('order', 0) }}">
                    </div>
                    <div class="form-group col-md-2 d-flex align-items-end">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" checked id="isActive">
                            <label class="form-check-label" for="isActive">نشط</label>
                        </div>
                    </div>
                </div>

                <div class="mt-3">
                    <button class="btn btn-primary">إنشاء</button>
                    <a href="{{ route('admin.sliders.index') }}" class="btn btn-secondary">إلغاء</a>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection
