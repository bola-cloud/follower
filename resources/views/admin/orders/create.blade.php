@extends('layouts.admin')

@section('content')
    <div class="container py-4">
        <h1 class="mb-4">إضافة طلب جديد</h1>

        <!-- Display all validation errors -->
        @if($errors->any())
            <div class="alert alert-danger">
                <ul>
                    @foreach($errors->all() as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if(session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif

        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        <!-- Form for adding a new order -->
        <form action="{{ route('admin.orders.store') }}" method="POST">
            @csrf

            <!-- Type field -->
            <div class="mb-3">
                <label for="type" class="form-label">نوع الطلب</label>
                <select name="type" id="type" class="form-control @error('type') is-invalid @enderror" required
                    onchange="toggleCommentsField()">
                    <option value="" disabled {{ old('type') ? '' : 'selected' }}>اختر نوع الطلب</option>
                    <option value="follow" {{ old('type') == 'follow' ? 'selected' : '' }}>متابعة</option>
                    <option value="like" {{ old('type') == 'like' ? 'selected' : '' }}>إعجاب</option>
                    <option value="comment" {{ old('type') == 'comment' ? 'selected' : '' }}>تعليق</option>
                </select>
                @error('type')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <!-- Comments field (Hidden by default) -->
            <div class="mb-3" id="comments-field" style="display: none;">
                <label for="comments" class="form-label">التعليقات (كل تعليق في سطر)</label>
                <textarea name="comments" id="comments" class="form-control @error('comments') is-invalid @enderror"
                    rows="5" placeholder="اكتب التعليقات هنا...">{{ old('comments') }}</textarea>
                @error('comments')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <script>
                function toggleCommentsField() {
                    var type = document.getElementById('type').value;
                    var commentsField = document.getElementById('comments-field');
                    if (type === 'comment') {
                        commentsField.style.display = 'block';
                    } else {
                        commentsField.style.display = 'none';
                    }
                }
                // Run on load in case of old input
                document.addEventListener('DOMContentLoaded', function () {
                    toggleCommentsField();
                });
            </script>

            <!-- Total Count field -->
            <div class="mb-3">
                <label for="total_count" class="form-label">العدد الإجمالي</label>
                <input type="number" name="total_count" id="total_count"
                    class="form-control @error('total_count') is-invalid @enderror" required min="1"
                    value="{{ old('total_count') }}">
                @error('total_count')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <!-- Target URL field -->
            <div class="mb-3">
                <label for="target_url" class="form-label">الرابط المستهدف</label>
                <input type="url" name="target_url" id="target_url"
                    class="form-control @error('target_url') is-invalid @enderror" required value="{{ old('target_url') }}">
                @error('target_url')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <!-- Submit button -->
            <button type="submit" class="btn btn-primary">إنشاء الطلب</button>
        </form>
    </div>
@endsection