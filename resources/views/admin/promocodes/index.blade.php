@extends('layouts.admin')

@section('content')
<div class="container py-4">
    <h2>إدارة الرموز الترويجية</h2>
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <form method="GET" class="form-inline my-3">
        <input type="text" name="search" class="form-control mr-2" placeholder="كود الترويج" value="{{ request('search') }}">
        <select name="status" class="form-control mr-2">
            <option value="">-- الحالة --</option>
            <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>نشط</option>
            <option value="used" {{ request('status') == 'used' ? 'selected' : '' }}>مستخدم</option>
            <option value="expired" {{ request('status') == 'expired' ? 'selected' : '' }}>منتهي</option>
        </select>
        <button class="btn btn-primary">بحث</button>
        <a href="{{ route('admin.promocodes.create') }}" class="btn btn-success ml-auto">إضافة رمز</a>
    </form>

    <form method="POST" action="{{ route('admin.promocodes.bulkDelete') }}" onsubmit="return confirm('هل أنت متأكد من حذف الرموز المحددة؟')">
        @csrf
        @method('DELETE')

        <div class="mb-2">
            <button type="submit" class="btn btn-danger">حذف المحدد</button>
        </div>

        <table class="table table-bordered">
            <thead>
                <tr>
                    <th><input type="checkbox" id="select-all"></th>
                    <th>الكود</th>
                    <th>النقاط</th>
                    <th>تاريخ الانتهاء</th>
                    <th>مستخدم بواسطة</th>
                    <th>تم التفعيل</th>
                    <th>تاريخ الإنشاء</th>
                    <th>الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                @forelse($promocodes as $promo)
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="{{ $promo->id }}"></td>
                        <td>{{ $promo->code }}</td>
                        <td>{{ $promo->points }}</td>
                        <td>{{ $promo->expires_at ? \Carbon\Carbon::parse($promo->expires_at)->format('Y-m-d') : '-' }}</td>
                        <td>{{ $promo->user ? $promo->user->name : '-' }}</td>
                        <td>{{ $promo->activated_at ? \Carbon\Carbon::parse($promo->activated_at)->format('Y-m-d H:i') : '-' }}</td>
                        <td>{{ $promo->created_at->format('Y-m-d H:i') }}</td>
                        <td>
                            <a href="{{ route('admin.promocodes.edit', $promo->id) }}" class="btn btn-sm btn-primary">تعديل</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center">لا توجد رموز</td></tr>
                @endforelse
            </tbody>
        </table>
    </form>

    <div class="mt-3">{{ $promocodes->links('pagination::bootstrap-4') }}</div>
</div>

<script>
    // Select/Deselect all checkboxes
    document.getElementById('select-all').addEventListener('change', function() {
        const checked = this.checked;
        document.querySelectorAll('input[name="ids[]"]').forEach(cb => cb.checked = checked);
    });
</script>
@endsection
