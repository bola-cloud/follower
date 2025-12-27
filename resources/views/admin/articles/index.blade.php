
@extends('layouts.reactx')

@section('title','Articles')

@section('content')
  <div class="mb-6 flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Articles</h1>
      <p class="text-gray-600 dark:text-gray-400">Manage site articles</p>
    </div>
    <div>
      <a href="{{ route('admin.articles.create') }}" class="px-4 py-2 bg-primary-500 text-white rounded-lg">New Article</a>
    </div>
  </div>

  @if(session('success'))
    <div class="mb-4 p-3 bg-green-100 text-green-800 rounded">{{ session('success') }}</div>
  @endif

  <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full">
        <thead class="bg-gray-50 dark:bg-gray-700/50">
          <tr>
            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500">Title</th>
            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500">Published</th>
            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500">Created</th>
            <th class="px-6 py-3"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
          @forelse($articles as $article)
            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
              <td class="px-6 py-4">{{ $article->title }}</td>
              <td class="px-6 py-4">@if($article->is_published) <span class="text-green-600">Yes</span> @else <span class="text-gray-500">No</span> @endif</td>
              <td class="px-6 py-4">{{ $article->created_at->format('Y-m-d') }}</td>
              <td class="px-6 py-4 text-right">
                <a href="{{ route('admin.articles.edit', $article) }}" class="px-3 py-1 rounded bg-gray-100 dark:bg-gray-700">Edit</a>
                <form action="{{ route('admin.articles.destroy', $article) }}" method="POST" style="display:inline-block" onsubmit="return confirm('Delete this article?')">
                  @csrf
                  @method('DELETE')
                  <button type="submit" class="px-3 py-1 rounded bg-red-100 text-red-700">Delete</button>
                </form>
              </td>
            </tr>
          @empty
            <tr>
              <td class="px-6 py-4 text-center text-gray-500" colspan="4">No articles yet</td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <div class="p-4">
      {{ $articles->links() }}
    </div>
  </div>

@endsection
