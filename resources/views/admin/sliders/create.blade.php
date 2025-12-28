@extends('layouts.admin')

@section('title','Create Slider')

@section('content')
<div class="container py-4">
    <div class="card shadow-sm">
        <div class="card-header bg-white border-0">
            <div class="d-flex justify-content-between align-items-center">
                <div class="text-right">
                    <h4 class="mb-0">إضافة شريحة جديدة</h4>
                    <small class="text-muted">أنشئ شريحة جديدة لواجهة الصفحة الرئيسية</small>
                </div>
            </div>
        </div>
        <div class="card-body">
            @if($errors->any())
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form action="{{ route('admin.sliders.store') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <div class="row justify-content-center">
                    <div class="col-md-8">
                        <div class="form-group text-right">
                            <label for="title">العنوان</label>
                            <input id="title" type="text" name="title" class="form-control" value="{{ old('title') }}">
                        </div>

                        <div class="form-group text-right">
                            <label for="description">الوصف</label>
                            <textarea id="description" name="description" rows="4" class="form-control">{{ old('description') }}</textarea>
                        </div>

                        <div class="form-group text-right">
                            <label for="image">الصورة</label>
                            <div class="mb-2">
                                <input id="image" type="file" name="image" accept="image/*" class="form-control-file">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-6 text-right">
                                <label for="start_at">تاريخ البدء</label>
                                <input id="start_at" type="date" name="start_at" class="form-control" value="{{ old('start_at') }}">
                            </div>
                            <div class="form-group col-md-6 text-right">
                                <label for="end_at">تاريخ الانتهاء (شامل)</label>
                                <input id="end_at" type="date" name="end_at" class="form-control" value="{{ old('end_at') }}">
                                <small class="form-text text-muted">إذا أدخلت تاريخًا (مثلاً 2025-12-23) فستنتهي الشريحة عند بداية 2025-12-24</small>
                            </div>
                        </div>

                        <div class="form-row align-items-center">
                            <div class="form-group col-md-3 text-right">
                                    <label for="order">الترتيب</label>
                                    <input id="order" type="number" name="order" class="form-control form-control-sm text-center" style="width:110px;" value="{{ old('order', 0) }}">
                                </div>
                                <div class="form-group col-md-3 text-right d-flex align-items-center">
                                    <div class="custom-control custom-switch mb-0">
                                        <input type="checkbox" class="custom-control-input" id="isActive" name="is_active" value="1" checked>
                                        <label class="custom-control-label" for="isActive">نشط</label>
                                    </div>
                                </div>
                        </div>

                        <div class="mt-4 d-flex justify-content-end">
                            <a href="{{ route('admin.sliders.index') }}" class="btn btn-light ml-2">إلغاء</a>
                            <button class="btn btn-primary">إنشاء</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection
