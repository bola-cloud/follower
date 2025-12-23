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
                    'points_per_ads' => 'النقاط لكل إعلان',
                    'ads_per_user_per_day' => 'الإعلانات المسموح بها لكل مستخدم يومياً',
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

        <hr />

        <div class="row mt-3">
            <div class="col-md-12 mb-4">
                <div class="card border-info shadow-sm">
                    <div class="card-body">
                        <label for="preferred_cookie_user_id" class="font-weight-bold mb-2 d-block">اختر حساب الكوكيز المفضل (سيستخدمه النظام أولاً)</label>
                        @php
                            $cookieUsers = \App\Models\User::whereNotNull('cookies')->get();
                        @endphp
                        <select name="preferred_cookie_user_id" id="preferred_cookie_user_id" class="form-control">
                            <option value="__none__" {{ setting('preferred_cookie_user_id') === '__none__' ? 'selected' : '' }}>لا تستخدم الكوكيز</option>
                            <option value="" {{ setting('preferred_cookie_user_id') === '' ? 'selected' : '' }}>-- لا شيء -- (سيتم اختيار حساب عشوائي من المتوفرين)</option>
                            @foreach($cookieUsers as $cu)
                                <option value="{{ $cu->id }}" {{ (string)setting('preferred_cookie_user_id') === (string)$cu->id ? 'selected' : '' }}>
                                    {{ $cu->name }} (ID: {{ $cu->id }})
                                </option>
                            @endforeach
                        </select>
                        <small class="form-text text-muted">اختر هنا حساب واحد سيُستعمل دائماً لطلبات Instagram عندما تكون كوكيز متوفرة.</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="text-center mt-4">
            <button type="submit" class="btn btn-success btn-lg px-5 py-2">
                💾 حفظ التعديلات
            </button>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
    $(function(){
        $('#preferred_cookie_user_id').select2({
            width: '100%',
            placeholder: 'اختر حساب الكوكيز أو لا تستخدم الكوكيز'
        });
    });
</script>
@endpush
