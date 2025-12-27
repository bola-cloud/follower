@extends('layouts.reactx')

@section('title','Edit Article')

@section('content')
  <div class="max-w-3xl">
    <h1 class="text-2xl font-bold mb-4">Edit Article</h1>

    @if($errors->any())
      <div class="mb-4 p-3 bg-red-100 text-red-800 rounded">
        <ul class="list-disc list-inside">
          @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
          @endforeach
        </ul>
      </div>
    @endif

    <form action="{{ route('admin.articles.update', $article) }}" method="POST" enctype="multipart/form-data" class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-8 space-y-6">
      @csrf
      @method('PUT')

      <div>
        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-200 mb-2">Title</label>
        <input type="text" name="title" value="{{ old('title', $article->title) }}" class="w-full px-4 py-3 rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-primary-500" required>
      </div>

      <div class="mb-4">
        <label class="block text-sm font-medium mb-1">Feature Image</label>
        @php
          $imgUrl = null;
          if ($article->image) {
              if (Str::startsWith($article->image, ['http://', 'https://'])) {
                  $imgUrl = $article->image;
              } elseif (Str::startsWith($article->image, ['/storage', 'storage'])) {
                  $imgUrl = asset(ltrim($article->image, '/'));
              } else {
                  $imgUrl = asset('storage/' . $article->image);
              }
          }
        @endphp
        @if($imgUrl)
          <div class="mb-2" id="image-preview">
            <img src="{{ $imgUrl }}" alt="current" class="w-48 h-auto rounded">
          </div>
        @else
          <div class="mb-2" id="image-preview"></div>
        @endif

        <div>
          <input id="image" type="file" name="image" accept="image/*" class="w-full px-4 py-3 rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500">
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400">Upload to replace existing image (optional)</p>
      </div>

      <div>
        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-200 mb-2">Content</label>
        <input type="hidden" name="content" id="content">
        <div id="quill-editor" class="bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-xl" style="min-height: 320px">{!! old('content', $article->content) !!}</div>
      </div>

      <div class="mb-4 flex items-center gap-3">
        <label class="flex items-center gap-2">
          <input type="checkbox" name="is_published" value="1" {{ old('is_published', $article->is_published) ? 'checked' : '' }}> Publish now
        </label>
      </div>

      <div class="flex gap-4 pt-4">
        <button class="px-6 py-3 bg-gradient-to-r from-primary-500 to-pink-500 text-white font-semibold rounded-xl hover:shadow-lg transition-shadow">Update Article</button>
        <a href="{{ route('admin.articles.index') }}" class="px-6 py-3 bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white font-semibold rounded-xl hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">Cancel</a>
      </div>
    </form>
  </div>

  <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
  <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      var quill = new Quill('#quill-editor', {
        theme: 'snow',
        modules: {
          toolbar: [['bold', 'italic', 'underline'], [{ 'header': [1, 2, 3, false] }], ['link', 'image', 'code-block'], [{ 'list': 'ordered'}, { 'list': 'bullet' }]]
        }
      });

      var initial = @json(old('content', $article->content));
      if (initial) {
        quill.clipboard.dangerouslyPasteHTML(initial);
      }

      var form = document.querySelector('form');
      form.addEventListener('submit', function (e) {
        var html = quill.root.innerHTML;
        document.getElementById('content').value = html;
      });

      // file input preview
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
            img.className = 'w-48 h-auto rounded';
            preview.appendChild(img);
          };
          reader.readAsDataURL(file);
        });
      }
    });
  </script>

@endsection
