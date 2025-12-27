
@extends('layouts.reactx')

@section('title','Create Article')

@section('content')
  <div class="max-w-4xl">
    <div class="mb-6">
      <h1 class="text-3xl font-bold text-gray-900 dark:text-white mb-2">Create Article</h1>
      <p class="text-gray-600 dark:text-gray-400">Create and publish site articles</p>
    </div>

    @if($errors->any())
      <div class="mb-4 p-3 bg-red-100 text-red-800 rounded">
        <ul class="list-disc list-inside">
          @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
          @endforeach
        </ul>
      </div>
    @endif

    <form action="{{ route('admin.articles.store') }}" method="POST" enctype="multipart/form-data" class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-8 space-y-6">
      @csrf
      <div>
        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-200 mb-2">Title</label>
        <input type="text" name="title" value="{{ old('title') }}" class="w-full px-4 py-3 rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-primary-500" required>
      </div>

      <div>
        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-200 mb-2">Feature Image</label>
        <div>
          <input id="image" type="file" name="image" accept="image/*" class="w-full px-4 py-3 rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500">
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">Optional. Recommended size: 1200x630</p>
        <div id="image-preview" class="mt-3">
          @if(old('image'))
            <img src="{{ old('image') }}" class="h-32 rounded" alt="preview">
          @endif
        </div>
      </div>

      {{-- Excerpt removed per request --}}

      <div>
        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-200 mb-2">Content</label>
        <input type="hidden" name="content" id="content">
        <div id="quill-editor" class="bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-xl" style="min-height: 320px">{!! old('content') !!}</div>
      </div>

      <div class="mb-4 flex items-center gap-3">
        <label class="flex items-center gap-2">
          <input type="checkbox" name="is_published" value="1"> Publish now
        </label>
      </div>

      <div class="flex gap-4 pt-4">
        <button class="px-6 py-3 bg-gradient-to-r from-primary-500 to-pink-500 text-white font-semibold rounded-xl hover:shadow-lg transition-shadow">Save Article</button>
        <a href="{{ route('admin.articles.index') }}" class="px-6 py-3 bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white font-semibold rounded-xl hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">Cancel</a>
      </div>
    </form>
  </div>

  {{-- TinyMCE WYSIWYG --}}
  <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
  <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      // Initialize Quill
      var quill = new Quill('#quill-editor', {
        theme: 'snow',
        modules: {
          toolbar: [['bold', 'italic', 'underline'], [{ 'header': [1, 2, 3, false] }], ['link', 'image', 'code-block'], [{ 'list': 'ordered'}, { 'list': 'bullet' }]]
        }
      });

      // Load initial content (if any) safely
      var initial = @json(old('content', ''));
      if (initial) {
        quill.clipboard.dangerouslyPasteHTML(initial);
      }

      // On submit, copy html to hidden textarea
      var form = document.querySelector('form');
      form.addEventListener('submit', function (e) {
        var html = quill.root.innerHTML;
        document.getElementById('content').value = html;
      });
    });
  </script>

  <script>
    // Preview selected image file in the preview container
    document.addEventListener('DOMContentLoaded', function () {
      var fileInput = document.getElementById('image');
      var preview = document.getElementById('image-preview');
      if (fileInput) {
        fileInput.addEventListener('change', function (e) {
          var file = e.target.files && e.target.files[0];
          if (!file) return;
          var reader = new FileReader();
          reader.onload = function (ev) {
            preview.innerHTML = '';
            var img = document.createElement('img');
            img.src = ev.target.result;
            img.className = 'h-32 rounded';
            preview.appendChild(img);
          };
          reader.readAsDataURL(file);
        });
      }
    });
  </script>

@endsection
