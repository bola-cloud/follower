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
                <label class="form-label">التعليقات</label>
                <div id="comments-container">
                    <!-- Fields will be generated dynamically -->
                    @if(old('comments') && is_array(old('comments')))
                        @foreach(old('comments') as $comment)
                            <div class="input-group mb-2 comment-input-group">
                                <input type="text" name="comments[]" class="form-control" placeholder="اكتب التعليق هنا..." value="{{ $comment }}" required>
                            </div>
                        @endforeach
                    @endif
                </div>
                <!-- Manual buttons removed as requested -->
                
                <div class="form-text">سيتم تدوير التعليقات (Round-Robin) إذا لم يتم ملء جميع الحقول أو إذا كان العدد كبيرًا جدًا.</div>
                @error('comments')
                    <div class="text-danger mt-1">{{ $message }}</div>
                @enderror
                @error('comments.*')
                    <div class="text-danger mt-1">{{ $message }}</div>
                @enderror
            </div>

            <script>
                function toggleCommentsField() {
                    var type = document.getElementById('type').value;
                    var commentsField = document.getElementById('comments-field');
                    var inputs = commentsField.querySelectorAll('input');
                    
                    if (type === 'comment') {
                        commentsField.style.display = 'block';
                        inputs.forEach(input => input.disabled = false);
                        syncCommentFields(); // Sync on toggle
                    } else {
                        commentsField.style.display = 'none';
                        inputs.forEach(input => input.disabled = true);
                    }
                }

                function syncCommentFields() {
                    var totalCountInput = document.getElementById('total_count');
                    var count = parseInt(totalCountInput.value) || 0;
                    var container = document.getElementById('comments-container');
                    var currentFields = container.getElementsByClassName('comment-input-group');
                    var currentCount = currentFields.length;
                    
                    // Safety cap to prevent browser crash
                    if (count > 100) {
                        // Optional: You might want to warn the user or cap it
                        // For now accepting exactly what user asked
                    }

                    if (count > currentCount) {
                        // Add fields
                        for (var i = 0; i < (count - currentCount); i++) {
                            var div = document.createElement('div');
                            div.className = 'input-group mb-2 comment-input-group';
                            div.innerHTML = `<input type="text" name="comments[]" class="form-control" placeholder="اكتب التعليق هنا..." required>`;
                            container.appendChild(div);
                        }
                    } else if (count < currentCount) {
                        // Remove fields from the bottom
                        for (var i = 0; i < (currentCount - count); i++) {
                            container.removeChild(currentFields[currentFields.length - 1]);
                        }
                    }
                }

                document.addEventListener('DOMContentLoaded', function () {
                    toggleCommentsField();
                    
                    // Listen for changes on total_count
                    document.getElementById('total_count').addEventListener('input', function() {
                        if (document.getElementById('type').value === 'comment') {
                            syncCommentFields();
                        }
                    });
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