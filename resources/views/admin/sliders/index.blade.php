@extends('layouts.admin')

@section('title','Sliders')

@section('content')
<div class="mb-6 flex items-center justify-between">
  <div>
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Sliders</h1>
    <p class="text-gray-600 dark:text-gray-400">Manage homepage sliders</p>
  </div>
  <div>
    <a href="{{ route('admin.sliders.create') }}" class="px-4 py-2 bg-gradient-to-r from-primary-500 to-pink-500 text-white rounded-lg">New Slider</a>
  </div>
</div>

@if(session('success'))
  <div class="mb-4 p-3 bg-green-100 text-green-800 rounded">{{ session('success') }}</div>
@endif

<div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden">
  <div class="p-4">
    <table class="w-full">
      <thead class="bg-gray-50 dark:bg-gray-700/50">
        <tr>
          <th class="px-4 py-2 text-left text-xs text-gray-500">Title</th>
          <th class="px-4 py-2 text-left text-xs text-gray-500">Active</th>
          <th class="px-4 py-2 text-left text-xs text-gray-500">Order</th>
          <th class="px-4 py-2"></th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
        @forelse($sliders as $slider)
          <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
            <td class="px-4 py-3">{{ $slider->title }}</td>
            <td class="px-4 py-3">{{ $slider->is_active ? 'Yes' : 'No' }}</td>
            <td class="px-4 py-3">{{ $slider->order }}</td>
            <td class="px-4 py-3 text-right">
              <a href="{{ route('admin.sliders.edit', $slider) }}" class="px-3 py-1 rounded bg-gray-100 dark:bg-gray-700">Edit</a>
              <form action="{{ route('admin.sliders.destroy', $slider) }}" method="POST" style="display:inline-block" onsubmit="return confirm('Delete slider?')">
                @csrf
                @method('DELETE')
                <button type="submit" class="px-3 py-1 rounded bg-red-100 text-red-700">Delete</button>
              </form>
            </td>
          </tr>
        @empty
          <tr>
            <td class="px-4 py-6 text-center text-gray-500" colspan="4">No sliders yet</td>
          </tr>
        @endforelse
      </tbody>
    </table>

    <div class="mt-4">
      {{ $sliders->links() }}
    </div>
  </div>
</div>

@endsection
