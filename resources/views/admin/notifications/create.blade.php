@extends('layouts.admin')

@section('content')
    <div class="container-fluid py-4" dir="rtl">
        <div class="row justify-content-center">
            <div class="col-md-10 col-lg-8">
                <div class="card shadow-lg border-0 rounded-lg">
                    <div class="card-header bg-danger text-white text-center py-3">
                        <h3 class="mb-0 font-weight-bold">
                            <i class="la la-bell mr-2"></i> إنشاء إشعار جديد
                        </h3>
                        <p class="mb-0 text-white-50 mt-1 small">أرسل إشعاراً لجميع المستخدمين على تطبيقاتهم</p>
                    </div>
                    <div class="card-body p-4">
                        <form action="{{ route('admin.notifications.store') }}" method="POST">
                            @csrf

                            <!-- Title Field -->
                            <div class="form-group text-right">
                                <label for="title" class="font-weight-bold text-dark">عنوان الإشعار <span
                                        class="text-danger">*</span></label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-light border-right-0"><i
                                                class="la la-header text-danger"></i></span>
                                    </div>
                                    <input id="title" type="text" name="title" class="form-control border-left-0"
                                        placeholder="أدخل عنواناً جذاباً" required>
                                </div>
                            </div>

                            <!-- Body Field -->
                            <div class="form-group text-right">
                                <label for="body" class="font-weight-bold text-dark">نص الإشعار</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-light border-right-0"><i
                                                class="la la-file-text text-danger"></i></span>
                                    </div>
                                    <textarea id="body" name="body" rows="5" class="form-control border-left-0"
                                        placeholder="اكتب تفاصيل الإشعار هنا..."></textarea>
                                </div>
                            </div>

                            <hr class="my-4">

                            <!-- Buttons -->
                            <div class="d-flex justify-content-between align-items-center">
                                <a href="{{ route('admin.notifications.index') }}"
                                    class="btn btn-outline-secondary btn-lg px-4">
                                    <i class="la la-times"></i> إلغاء
                                </a>
                                <button class="btn btn-danger btn-lg px-5 shadow-sm">
                                    <i class="la la-paper-plane"></i> إرسال الإشعار
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection