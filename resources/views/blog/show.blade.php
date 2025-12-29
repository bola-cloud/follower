<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $article->title }} - Egfollow</title>
    <meta name="description" content="{{ Str::limit(strip_tags($article->content), 160) }}">
    <link rel="icon" href="{{ asset('logo.jpeg') }}" type="image/jpeg">

    <link rel="canonical" href="{{ url()->current() }}">
    <meta name="keywords" content="instagram, growth, followers, likes, guide, tutorial, {{ $article->title }}">
    <meta name="author" content="Egfollow">

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="article">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:title" content="{{ $article->title }}">
    <meta property="og:description" content="{{ Str::limit(strip_tags($article->content), 160) }}">
    <meta property="og:site_name" content="Egfollow">
    <meta property="article:published_time" content="{{ $article->created_at->toIso8601String() }}">
    @if($article->image)
        <meta property="og:image" content="{{ preg_match('/^https?:\/\//', $article->image) ? $article->image : asset('storage/' . ltrim($article->image, '/')) }}">
    @else
        <meta property="og:image" content="{{ asset('logo.jpeg') }}">
    @endif

    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:url" content="{{ url()->current() }}">
    <meta name="twitter:title" content="{{ $article->title }}">
    <meta name="twitter:description" content="{{ Str::limit(strip_tags($article->content), 160) }}">
    @if($article->image)
        <meta name="twitter:image" content="{{ preg_match('/^https?:\/\//', $article->image) ? $article->image : asset('storage/' . ltrim($article->image, '/')) }}">
    @else
        <meta name="twitter:image" content="{{ asset('logo.jpeg') }}">
    @endif

    <!-- Schema.org JSON-LD -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "BlogPosting",
      "headline": "{{ $article->title }}",
      "image": [
        @if($article->image)
        "{{ preg_match('/^https?:\/\//', $article->image) ? $article->image : asset('storage/' . ltrim($article->image, '/')) }}"
        @else
        "{{ asset('logo.jpeg') }}"
        @endif
       ],
      "datePublished": "{{ $article->created_at->toIso8601String() }}",
      "dateModified": "{{ $article->updated_at->toIso8601String() }}",
      "author": {
        "@type": "Organization",
        "name": "Egfollow"
      },
      "publisher": {
        "@type": "Organization",
        "name": "Egfollow",
        "logo": {
          "@type": "ImageObject",
          "url": "{{ asset('logo.jpeg') }}"
        }
      },
      "description": "{{ Str::limit(strip_tags($article->content), 160) }}"
    }
    </script>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Outfit', 'sans-serif'],
                    },
                    colors: {
                        brand: {
                            purple: '#8B5CF6',
                            pink: '#EC4899',
                            blue: '#3B82F6',
                            dark: '#0F172A'
                        }
                    }
                }
            }
        }
    </script>

    <style>
        .glass-effect {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
        }

        .gradient-text {
            background: linear-gradient(to right, #8B5CF6, #EC4899);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
    </style>
</head>

<body class="font-sans text-slate-800 antialiased bg-slate-50 overflow-x-hidden">

    <!-- Header -->
    <header class="fixed w-full top-0 z-50 transition-all duration-300 border-b border-slate-200/50" id="navbar">
        <div class="absolute inset-0 glass-effect shadow-sm opacity-95"></div>
        <div class="container mx-auto px-4 py-4 relative">
            <div class="flex justify-between items-center">
                <!-- Logo -->
                <a href="{{ url('/') }}" class="text-2xl font-bold tracking-tight flex items-center gap-2">
                    <img src="{{ asset('logo.jpeg') }}" class="w-8 h-8 rounded-lg object-contain" alt="Egfollow Logo">
                    <span
                        class="bg-clip-text text-transparent bg-gradient-to-r from-brand-purple to-brand-pink app-name">Egfollow</span>
                </a>

                <!-- Nav -->
                <nav class="hidden md:flex items-center gap-8">
                    <a href="{{ url('/') }}#features"
                        class="text-slate-600 hover:text-brand-purple transition-colors font-medium">Features</a>
                    <a href="{{ url('/') }}#pricing"
                        class="text-slate-600 hover:text-brand-purple transition-colors font-medium">Pricing</a>
                    <a href="{{ url('/') }}#blog" class="text-brand-purple transition-colors font-medium">Blog</a>
                </nav>

                <!-- CTA -->
                <div class="flex items-center gap-4">
                    <a href="{{ url('/') }}#download"
                        class="hidden md:inline-flex bg-slate-900 hover:bg-slate-800 text-white px-6 py-2.5 rounded-full font-medium transition-all hover:shadow-lg hover:scale-105 text-sm">
                        Download App
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Article Content -->
    <article class="pt-32 pb-20">
        <div class="container mx-auto px-4 max-w-4xl">
            <!-- Article Header -->
            <header class="mb-12 text-center">
                <div
                    class="inline-block px-4 py-1.5 rounded-full bg-brand-purple/10 text-brand-purple font-medium text-sm mb-6">
                    Blog Post
                </div>
                <h1 class="text-4xl md:text-5xl font-bold text-slate-900 mb-6 leading-tight" dir="auto">
                    {{ $article->title }}
                </h1>
                <div class="flex items-center justify-center gap-4 text-slate-500 text-sm">
                    @if($article->published_at)
                        <time datetime="{{ $article->published_at }}">
                            {{ \Carbon\Carbon::parse($article->published_at)->format('F d, Y') }}
                        </time>
                    @endif
                </div>
            </header>

            <!-- Featured Image -->
            @if($article->image)
                @php
                    $imageUrl = preg_match('/^https?:\/\//', $article->image) ? $article->image : asset('storage/' . ltrim($article->image, '/'));
                @endphp
                <div class="rounded-3xl overflow-hidden shadow-xl mb-12 border border-slate-100">
                    <img src="{{ $imageUrl }}" alt="{{ $article->title }}" class="w-full h-auto object-cover max-h-[500px]">
                </div>
            @endif

            <!-- Article Body -->
            <div class="prose prose-lg prose-slate mx-auto prose-headings:font-bold prose-headings:text-slate-900 prose-a:text-brand-purple hover:prose-a:text-brand-pink"
                dir="auto">
                {!! $article->content !!}
            </div>

            <!-- Back to Home -->
            <div class="mt-16 text-center border-t border-slate-100 pt-12">
                <a href="{{ url('/') }}"
                    class="inline-flex items-center gap-2 text-slate-600 hover:text-brand-purple font-medium transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                        stroke="currentColor" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                    </svg>
                    Back to Home
                </a>
            </div>
        </div>
    </article>

    <!-- Footer -->
    <footer class="bg-slate-900 text-white py-12 border-t border-slate-800">
        <div class="container mx-auto px-4">
            <div class="text-center text-sm text-slate-500">
                <p>&copy; {{ date('Y') }} <span class="app-name">Egfollow</span>. All rights reserved.</p>
            </div>
        </div>
    </footer>

</body>

</html>