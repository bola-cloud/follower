
@extends('layouts.reactx')

@section('title','Create Article')

@section('content')
  <div class="max-w-3xl">
    <h1 class="text-2xl font-bold mb-4">Create Article</h1>

    @if($errors->any())
      <div class="mb-4 p-3 bg-red-100 text-red-800 rounded">
        <ul class="list-disc list-inside">
          @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
          @endforeach
        </ul>
      </div>
    @endif

    <form action="{{ route('admin.articles.store') }}" method="POST" enctype="multipart/form-data">
      @csrf
      <div class="mb-4">
        <label class="block text-sm font-medium mb-1">Title</label>
        <input type="text" name="title" value="{{ old('title') }}" class="w-full px-4 py-2 rounded border" required>
      </div>

      <div class="mb-4">
        <label class="block text-sm font-medium mb-1">Feature Image</label>
        <div>
          <input id="image" type="file" name="image" accept="image/*" class="w-full px-3 py-2 rounded border">
        </div>
        <p class="text-xs text-gray-500 mt-2">Optional. Recommended size: 1200x630</p>
        <div id="image-preview" class="mt-3">
          @if(old('image'))
            <img src="{{ old('image') }}" class="h-32 rounded" alt="preview">
          @endif
        </div>
      </div>

      {{-- Excerpt removed per request --}}

      <div class="mb-4">
        <label class="block text-sm font-medium mb-1">Content</label>
        <input type="hidden" name="content" id="content">
        <div id="quill-editor" class="bg-white border rounded" style="min-height: 320px">{!! old('content') !!}</div>
      </div>

      <div class="mb-4 flex items-center gap-3">
        <label class="flex items-center gap-2">
          <input type="checkbox" name="is_published" value="1"> Publish now
        </label>
      </div>

      <div>
        <button class="px-4 py-2 bg-primary-500 text-white rounded">Save Article</button>
        <a href="{{ route('admin.articles.index') }}" class="ml-2 px-4 py-2 rounded bg-gray-100">Cancel</a>
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
