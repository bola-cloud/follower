@extends('layouts.admin')

@section('content')
<div class="container py-5">
    <h2 class="text-center mb-5 fw-bold">⚙️ إعدادات التطبيق</h2>

    @if(session('success'))
        <div class="alert alert-success text-center">
            {{ session('success') }}
        </div>
    @endif

    <form method="POST" action="{{ route('admin.settings.update') }}">
        @csrf

        <div class="row g-4">
            @php
                $settings = [
                    'points_per_follow' => 'النقاط لكل متابعة',
                    'points_per_like' => 'النقاط لكل إعجاب',
                    'added_points' => 'النقاط المضافة عند التسجيل',
                    'app_version' => 'إصدار التطبيق',
                    'build_number' => 'رقم البناء',
                    'download_link' => 'رابط التحميل',
                    'mandatory' => 'هل التحديث إلزامي؟',
                ];
            @endphp

            @foreach($settings as $key => $label)
                <div class="col-md-6">
                    <div class="form-floating border rounded shadow-sm">
                        @if($key === 'mandatory')
                            <select class="form-select" name="{{ $key }}" id="{{ $key }}">
                                <option value="1" {{ setting($key) == '1' ? 'selected' : '' }}>نعم</option>
                                <option value="0" {{ setting($key) == '0' ? 'selected' : '' }}>لا</option>
                            </select>
                        @else
                            <input
                                type="text"
                                class="form-control"
                                name="{{ $key }}"
                                id="{{ $key }}"
                                value="{{ setting($key) }}"
                                placeholder="{{ $label }}"
                                required
                            >
                        @endif
                        <label for="{{ $key }}" class="text-muted fw-bold">{{ $label }}</label>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="text-center mt-5">
            <button type="submit" class="btn btn-primary btn-lg px-5 py-2 shadow-sm">
                💾 حفظ التعديلات
            </button>
        </div>
    </form>
</div>
@endsection
