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

    <form action="{{ route('admin.articles.update', $article) }}" method="POST" enctype="multipart/form-data">
      @csrf
      @method('PUT')

      <div class="mb-4">
        <label class="block text-sm font-medium mb-1">Title</label>
        <input type="text" name="title" value="{{ old('title', $article->title) }}" class="w-full px-4 py-2 rounded border" required>
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
          <input id="image" type="file" name="image" accept="image/*" class="w-full px-3 py-2 rounded border">
        </div>
        <p class="text-xs text-gray-500">Upload to replace existing image (optional)</p>
      </div>

      <div class="mb-4">
        <label class="block text-sm font-medium mb-1">Content</label>
        <input type="hidden" name="content" id="content">
        <div id="quill-editor" class="bg-white border rounded" style="min-height: 320px">{!! old('content', $article->content) !!}</div>
      </div>

      <div class="mb-4 flex items-center gap-3">
        <label class="flex items-center gap-2">
          <input type="checkbox" name="is_published" value="1" {{ old('is_published', $article->is_published) ? 'checked' : '' }}> Publish now
        </label>
      </div>

      <div>
        <button class="px-4 py-2 bg-primary-500 text-white rounded">Update Article</button>
        <a href="{{ route('admin.articles.index') }}" class="ml-2 px-4 py-2 rounded bg-gray-100">Cancel</a>
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
