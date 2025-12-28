@extends('layouts.admin')

@section('content')
    <div class="container py-4" dir="rtl">
        <div class="card shadow-sm">
            <div class="card-header bg-white border-0">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="text-right">
                        <h4 class="mb-0">إنشاء إشعار</h4>
                        <small class="text-muted">أرسل إشعاراً لجميع المستخدمين (يتطلب مفتاح خادم FCM)</small>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <form action="{{ route('admin.notifications.store') }}" method="POST">
                    @csrf
                    <div class="row justify-content-center">
                        <div class="col-md-8">
                            <div class="form-group text-right">
                                <label>العنوان</label>
                                <input type="text" name="title" class="form-control" required>
                            </div>

                            <div class="form-group text-right">
                                <label>النص</label>
                                <textarea name="body" rows="4" class="form-control"></textarea>
                            </div>

                            <div class="mt-4 d-flex justify-content-end">
                                <a href="{{ route('admin.notifications.index') }}" class="btn btn-light ml-2">إلغاء</a>
                                <button class="btn btn-primary">إرسال</button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

@endsection