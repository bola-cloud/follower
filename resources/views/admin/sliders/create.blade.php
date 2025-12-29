@extends('layouts.admin')

@section('title', 'Create Slider')

@section('content')
    <div class="container-fluid py-4" dir="rtl">
        <div class="row justify-content-center">
            <div class="col-md-10 col-lg-8">
                <div class="card shadow-lg border-0 rounded-lg">
                    <div class="card-header bg-primary text-white text-center py-3">
                        <h3 class="mb-0 font-weight-bold">
                            <i class="la la-image mr-2"></i> إضافة شريحة جديدة
                        </h3>
                        <p class="mb-0 text-white-50 mt-1 small">أنشئ شريحة جديدة لواجهة الصفحة الرئيسية</p>
                    </div>
                    <div class="card-body p-4">
                        @if($errors->any())
                            <div class="alert alert-danger shadow-sm border-left-danger">
                                <ul class="mb-0 pl-3">
                                    @foreach($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <form action="{{ route('admin.sliders.store') }}" method="POST" enctype="multipart/form-data">
                            @csrf

                            <!-- Title & Order Row -->
                            <div class="form-row">
                                <div class="form-group col-md-8 text-right">
                                    <label for="title" class="font-weight-bold text-dark">العنوان <span
                                            class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text bg-light border-right-0"><i
                                                    class="la la-header text-primary"></i></span>
                                        </div>
                                        <input id="title" type="text" name="title" class="form-control border-left-0"
                                            placeholder="أدخل عنوان الشريحة" value="{{ old('title') }}" required>
                                    </div>
                                </div>
                                <div class="form-group col-md-4 text-right">
                                    <label for="order" class="font-weight-bold text-dark">الترتيب</label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text bg-light border-right-0"><i
                                                    class="la la-sort-numeric-asc text-primary"></i></span>
                                        </div>
                                        <input id="order" type="number" name="order" class="form-control border-left-0"
                                            value="{{ old('order', 0) }}">
                                    </div>
                                </div>
                            </div>

                            <!-- Description -->
                            <div class="form-group text-right">
                                <label for="description" class="font-weight-bold text-dark">الوصف</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-light border-right-0"><i
                                                class="la la-align-left text-primary"></i></span>
                                    </div>
                                    <textarea id="description" name="description" rows="4"
                                        class="form-control border-left-0"
                                        placeholder="أدخل وصفاً مختصراً للشريحة (اختياري)">{{ old('description') }}</textarea>
                                </div>
                            </div>

                            <!-- Dates Row -->
                            <div class="form-row bg-light p-3 rounded mb-3 border">
                                <div class="form-group col-md-6 text-right">
                                    <label for="start_at" class="font-weight-bold">تاريخ البدء</label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text border-right-0"><i
                                                    class="la la-calendar-plus-o text-success"></i></span>
                                        </div>
                                        <input id="start_at" type="date" name="start_at" class="form-control border-left-0"
                                            value="{{ old('start_at') }}">
                                    </div>
                                </div>
                                <div class="form-group col-md-6 text-right">
                                    <label for="end_at" class="font-weight-bold">تاريخ الانتهاء</label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text border-right-0"><i
                                                    class="la la-calendar-times-o text-danger"></i></span>
                                        </div>
                                        <input id="end_at" type="date" name="end_at" class="form-control border-left-0"
                                            value="{{ old('end_at') }}">
                                    </div>
                                    <small class="form-text text-muted mt-2"><i class="la la-info-circle"></i> إذا اخترت
                                        تاريخاً، ستنتهي الشريحة عند بداية اليوم التالي له.</small>
                                </div>
                            </div>

                            <!-- Image Upload (Styled) -->
                            <div class="form-group text-right">
                                <label for="image" class="font-weight-bold text-dark">صورة الشريحة <span
                                        class="text-danger">*</span></label>
                                <div class="custom-file">
                                    <!-- Using simple styling for file input as custom-file can be tricky without JS -->
                                    <div class="p-3 border rounded text-center bg-white dashed-border"
                                        style="border: 2px dashed #ddd; background-color: #fafafa;">
                                        <i class="la la-cloud-upload text-muted" style="font-size: 3rem;"></i>
                                        <h6 class="mt-2 text-muted">اضغط لاختيار صورة من جهازك</h6>
                                        <input id="image" type="file" name="image" accept="image/*"
                                            class="form-control-file mt-2"
                                            style="position: absolute; top:0; left:0; width:100%; height:100%; opacity:0; cursor:pointer;">
                                    </div>
                                    <small class="text-muted">يقبل الصور فقط (JPG, PNG, GIF)</small>
                                </div>
                            </div>

                            <!-- Active Switch -->
                            <div class="form-group mt-4 text-right">
                                <div class="custom-control custom-switch custom-switch-lg pl-0">
                                    <input type="checkbox" class="custom-control-input" id="isActive" name="is_active"
                                        value="1" checked>
                                    <label class="custom-control-label font-weight-bold text-primary" for="isActive">تفعيل
                                        الشريحة مباشرة</label>
                                </div>
                            </div>

                            <hr class="my-4">

                            <!-- Buttons -->
                            <div class="d-flex justify-content-between align-items-center">
                                <a href="{{ route('admin.sliders.index') }}" class="btn btn-outline-secondary btn-lg px-4">
                                    <i class="la la-arrow-right"></i> إلغاء
                                </a>
                                <button class="btn btn-primary btn-lg px-5 shadow-sm">
                                    <i class="la la-check-circle"></i> إنشاء الشريحة
                                </button>
                            </div>

                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection