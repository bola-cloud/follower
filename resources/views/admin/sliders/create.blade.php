@extends('layouts.admin')

@section('title','Create Slider')

@section('content')
<div class="max-w-4xl">
  <div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-900 dark:text-white mb-2">Create Slider</h1>
    <p class="text-gray-600 dark:text-gray-400">Add a new homepage slider</p>
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

  <form action="{{ route('admin.sliders.store') }}" method="POST" enctype="multipart/form-data" class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-8 space-y-6">
    @csrf
    <div>
      <label class="block text-sm font-semibold text-gray-700 dark:text-gray-200 mb-2">Title</label>
      <input type="text" name="title" class="w-full px-4 py-3 rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white" value="{{ old('title') }}">
    </div>

    <div>
      <label class="block text-sm font-semibold text-gray-700 dark:text-gray-200 mb-2">Description</label>
      <textarea name="description" rows="3" class="w-full px-4 py-3 rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white">{{ old('description') }}</textarea>
    </div>

    <div>
      <label class="block text-sm font-semibold text-gray-700 dark:text-gray-200 mb-2">Image</label>
      <input type="file" name="image" accept="image/*" class="w-full px-4 py-3 rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
    </div>

    <div class="flex gap-4">
      <div>
        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-200 mb-2">Order</label>
        <input type="number" name="order" class="w-32 px-3 py-2 rounded-xl border" value="{{ old('order',0) }}">
      </div>
      <div class="flex items-end">
        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
          <input type="checkbox" name="is_active" value="1" checked>
          Active
        </label>
      </div>
    </div>

    <div class="flex gap-4 pt-4">
      <button class="px-6 py-3 bg-gradient-to-r from-primary-500 to-pink-500 text-white rounded-xl">Create</button>
      <a href="{{ route('admin.sliders.index') }}" class="px-6 py-3 bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white rounded-xl">Cancel</a>
    </div>
  </form>
</div>
@endsection
