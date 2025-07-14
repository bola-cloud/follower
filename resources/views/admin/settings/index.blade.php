@extends('layouts.admin')

@section('content')
<div class="container py-4">
    <h2 class="text-center mb-5 font-weight-bold">⚙️ إعدادات التطبيق</h2>

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
                    'mandatory' => 'هل التحديث إلزامي؟',
                ];
            @endphp

            @foreach($settings as $key => $label)
                <div class="col-md-6 mb-4">
                    <div class="card border-primary shadow-sm">
                        <div class="card-body">
                            <label for="{{ $key }}" class="font-weight-bold mb-2 d-block">{{ $label }}</label>

                            @if($key === 'mandatory')
                                <select name="{{ $key }}" id="{{ $key }}" class="form-control">
                                    <option value="1" {{ setting($key) == '1' ? 'selected' : '' }}>نعم</option>
                                    <option value="0" {{ setting($key) == '0' ? 'selected' : '' }}>لا</option>
                                </select>
                            @else
                                <input
                                    type="text"
                                    name="{{ $key }}"
                                    id="{{ $key }}"
                                    class="form-control"
                                    value="{{ setting($key) }}"
                                    required
                                >
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="text-center mt-4">
            <button type="submit" class="btn btn-success btn-lg px-5 py-2">
                💾 حفظ التعديلات
            </button>
        </div>
    </form>
</div>
@endsection
