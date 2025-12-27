@extends('layouts.app')

@section('title', $article->title)

@section('content')
<div class="container mx-auto px-4 py-16">
  <div class="max-w-4xl mx-auto bg-white rounded-3xl shadow-lg overflow-hidden">
    @if($image)
      <div class="w-full h-64 overflow-hidden">
        <img src="{{ $image }}" alt="{{ $article->title }}" class="w-full h-full object-cover">
      </div>
    @endif
    <div class="p-8">
      <div class="text-sm text-slate-500 mb-2">{{ $article->published_at ? $article->published_at->format('F j, Y') : $article->created_at->format('F j, Y') }}</div>
      <h1 class="text-3xl font-bold text-slate-900 mb-4">{{ $article->title }}</h1>
      <div class="prose max-w-none text-slate-700">{!! $article->content !!}</div>
      <div class="mt-8 flex items-center gap-4">
        <a href="/" class="text-sm text-slate-600 hover:text-slate-900">← Back to home</a>
      </div>
    </div>
  </div>
</div>
@endsection
