@extends('layouts.admin')

@section('title','Edit Slider')

@section('content')
<div class="container py-4">
    <div class="mb-3">
        <h1 class="mb-0">تعديل الشريحة</h1>
        <p class="text-muted">تحديث بيانات الشريحة</p>
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

    <div class="card shadow-sm">
        <div class="card-header bg-white border-0">
            <div class="d-flex justify-content-between align-items-center">
                <div class="text-right">
                    <h4 class="mb-0">تعديل الشريحة</h4>
                    <small class="text-muted">تحديث بيانات الشريحة</small>
                </div>
            </div>
        </div>
        <div class="card-body">
            <form action="{{ route('admin.sliders.update', $slider) }}" method="POST" enctype="multipart/form-data">
                @csrf
                @method('PUT')

                <div class="row justify-content-center">
                    <div class="col-md-8">
                        <div class="form-group text-right">
                            <label for="title">العنوان</label>
                            <input id="title" type="text" name="title" class="form-control" value="{{ old('title', $slider->title) }}">
                        </div>

                        <div class="form-group text-right">
                            <label for="description">الوصف</label>
                            <textarea id="description" name="description" rows="4" class="form-control">{{ old('description', $slider->description) }}</textarea>
                        </div>

                        <div class="form-group text-right">
                            <label for="image">الصورة</label>
                            @if($slider->image)
                                <div class="mb-2 text-right"><img src="{{ asset('storage/'.$slider->image) }}" class="img-thumbnail" style="height:140px;" alt="preview"></div>
                            @endif
                            <input id="image" type="file" name="image" accept="image/*" class="form-control-file">
                        </div>

                        <div class="form-row align-items-center">
                            <div class="form-group col-md-3 text-right">
                                    <label for="order">الترتيب</label>
                                    <input id="order" type="number" name="order" class="form-control form-control-sm text-center" style="width:110px;" value="{{ old('order', $slider->order) }}">
                                </div>
                                <div class="form-group col-md-3 text-right d-flex align-items-center">
                                    <div class="custom-control custom-switch mb-0">
                                        <input type="checkbox" class="custom-control-input" id="isActive" name="is_active" value="1" {{ $slider->is_active ? 'checked' : '' }}>
                                        <label class="custom-control-label" for="isActive">نشط</label>
                                    </div>
                                </div>
                        </div>

                        <div class="mt-4 d-flex justify-content-end">
                            <a href="{{ route('admin.sliders.index') }}" class="btn btn-light ml-2">إلغاء</a>
                            <button class="btn btn-primary">تحديث</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection
