@extends('layouts.admin')

@section('content')
<div class="container py-4">
    <h1 class="mb-4 text-center">إعدادات التطبيق</h1>

    @if(session('success'))
        <div class="alert alert-success text-center">
            {{ session('success') }}
        </div>
    @endif

    <form method="POST" action="{{ route('admin.settings.update') }}">
        @csrf

        <div class="row">
            @php
                $settings = [
                    'points_per_follow' => 'النقاط لكل متابعة',
                    'points_per_like' => 'النقاط لكل إعجاب',
                    'added_points' => 'النقاط المضافة عند التسجيل',
                    'app_version' => 'إصدار التطبيق',
                    'build_number' => 'رقم البناء',
                    'download_link' => 'رابط التحميل',
                    'mandatory' => 'التحديث إلزامي؟',
                ];
            @endphp

            @foreach($settings as $key => $label)
                <div class="col-md-6 mb-4">
                    <div class="card shadow-sm border-primary">
                        <div class="card-body">
                            <h5 class="card-title">{{ $label }}</h5>
                            @if($key === 'mandatory')
                                <select class="form-select" name="{{ $key }}">
                                    <option value="1" {{ setting($key) == '1' ? 'selected' : '' }}>نعم</option>
                                    <option value="0" {{ setting($key) == '0' ? 'selected' : '' }}>لا</option>
                                </select>
                            @else
                                <input
                                    type="text"
                                    class="form-control"
                                    name="{{ $key }}"
                                    value="{{ setting($key) }}"
                                    required
                                >
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="text-center">
            <button type="submit" class="btn btn-success btn-lg px-5">💾 حفظ التعديلات</button>
        </div>
    </form>
</div>
@endsection
