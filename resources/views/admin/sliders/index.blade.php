@extends('layouts.admin')

@section('title','Sliders')

@section('content')
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="mb-0">شريط التمرير</h1>
            <p class="text-muted mb-0">إدارة شرائح الصفحة الرئيسية</p>
        </div>
        <div>
            <a href="{{ route('admin.sliders.create') }}" class="btn btn-primary">إضافة شريحة</a>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-striped mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>العنوان</th>
                            <th>نشط</th>
                            <th>الترتيب</th>
                            <th class="text-right">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($sliders as $slider)
                            <tr>
                                <td>{{ $slider->title }}</td>
                                <td>
                                    <form id="toggle-form-{{ $slider->id }}" action="{{ route('admin.sliders.toggle', $slider) }}" method="POST" style="display:inline-block">
                                        @csrf
                                        <div class="custom-control custom-switch">
                                            <input type="checkbox" class="custom-control-input" id="switch-{{ $slider->id }}" onchange="document.getElementById('toggle-form-{{ $slider->id }}').submit()" {{ $slider->is_active ? 'checked' : '' }}>
                                            <label class="custom-control-label" for="switch-{{ $slider->id }}"></label>
                                        </div>
                                    </form>
                                </td>
                                <td>
                                    <form action="{{ route('admin.sliders.order', $slider) }}" method="POST" class="form-inline">
                                        @csrf
                                        <input type="number" name="order" value="{{ $slider->order }}" class="form-control form-control-sm text-center" style="width:90px">
                                        <button class="btn btn-sm btn-outline-primary ml-2">حفظ</button>
                                    </form>
                                </td>
                                <td class="text-right">
                                    <a href="{{ route('admin.sliders.edit', $slider) }}" class="btn btn-sm btn-warning">تعديل</a>
                                    <form action="{{ route('admin.sliders.destroy', $slider) }}" method="POST" class="d-inline" onsubmit="return confirm('هل أنت متأكد من الحذف؟')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-danger">حذف</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-muted">لا توجد شرائح بعد</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer">
            {{ $sliders->links() }}
        </div>
    </div>
</div>

@endsection
