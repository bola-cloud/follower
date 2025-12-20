@extends('layouts.reactx')

@section('title', 'Settings')

@section('content')
<div class="max-w-4xl">
    <!-- Header -->
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-900 dark:text-white mb-2">Settings</h1>
        <p class="text-gray-600 dark:text-gray-400">Manage your app name, description, SEO settings, and logo</p>
    </div>

    <!-- Success Message -->
    @if (session('success'))
        <div class="mb-6 p-4 bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-700 rounded-xl text-green-800 dark:text-green-200">
            {{ session('success') }}
        </div>
    @endif

    <!-- Error Messages -->
    @if ($errors->any())
        <div class="mb-6 p-4 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-700 rounded-xl text-red-800 dark:text-red-200">
            <ul class="list-disc list-inside space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Settings Form -->
    <form action="{{ route('admin.settings.update') }}" method="POST" enctype="multipart/form-data" class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-8 space-y-6">
        @csrf

        <!-- App Name -->
        <div>
            <label for="app_name" class="block text-sm font-semibold text-gray-700 dark:text-gray-200 mb-2">
                App Name
            </label>
            <input
                type="text"
                id="app_name"
                name="app_name"
                value="{{ old('app_name', $settings['app_name']) }}"
                required
                class="w-full px-4 py-3 rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-primary-500"
                placeholder="e.g., ReactX"
            >
        </div>

        <!-- App Description -->
        <div>
            <label for="app_description" class="block text-sm font-semibold text-gray-700 dark:text-gray-200 mb-2">
                App Description
            </label>
            <textarea
                id="app_description"
                name="app_description"
                rows="4"
                class="w-full px-4 py-3 rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-primary-500"
                placeholder="Brief description of your app..."
            >{{ old('app_description', $settings['app_description']) }}</textarea>
        </div>

        <!-- SEO Title -->
        <div>
            <label for="seo_title" class="block text-sm font-semibold text-gray-700 dark:text-gray-200 mb-2">
                SEO Title
            </label>
            <input
                type="text"
                id="seo_title"
                name="seo_title"
                value="{{ old('seo_title', $settings['seo_title']) }}"
                required
                class="w-full px-4 py-3 rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-primary-500"
                placeholder="Page title for search engines..."
            >
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Max 60 characters for best results</p>
        </div>

        <!-- SEO Description -->
        <div>
            <label for="seo_description" class="block text-sm font-semibold text-gray-700 dark:text-gray-200 mb-2">
                SEO Description
            </label>
            <textarea
                id="seo_description"
                name="seo_description"
                rows="3"
                required
                class="w-full px-4 py-3 rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-primary-500"
                placeholder="Meta description for search engines..."
            >{{ old('seo_description', $settings['seo_description']) }}</textarea>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Max 160 characters for best results</p>
        </div>

        <!-- SEO Keywords -->
        <div>
            <label for="seo_keywords" class="block text-sm font-semibold text-gray-700 dark:text-gray-200 mb-2">
                SEO Keywords
            </label>
            <input
                type="text"
                id="seo_keywords"
                name="seo_keywords"
                value="{{ old('seo_keywords', $settings['seo_keywords']) }}"
                class="w-full px-4 py-3 rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-primary-500"
                placeholder="Comma separated keywords..."
            >
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Separate keywords with commas</p>
        </div>

        <!-- App Logo -->
        <div>
            <label for="app_logo" class="block text-sm font-semibold text-gray-700 dark:text-gray-200 mb-2">
                App Logo
            </label>

            @if($settings['app_logo'])
                <div class="mb-4">
                    <div class="flex items-center gap-4">
                        <img
                            src="{{ asset('storage/' . $settings['app_logo']) }}"
                            alt="Current Logo"
                            class="w-16 h-16 rounded-lg object-cover border border-gray-200 dark:border-gray-600"
                        >
                        <div>
                            <p class="text-sm text-gray-600 dark:text-gray-400">Current logo</p>
                            <p class="text-xs text-gray-500 dark:text-gray-500">Upload a new image to replace</p>
                        </div>
                    </div>
                </div>
            @endif

            <div class="relative">
                <input
                    type="file"
                    id="app_logo"
                    name="app_logo"
                    accept="image/*"
                    class="w-full px-4 py-3 rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500"
                >
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Supported formats: JPEG, PNG, GIF, WebP (Max 2MB)</p>
            </div>
        </div>

        <!-- Submit Button -->
        <div class="flex gap-4 pt-4">
            <button
                type="submit"
                class="px-6 py-3 bg-gradient-to-r from-primary-500 to-pink-500 text-white font-semibold rounded-xl hover:shadow-lg transition-shadow"
            >
                Save Settings
            </button>
            <a
                href="{{ route('admin.reactx.dashboard') }}"
                class="px-6 py-3 bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white font-semibold rounded-xl hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
            >
                Cancel
            </a>
        </div>
    </form>
</div>
@endsection
