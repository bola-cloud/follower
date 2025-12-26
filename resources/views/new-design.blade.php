<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ReactX - Boost Your Instagram Reacts & Followers</title>
    <meta name="description" content="Get instant Instagram reacts and followers with ReactX. Safe, secure, and free exchange platform for social media growth.">

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://reactx.app/">
    <meta property="og:title" content="ReactX - Boost Your Instagram Reacts & Followers">
    <meta property="og:description" content="Get instant Instagram reacts and followers with ReactX. Safe, secure, and free exchange platform for social media growth.">
    <meta property="og:image" content="https://placehold.co/1200x630/8b5cf6/white?text=ReactX+Preview">

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
        .hero-blob {
            position: absolute;
            filter: blur(80px);
            opacity: 0.4;
            z-index: -1;
        }
    </style>
</head>
<body class="font-sans text-slate-800 antialiased bg-slate-50 overflow-x-hidden">

    @php
        // Server-side fallback: get latest APK so buttons work without JS
        $__latestApk = \App\Models\Apk::orderBy('created_at', 'desc')->first();
        $__latest_download = $__latestApk ? $__latestApk->download_url : '#';
        $__latest_play = $__latestApk && $__latestApk->play_store_url ? $__latestApk->play_store_url : null;
    @endphp

    <!-- Header -->
    <header class="fixed w-full top-0 z-50 transition-all duration-300" id="navbar">
        <div class="absolute inset-0 glass-effect shadow-sm opacity-95"></div>
        <div class="container mx-auto px-4 py-4 relative">
            <div class="flex justify-between items-center">
                <!-- Logo -->
                <a href="#" class="text-2xl font-bold tracking-tight flex items-center gap-2">
                    <img class="app-logo w-8 h-8 rounded-lg object-cover hidden" alt="App Logo">
                    <span class="w-8 h-8 rounded-lg bg-gradient-to-tr from-brand-purple to-brand-pink flex items-center justify-center text-white logo-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-5 h-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
                        </svg>
                    </span>
                    <span class="bg-clip-text text-transparent bg-gradient-to-r from-brand-purple to-brand-pink app-name">ReactX</span>
                </a>

                <!-- Desktop Nav -->
                <nav class="hidden md:flex items-center gap-8">
                    <a href="#features" class="text-slate-600 hover:text-brand-purple transition-colors font-medium">Features</a>
                    <a href="#pricing" class="text-slate-600 hover:text-brand-purple transition-colors font-medium">Pricing</a>
                    <a href="#how-it-works" class="text-slate-600 hover:text-brand-purple transition-colors font-medium">How It Works</a>
                    <a href="#blog" class="text-slate-600 hover:text-brand-purple transition-colors font-medium">Blog</a>
                    <a href="#safety" class="text-slate-600 hover:text-brand-purple transition-colors font-medium">Safety</a>
                    <a href="#faq" class="text-slate-600 hover:text-brand-purple transition-colors font-medium">FAQ</a>
                </nav>

                <!-- CTA & Mobile Toggle -->
                <div class="flex items-center gap-4">
                    <a href="{{ $__latest_download }}" id="header-download-btn" class="hidden md:inline-flex bg-slate-900 hover:bg-slate-800 text-white px-6 py-2.5 rounded-full font-medium transition-all hover:shadow-lg hover:scale-105 text-sm" @if($__latest_download && $__latest_download !== '#') target="_blank" rel="noopener" download @endif>
                        Download App
                    </a>
                    <button id="mobile-menu-btn" class="md:hidden p-2 text-slate-600 focus:outline-none">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- Mobile Menu -->
        <div id="mobile-menu" class="fixed inset-0 bg-white z-40 transform translate-x-full transition-transform duration-300 md:hidden pt-24 px-6">
            <nav class="flex flex-col gap-6 text-center">
                <a href="#features" class="text-xl font-medium text-slate-800 mobile-link">Features</a>
                <a href="#pricing" class="text-xl font-medium text-slate-800 mobile-link">Pricing</a>
                <a href="#how-it-works" class="text-xl font-medium text-slate-800 mobile-link">How It Works</a>
                <a href="#blog" class="text-xl font-medium text-slate-800 mobile-link">Blog</a>
                <a href="#safety" class="text-xl font-medium text-slate-800 mobile-link">Safety</a>
                <a href="#faq" class="text-xl font-medium text-slate-800 mobile-link">FAQ</a>
                <a href="{{ $__latest_download }}" id="mobile-download-btn" class="mt-4 bg-gradient-to-r from-brand-purple to-brand-pink text-white px-8 py-3 rounded-full font-bold shadow-lg mobile-link" @if($__latest_download && $__latest_download !== '#') target="_blank" rel="noopener" download @endif>
                    Download App
                </a>
            </nav>
            <button id="close-menu-btn" class="absolute top-6 right-6 p-2 text-slate-500">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-8 h-8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="relative pt-32 pb-20 lg:pt-40 lg:pb-32 overflow-hidden">
        <!-- Background Blobs -->
        <div class="hero-blob bg-brand-purple w-96 h-96 rounded-full top-0 left-0 -translate-x-1/2 -translate-y-1/4"></div>
        <div class="hero-blob bg-brand-pink w-96 h-96 rounded-full bottom-0 right-0 translate-x-1/3 translate-y-1/4"></div>

        <div class="container mx-auto px-4">
            <div class="flex flex-col lg:flex-row items-center gap-12 lg:gap-20">
                <!-- Text Content -->
                <div class="lg:w-1/2 text-center lg:text-left z-10">
                    <div class="inline-block px-4 py-1.5 rounded-full bg-brand-purple/10 text-brand-purple font-medium text-sm mb-6 border border-brand-purple/20">
                        🚀 #1 Instagram Growth Tool
                    </div>
                    <h1 class="text-4xl lg:text-6xl font-bold leading-tight mb-6 text-slate-900">
                        Boost Your Instagram <br>
                        <span class="gradient-text">Reacts & Followers</span> <br>
                        Instantly
                    </h1>
                    <p class="text-lg text-slate-600 mb-8 leading-relaxed max-w-xl mx-auto lg:mx-0">
                        Join thousands of creators growing their presence. Exchange likes, reacts, and followers in a secure community environment. Real engagement from real users.
                    </p>

                    <div class="flex flex-col sm:flex-row gap-4 justify-center lg:justify-start mb-6">
                        <!-- Google Play Button -->
                        <a href="{{ $__latest_play ?? '#' }}" id="google-play-btn" class="flex items-center gap-3 bg-slate-900 hover:bg-slate-800 text-white px-6 py-3.5 rounded-xl transition-all hover:shadow-xl hover:-translate-y-1 group" @if($__latest_play) target="_blank" rel="noopener" @endif>
                            <svg class="w-8 h-8" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M3.609 1.814L13.792 12 3.61 22.186a.996.996 0 0 1-.61-.92V2.734a1 1 0 0 1 .609-.92zm11.468 11.122L5.01 23.003c.21.09.445.09.656 0l11.86-6.794-2.449-3.273zm1.27-1.7l4.827-2.753a.993.993 0 0 1 1.023.036.998.998 0 0 1-.056 1.706l-4.815 2.758-1.196-1.598.217-.15zm-1.27-1.7L15.077 2.936l-10.067 10.067 10.067-3.467z"/>
                            </svg>
                            <div class="text-left">
                                <div class="text-[10px] uppercase tracking-wider opacity-80">Get it on</div>
                                <div class="text-lg font-bold leading-none">Google Play</div>
                            </div>
                        </a>

                        <!-- APK Button -->
                        <a href="{{ $__latest_download }}" id="apk-download-btn-hero" class="flex items-center justify-center gap-2 bg-white border-2 border-slate-200 hover:border-brand-purple/50 text-slate-700 px-6 py-3.5 rounded-xl transition-all hover:shadow-lg hover:-translate-y-1 group" @if($__latest_download && $__latest_download !== '#') target="_blank" rel="noopener" download @endif>
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6 group-hover:text-brand-purple transition-colors">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                            </svg>
                            <div class="text-left">
                                <div class="text-sm font-bold">Download APK</div>
                            </div>
                        </a>
                    </div>

                    <div class="flex items-center justify-center lg:justify-start gap-4 text-xs text-slate-500 font-medium">
                        <span class="flex items-center gap-1">
                            <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            No Password Required
                        </span>
                        <span class="flex items-center gap-1">
                            <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                            Safe & Secure
                        </span>
                    </div>
                </div>

                <!-- Phone Mockup -->
                <div class="lg:w-1/2 relative z-10 flex justify-center">
                    <div class="relative w-72 h-[580px] bg-slate-900 rounded-[3rem] border-[8px] border-slate-900 shadow-2xl overflow-hidden">
                        <!-- Notch -->
                        <div class="absolute top-0 left-1/2 -translate-x-1/2 w-32 h-6 bg-slate-900 rounded-b-xl z-20"></div>

                        <!-- Screen Content Placeholder -->
                        <div class="w-full h-full bg-slate-50 relative overflow-hidden">
                            <!-- App Header -->
                            <div class="h-24 bg-gradient-to-r from-brand-purple to-brand-pink p-6 pt-10 text-white flex justify-between items-center">
                                <span class="font-bold">ReactX</span>
                                <div class="w-8 h-8 bg-white/20 rounded-full"></div>
                            </div>

                            <!-- Stats Card -->
                            <div class="mx-4 -mt-6 bg-white rounded-xl shadow-lg p-4 flex justify-between text-center z-10 relative">
                                <div>
                                    <div class="text-xs text-slate-500 uppercase font-bold">Followers</div>
                                    <div class="text-xl font-bold text-brand-purple">1.2k</div>
                                </div>
                                <div class="w-px bg-slate-100"></div>
                                <div>
                                    <div class="text-xs text-slate-500 uppercase font-bold">Reacts</div>
                                    <div class="text-xl font-bold text-brand-pink">842</div>
                                </div>
                            </div>

                            <!-- Feed Items -->
                            <div class="p-4 space-y-4 mt-2">
                                <div class="bg-white p-3 rounded-xl shadow-sm border border-slate-100 flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-400 to-blue-600"></div>
                                    <div class="flex-1">
                                        <div class="h-2 w-24 bg-slate-200 rounded mb-1.5"></div>
                                        <div class="h-2 w-16 bg-slate-100 rounded"></div>
                                    </div>
                                    <div class="text-brand-purple font-bold text-sm">+50</div>
                                </div>
                                <div class="bg-white p-3 rounded-xl shadow-sm border border-slate-100 flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-pink-400 to-pink-600"></div>
                                    <div class="flex-1">
                                        <div class="h-2 w-20 bg-slate-200 rounded mb-1.5"></div>
                                        <div class="h-2 w-12 bg-slate-100 rounded"></div>
                                    </div>
                                    <div class="text-brand-pink font-bold text-sm">❤️</div>
                                </div>
                                <div class="bg-white p-3 rounded-xl shadow-sm border border-slate-100 flex items-center gap-3 opacity-60">
                                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-purple-400 to-purple-600"></div>
                                    <div class="flex-1">
                                        <div class="h-2 w-24 bg-slate-200 rounded mb-1.5"></div>
                                        <div class="h-2 w-16 bg-slate-100 rounded"></div>
                                    </div>
                                    <div class="text-brand-purple font-bold text-sm">+20</div>
                                </div>
                            </div>

                            <!-- Bottom Action Button -->
                            <div class="absolute bottom-6 left-0 right-0 px-6">
                                <div class="w-full bg-slate-900 text-white py-3 rounded-xl text-center text-sm font-bold">
                                    Start Boosting
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="py-10 bg-white border-b border-slate-100">
        <div class="container mx-auto px-4">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-8 text-center divide-x divide-slate-100">
                <div class="p-4">
                    <div class="text-4xl font-bold text-slate-900 mb-1">50K+</div>
                    <div class="text-slate-500 font-medium text-sm uppercase tracking-wide">Active Users</div>
                </div>
                <div class="p-4">
                    <div class="text-4xl font-bold text-brand-purple mb-1">2M+</div>
                    <div class="text-slate-500 font-medium text-sm uppercase tracking-wide">Reacts Delivered</div>
                </div>
                <div class="p-4">
                    <div class="text-4xl font-bold text-brand-pink mb-1">500K+</div>
                    <div class="text-slate-500 font-medium text-sm uppercase tracking-wide">Orders Completed</div>
                </div>
                <div class="p-4">
                    <div class="text-4xl font-bold text-brand-blue mb-1">4.9</div>
                    <div class="text-slate-500 font-medium text-sm uppercase tracking-wide">User Rating</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="py-20 bg-white">
        <div class="container mx-auto px-4">
            <div class="text-center mb-16 max-w-2xl mx-auto">
                <h2 class="text-3xl md:text-4xl font-bold text-slate-900 mb-4">Why Choose ReactX?</h2>
                <p class="text-slate-600">We provide the safest and fastest way to grow your social media presence with real engagement.</p>
            </div>

            <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-8">
                <!-- Feature 1 -->
                <div class="bg-slate-50 p-8 rounded-2xl hover:shadow-xl hover:-translate-y-1 transition-all duration-300 border border-slate-100">
                    <div class="w-14 h-14 bg-blue-100 rounded-xl flex items-center justify-center text-brand-blue mb-6">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-7 h-7">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold text-slate-900 mb-2">Real Users</h3>
                    <p class="text-slate-600 leading-relaxed">Connect with genuine active users. No bots or fake accounts in our ecosystem.</p>
                </div>

                <!-- Feature 2 -->
                <div class="bg-slate-50 p-8 rounded-2xl hover:shadow-xl hover:-translate-y-1 transition-all duration-300 border border-slate-100">
                    <div class="w-14 h-14 bg-purple-100 rounded-xl flex items-center justify-center text-brand-purple mb-6">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-7 h-7">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold text-slate-900 mb-2">Instant Boost</h3>
                    <p class="text-slate-600 leading-relaxed">See results immediately after requesting. Our system processes exchanges in real-time.</p>
                </div>

                <!-- Feature 3 -->
                <div class="bg-slate-50 p-8 rounded-2xl hover:shadow-xl hover:-translate-y-1 transition-all duration-300 border border-slate-100">
                    <div class="w-14 h-14 bg-pink-100 rounded-xl flex items-center justify-center text-brand-pink mb-6">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-7 h-7">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold text-slate-900 mb-2">Targeted Reacts</h3>
                    <p class="text-slate-600 leading-relaxed">Choose exactly which posts get attention and what kind of reactions you want.</p>
                </div>

                <!-- Feature 4 -->
                <div class="bg-slate-50 p-8 rounded-2xl hover:shadow-xl hover:-translate-y-1 transition-all duration-300 border border-slate-100">
                    <div class="w-14 h-14 bg-green-100 rounded-xl flex items-center justify-center text-green-600 mb-6">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-7 h-7">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.746 3.746 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold text-slate-900 mb-2">100% Secure</h3>
                    <p class="text-slate-600 leading-relaxed">Your account safety is our priority. We use advanced encryption and never ask for passwords.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Pricing Section -->
    {{-- <section id="pricing" class="py-20 bg-slate-50">
        <div class="container mx-auto px-4">
            <div class="text-center mb-16 max-w-2xl mx-auto">
                <h2 class="text-3xl md:text-4xl font-bold text-slate-900 mb-4">Simple Coin Packages</h2>
                <p class="text-slate-600">Purchase coins to boost your profile faster. No subscription required.</p>
            </div>

            <div class="grid md:grid-cols-3 gap-8 max-w-5xl mx-auto items-center">
                <!-- Starter Package -->
                <div class="bg-white p-8 rounded-3xl shadow-sm border border-slate-200 hover:shadow-xl transition-all duration-300">
                    <div class="text-slate-500 font-medium mb-4">Starter Pack</div>
                    <div class="text-4xl font-bold text-slate-900 mb-2">500 <span class="text-lg font-normal text-slate-500">Coins</span></div>
                    <div class="text-2xl font-bold text-brand-purple mb-6">$4.99</div>

                    <ul class="space-y-4 mb-8 text-slate-600 text-sm">
                        <li class="flex items-center gap-3">
                            <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                            Approx. 250 Followers
                        </li>
                        <li class="flex items-center gap-3">
                            <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                            Approx. 500 Likes
                        </li>
                        <li class="flex items-center gap-3">
                            <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                            Instant Delivery
                        </li>
                    </ul>

                    <button class="w-full py-3 rounded-xl border-2 border-slate-200 font-bold text-slate-700 hover:border-brand-purple hover:text-brand-purple transition-colors">Buy Now</button>
                </div>

                <!-- Pro Package (Popular) -->
                <div class="bg-white p-8 rounded-3xl shadow-xl border-2 border-brand-purple relative transform md:-translate-y-4">
                    <div class="absolute top-0 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-gradient-to-r from-brand-purple to-brand-pink text-white px-4 py-1 rounded-full text-sm font-bold uppercase tracking-wide">Most Popular</div>

                    <div class="text-brand-purple font-bold mb-4">Pro Pack</div>
                    <div class="text-4xl font-bold text-slate-900 mb-2">2500 <span class="text-lg font-normal text-slate-500">Coins</span></div>
                    <div class="text-2xl font-bold text-brand-pink mb-6">$19.99</div>

                    <ul class="space-y-4 mb-8 text-slate-600 font-medium">
                        <li class="flex items-center gap-3">
                            <div class="w-5 h-5 rounded-full bg-brand-purple/10 flex items-center justify-center text-brand-purple text-xs">✓</div>
                            Approx. 1,250 Followers
                        </li>
                        <li class="flex items-center gap-3">
                            <div class="w-5 h-5 rounded-full bg-brand-purple/10 flex items-center justify-center text-brand-purple text-xs">✓</div>
                            Approx. 2,500 Likes
                        </li>
                        <li class="flex items-center gap-3">
                            <div class="w-5 h-5 rounded-full bg-brand-purple/10 flex items-center justify-center text-brand-purple text-xs">✓</div>
                            Priority Support
                        </li>
                        <li class="flex items-center gap-3">
                            <div class="w-5 h-5 rounded-full bg-brand-purple/10 flex items-center justify-center text-brand-purple text-xs">✓</div>
                            Bonus +100 Coins
                        </li>
                    </ul>

                    <button class="w-full py-4 rounded-xl bg-gradient-to-r from-brand-purple to-brand-pink text-white font-bold shadow-lg hover:shadow-xl hover:scale-105 transition-all">Buy Now</button>
                </div>

                <!-- Enterprise Package -->
                <div class="bg-white p-8 rounded-3xl shadow-sm border border-slate-200 hover:shadow-xl transition-all duration-300">
                    <div class="text-slate-500 font-medium mb-4">Influencer Pack</div>
                    <div class="text-4xl font-bold text-slate-900 mb-2">10000 <span class="text-lg font-normal text-slate-500">Coins</span></div>
                    <div class="text-2xl font-bold text-brand-blue mb-6">$69.99</div>

                    <ul class="space-y-4 mb-8 text-slate-600 text-sm">
                        <li class="flex items-center gap-3">
                            <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                            Approx. 5,000 Followers
                        </li>
                        <li class="flex items-center gap-3">
                            <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                            Approx. 10,000 Likes
                        </li>
                        <li class="flex items-center gap-3">
                            <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                            VIP Support
                        </li>
                    </ul>

                    <button class="w-full py-3 rounded-xl border-2 border-slate-200 font-bold text-slate-700 hover:border-brand-blue hover:text-brand-blue transition-colors">Buy Now</button>
                </div>
            </div>
        </div>
    </section> --}}

    <!-- How It Works -->
    <section id="how-it-works" class="py-20 bg-slate-50 relative overflow-hidden">
        <div class="absolute top-0 left-0 w-full h-full bg-[url('data:image/svg+xml,%3Csvg width=\'60\' height=\'60\' viewBox=\'0 0 60 60\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cg fill=\'none\' fill-rule=\'evenodd\'%3E%3Cg fill=\'%238b5cf6\' fill-opacity=\'0.05\'%3E%3Cpath d=\'M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z\'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E')] opacity-50"></div>

        <div class="container mx-auto px-4 relative z-10">
            <div class="text-center mb-16">
                <h2 class="text-3xl md:text-4xl font-bold text-slate-900 mb-4">How It Works</h2>
                <p class="text-slate-600">Get started in 3 simple steps. No complicated setup required.</p>
            </div>

            <div class="flex flex-col md:flex-row justify-between items-start gap-8 max-w-5xl mx-auto">
                <!-- Step 1 -->
                <div class="flex-1 text-center relative group">
                    <div class="w-20 h-20 bg-white rounded-2xl shadow-md flex items-center justify-center mx-auto mb-6 text-2xl font-bold text-brand-purple border border-slate-100 relative z-10">
                        1
                    </div>
                    <!-- Line Connector -->
                    <div class="hidden md:block absolute top-10 left-1/2 w-full h-0.5 bg-slate-200 -z-0"></div>

                    <h3 class="text-xl font-bold text-slate-900 mb-2">Download & Connect</h3>
                    <p class="text-slate-600 text-sm px-4">Get the app and simply enter your username. No password needed to start.</p>
                </div>

                <!-- Step 2 -->
                <div class="flex-1 text-center relative group">
                    <div class="w-20 h-20 bg-white rounded-2xl shadow-md flex items-center justify-center mx-auto mb-6 text-2xl font-bold text-brand-pink border border-slate-100 relative z-10">
                        2
                    </div>
                    <!-- Line Connector -->
                    <div class="hidden md:block absolute top-10 left-1/2 w-full h-0.5 bg-slate-200 -z-0"></div>

                    <h3 class="text-xl font-bold text-slate-900 mb-2">Choose Service</h3>
                    <p class="text-slate-600 text-sm px-4">Select whether you want more followers, likes, or custom reactions.</p>
                </div>

                <!-- Step 3 -->
                <div class="flex-1 text-center relative group">
                    <div class="w-20 h-20 bg-white rounded-2xl shadow-md flex items-center justify-center mx-auto mb-6 text-2xl font-bold text-brand-blue border border-slate-100 relative z-10">
                        3
                    </div>
                    <h3 class="text-xl font-bold text-slate-900 mb-2">Watch It Grow</h3>
                    <p class="text-slate-600 text-sm px-4">Sit back and watch your engagement numbers climb instantly.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Latest News / Blog Section -->
    <section id="blog" class="py-20 bg-slate-50">
        <div class="container mx-auto px-4">
            <div class="flex justify-between items-end mb-12 max-w-6xl mx-auto">
                <div>
                    <h2 class="text-3xl md:text-4xl font-bold text-slate-900 mb-2">Latest Updates</h2>
                    <p class="text-slate-600">Tips and tricks to grow your Instagram.</p>
                </div>
                <a href="#" class="hidden md:flex items-center gap-2 text-brand-purple font-medium hover:gap-3 transition-all">
                    View all articles
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                    </svg>
                </a>
            </div>

            <div class="grid md:grid-cols-3 gap-8 max-w-6xl mx-auto">
                <!-- Article 1 -->
                <a href="#" class="group bg-white rounded-2xl overflow-hidden shadow-sm hover:shadow-xl transition-all duration-300">
                    <div class="h-48 bg-gray-200 relative overflow-hidden">
                        <img src="/placeholder.svg?height=300&width=500&text=Instagram+Tips" alt="Blog Post" class="w-full h-full object-cover transform group-hover:scale-105 transition-transform duration-500">
                        <div class="absolute top-4 left-4 bg-white/90 backdrop-blur px-3 py-1 rounded-full text-xs font-bold text-brand-purple">Tips</div>
                    </div>
                    <div class="p-6">
                        <div class="text-xs text-slate-500 mb-2">Nov 20, 2025</div>
                        <h3 class="text-xl font-bold text-slate-900 mb-3 group-hover:text-brand-purple transition-colors">How to get your first 1,000 followers fast</h3>
                        <p class="text-slate-600 text-sm line-clamp-2">Learn the proven strategies to kickstart your Instagram growth journey without spending a fortune.</p>
                    </div>
                </a>

                <!-- Article 2 -->
                <a href="#" class="group bg-white rounded-2xl overflow-hidden shadow-sm hover:shadow-xl transition-all duration-300">
                    <div class="h-48 bg-gray-200 relative overflow-hidden">
                        <img src="/placeholder.svg?height=300&width=500&text=Best+Time" alt="Blog Post" class="w-full h-full object-cover transform group-hover:scale-105 transition-transform duration-500">
                        <div class="absolute top-4 left-4 bg-white/90 backdrop-blur px-3 py-1 rounded-full text-xs font-bold text-brand-pink">Strategy</div>
                    </div>
                    <div class="p-6">
                        <div class="text-xs text-slate-500 mb-2">Nov 18, 2025</div>
                        <h3 class="text-xl font-bold text-slate-900 mb-3 group-hover:text-brand-pink transition-colors">Best times to post on Instagram in 2025</h3>
                        <p class="text-slate-600 text-sm line-clamp-2">Timing is everything. Discover when your audience is most active to maximize engagement.</p>
                    </div>
                </a>

                <!-- Article 3 -->
                <a href="#" class="group bg-white rounded-2xl overflow-hidden shadow-sm hover:shadow-xl transition-all duration-300">
                    <div class="h-48 bg-gray-200 relative overflow-hidden">
                        <img src="/placeholder.svg?height=300&width=500&text=Algorithm" alt="Blog Post" class="w-full h-full object-cover transform group-hover:scale-105 transition-transform duration-500">
                        <div class="absolute top-4 left-4 bg-white/90 backdrop-blur px-3 py-1 rounded-full text-xs font-bold text-brand-blue">Updates</div>
                    </div>
                    <div class="p-6">
                        <div class="text-xs text-slate-500 mb-2">Nov 15, 2025</div>
                        <h3 class="text-xl font-bold text-slate-900 mb-3 group-hover:text-brand-blue transition-colors">Understanding the new Instagram Algorithm</h3>
                        <p class="text-slate-600 text-sm line-clamp-2">The algorithm has changed again. Here's what you need to know to stay ahead of the game.</p>
                    </div>
                </a>
            </div>
        </div>
    </section>


    <!-- Safety Section -->
    <section id="safety" class="py-20 bg-white">
        <div class="container mx-auto px-4">
            <div class="flex flex-col lg:flex-row items-center gap-12">
                <div class="lg:w-1/2">
                    <div class="relative">
                        <div class="absolute inset-0 bg-gradient-to-r from-brand-purple to-brand-pink blur-3xl opacity-20 rounded-full"></div>
                        <img src="/placeholder.svg?height=400&width=500" alt="Security Illustration" class="relative z-10 rounded-3xl shadow-2xl border border-slate-100 bg-white/50 backdrop-blur-sm p-2">
                    </div>
                </div>
                <div class="lg:w-1/2">
                    <h2 class="text-3xl md:text-4xl font-bold text-slate-900 mb-6">Safe, Secure & Transparent</h2>
                    <p class="text-slate-600 text-lg mb-8">
                        We built ReactX with your privacy in mind. Unlike other services, we never store sensitive data or ask for your private credentials.
                    </p>

                    <div class="space-y-6">
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-full bg-green-100 flex items-center justify-center text-green-600 shrink-0 mt-1">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5">
                                    <path fill-rule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clip-rule="evenodd" />
                                </svg>
                            </div>
                            <div>
                                <h4 class="text-lg font-bold text-slate-900">No Passwords Stored</h4>
                                <p class="text-slate-600 text-sm">We only need your public username to deliver engagement.</p>
                            </div>
                        </div>

                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center text-brand-blue shrink-0 mt-1">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" />
                                </svg>
                            </div>
                            <div>
                                <h4 class="text-lg font-bold text-slate-900">Bot-Free Guarantee</h4>
                                <p class="text-slate-600 text-sm">All engagement comes from our network of real mobile users.</p>
                            </div>
                        </div>

                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-full bg-purple-100 flex items-center justify-center text-brand-purple shrink-0 mt-1">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5">
                                    <path fill-rule="evenodd" d="M2 10c0-3.967 3.69-7 8-7 4.31 0 8 3.033 8 7s-3.69 7-8 7a6.99 6.99 0 01-2.913-.64l-4.272 1.265a.75.75 0 01-.945-.944l1.265-4.272A6.985 6.985 0 012 10zm8 1a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
                                </svg>
                            </div>
                            <div>
                                <h4 class="text-lg font-bold text-slate-900">24/7 Support</h4>
                                <p class="text-slate-600 text-sm">Our team is always ready to help with any questions or issues.</p>
                            </div>
                        </div>
                    </div>

                    <div class="mt-10 inline-flex items-center gap-3 px-5 py-2 bg-slate-50 rounded-full border border-slate-200 text-sm font-medium text-slate-600">
                        <span class="flex -space-x-2">
                            <div class="w-6 h-6 rounded-full bg-gray-300 border-2 border-white"></div>
                            <div class="w-6 h-6 rounded-full bg-gray-400 border-2 border-white"></div>
                            <div class="w-6 h-6 rounded-full bg-gray-500 border-2 border-white"></div>
                        </span>
                        Trusted by 50,000+ users
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Testimonials -->
    <section class="py-20 bg-slate-50">
        <div class="container mx-auto px-4">
            <div class="text-center mb-16">
                <h2 class="text-3xl md:text-4xl font-bold text-slate-900 mb-4">What Our Users Say</h2>
                <p class="text-slate-600">Join the community of creators boosting their engagement.</p>
            </div>

            <div class="grid md:grid-cols-3 gap-8">
                <!-- Review 1 -->
                <div class="bg-white p-8 rounded-2xl shadow-sm hover:shadow-md transition-shadow">
                    <div class="flex items-center gap-4 mb-4">
                        <div class="w-12 h-12 rounded-full bg-gradient-to-tr from-yellow-400 to-orange-500 flex items-center justify-center text-white font-bold text-lg">S</div>
                        <div>
                            <h4 class="font-bold text-slate-900">Sarah J.</h4>
                            <div class="text-yellow-400 text-sm">★★★★★</div>
                        </div>
                    </div>
                    <p class="text-slate-600 italic">"I was skeptical at first, but ReactX really works! My posts get so much more visibility now thanks to the initial boost."</p>
                </div>

                <!-- Review 2 -->
                <div class="bg-white p-8 rounded-2xl shadow-sm hover:shadow-md transition-shadow">
                    <div class="flex items-center gap-4 mb-4">
                        <div class="w-12 h-12 rounded-full bg-gradient-to-tr from-blue-400 to-cyan-500 flex items-center justify-center text-white font-bold text-lg">M</div>
                        <div>
                            <h4 class="font-bold text-slate-900">Mike T.</h4>
                            <div class="text-yellow-400 text-sm">★★★★★</div>
                        </div>
                    </div>
                    <p class="text-slate-600 italic">"Super easy to use and the results are instant. Love that I don't have to give my password. 10/10 recommended."</p>
                </div>

                <!-- Review 3 -->
                <div class="bg-white p-8 rounded-2xl shadow-sm hover:shadow-md transition-shadow">
                    <div class="flex items-center gap-4 mb-4">
                        <div class="w-12 h-12 rounded-full bg-gradient-to-tr from-purple-400 to-pink-500 flex items-center justify-center text-white font-bold text-lg">A</div>
                        <div>
                            <h4 class="font-bold text-slate-900">Alex R.</h4>
                            <div class="text-yellow-400 text-sm">★★★★★</div>
                        </div>
                    </div>
                    <p class="text-slate-600 italic">"Best app for growing new accounts. The community aspect is great and the exchange system is very fair."</p>
                </div>
            </div>
        </div>
    </section>

    <!-- FAQ Section -->
    <section id="faq" class="py-20 bg-white">
        <div class="container mx-auto px-4 max-w-3xl">
            <div class="text-center mb-16">
                <h2 class="text-3xl md:text-4xl font-bold text-slate-900 mb-4">Frequently Asked Questions</h2>
                <p class="text-slate-600">Everything you need to know about ReactX.</p>
            </div>

            <div class="space-y-4">
                <!-- FAQ Item 1 -->
                <div class="border border-slate-200 rounded-2xl overflow-hidden">
                    <button class="faq-btn w-full px-6 py-4 text-left bg-white hover:bg-slate-50 flex justify-between items-center transition-colors">
                        <span class="font-bold text-slate-900">Is this safe for my Instagram account?</span>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5 transform transition-transform duration-300 faq-icon">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                        </svg>
                    </button>
                    <div class="faq-content hidden bg-slate-50 px-6 py-4 text-slate-600 border-t border-slate-100">
                        Yes, absolutely. We operate within Instagram's community guidelines. Since we use real users and don't require your password, your account remains completely secure.
                    </div>
                </div>

                <!-- FAQ Item 2 -->
                <div class="border border-slate-200 rounded-2xl overflow-hidden">
                    <button class="faq-btn w-full px-6 py-4 text-left bg-white hover:bg-slate-50 flex justify-between items-center transition-colors">
                        <span class="font-bold text-slate-900">Do I need to share my password?</span>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5 transform transition-transform duration-300 faq-icon">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                        </svg>
                    </button>
                    <div class="faq-content hidden bg-slate-50 px-6 py-4 text-slate-600 border-t border-slate-100">
                        No! We will never ask for your Instagram password. We only need your username to know where to send the followers and reacts.
                    </div>
                </div>

                <!-- FAQ Item 3 -->
                <div class="border border-slate-200 rounded-2xl overflow-hidden">
                    <button class="faq-btn w-full px-6 py-4 text-left bg-white hover:bg-slate-50 flex justify-between items-center transition-colors">
                        <span class="font-bold text-slate-900">Is the app free to use?</span>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5 transform transition-transform duration-300 faq-icon">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                        </svg>
                    </button>
                    <div class="faq-content hidden bg-slate-50 px-6 py-4 text-slate-600 border-t border-slate-100">
                        Yes, ReactX is free to download and use. You can earn credits by engaging with others, or choose to purchase credits for faster growth.
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Newsletter Section -->
    <section class="py-20 bg-slate-900 relative overflow-hidden">
        <div class="absolute top-0 left-0 w-full h-full bg-gradient-to-r from-brand-purple to-brand-pink opacity-10"></div>

        <div class="container mx-auto px-4 relative z-10">
            <div class="max-w-3xl mx-auto text-center">
                <h2 class="text-3xl md:text-4xl font-bold text-white mb-4">Get Free Coins & Updates</h2>
                <p class="text-slate-300 mb-8 text-lg">Subscribe to our newsletter and get a promo code for 100 free coins delivered to your inbox.</p>

                <form class="flex flex-col sm:flex-row gap-4 max-w-lg mx-auto" onsubmit="event.preventDefault();">
                    <input type="email" placeholder="Enter your email address" class="flex-1 px-6 py-4 rounded-xl bg-white/10 border border-white/20 text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-brand-purple backdrop-blur-sm">
                    <button type="submit" class="bg-white text-brand-purple px-8 py-4 rounded-xl font-bold hover:bg-slate-100 transition-colors shadow-lg">
                        Subscribe
                    </button>
                </form>
                <p class="text-slate-500 text-xs mt-4">We respect your privacy. Unsubscribe at any time.</p>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section id="download" class="py-20 px-4">
        <div class="container mx-auto max-w-5xl">
            <div class="bg-gradient-to-r from-brand-purple to-brand-pink rounded-3xl p-8 md:p-16 text-center text-white shadow-2xl relative overflow-hidden">
                <!-- Decorative circles -->
                <div class="absolute top-0 left-0 w-64 h-64 bg-white opacity-10 rounded-full -translate-x-1/2 -translate-y-1/2 blur-3xl"></div>
                <div class="absolute bottom-0 right-0 w-64 h-64 bg-white opacity-10 rounded-full translate-x-1/2 translate-y-1/2 blur-3xl"></div>

                <h2 class="text-3xl md:text-5xl font-bold mb-6 relative z-10">Ready to Boost Your Instagram?</h2>
                <p class="text-lg md:text-xl mb-10 opacity-90 max-w-2xl mx-auto relative z-10">Download the app now and see the difference in minutes. Join the fastest growing community today.</p>

                <div class="flex flex-col sm:flex-row gap-4 justify-center relative z-10">
                    <!-- Google Play Button (White Variant) -->
                    <a href="{{ $__latest_play ?? '#' }}" id="google-play-btn-cta" class="flex items-center gap-3 bg-white text-brand-purple px-6 py-3.5 rounded-xl transition-all hover:shadow-lg hover:bg-gray-50 hover:-translate-y-1 group" @if($__latest_play) target="_blank" rel="noopener" @endif>
                        <svg class="w-8 h-8" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M3.609 1.814L13.792 12 3.61 22.186a.996.996 0 0 1-.61-.92V2.734a1 1 0 0 1 .609-.92zm11.468 11.122L5.01 23.003c.21.09.445.09.656 0l11.86-6.794-2.449-3.273zm1.27-1.7l4.827-2.753a.993.993 0 0 1 1.023.036.998.998 0 0 1-.056 1.706l-4.815 2.758-1.196-1.598.217-.15zm-1.27-1.7L15.077 2.936l-10.067 10.067 10.067-3.467z"/>
                        </svg>
                        <div class="text-left">
                            <div class="text-[10px] uppercase tracking-wider opacity-80">Get it on</div>
                            <div class="text-lg font-bold leading-none">Google Play</div>
                        </div>
                    </a>

                    <!-- APK Button (Outline Variant) -->
                    <a href="{{ $__latest_download }}" id="apk-download-btn-cta" class="flex items-center justify-center gap-2 bg-transparent border-2 border-white text-white px-6 py-3.5 rounded-xl transition-all hover:bg-white/10 hover:-translate-y-1" @if($__latest_download && $__latest_download !== '#') target="_blank" rel="noopener" download @endif>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                        </svg>
                        <div class="text-left">
                            <div class="text-sm font-bold">Download APK</div>
                        </div>
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-slate-900 text-white py-12 border-t border-slate-800">
        <div class="container mx-auto px-4">
            <div class="flex flex-col md:flex-row justify-between items-center mb-8">
                <a href="#" class="text-2xl font-bold flex items-center gap-2 mb-4 md:mb-0">
                    <img class="app-logo w-8 h-8 rounded-lg object-cover hidden" alt="App Logo">
                    <span class="w-8 h-8 rounded-lg bg-gradient-to-tr from-brand-purple to-brand-pink flex items-center justify-center text-white logo-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-5 h-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
                        </svg>
                    </span>
                    <span class="app-name">ReactX</span>
                </a>
                <div class="flex gap-6 text-sm text-slate-400">
                    <a href="#" class="hover:text-white transition-colors">Privacy Policy</a>
                    <a href="#" class="hover:text-white transition-colors">Terms of Service</a>
                    <a href="#" class="hover:text-white transition-colors">Support</a>
                </div>
            </div>
            <div class="border-t border-slate-800 pt-8 text-center text-sm text-slate-500">
                <p>&copy; 2025 <span class="app-name">ReactX</span>. All rights reserved.</p>
                <p class="mt-2 text-xs">Not affiliated with Instagram or Meta Platforms, Inc.</p>
            </div>
        </div>
    </footer>

    <script>
        // Load settings and download links on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadSettings();
            setDownloadLinks();
        });

        // Load and apply settings dynamically
        function loadSettings() {
            fetch("{{ route('settings.public') }}")
                .then(response => response.json())
                .then(settings => {
                    // Update app name throughout page
                    if (settings.app_name) {
                        document.querySelectorAll('.app-name').forEach(el => {
                            el.textContent = settings.app_name;
                        });
                    }

                    // Update page title and meta tags
                    if (settings.seo_title) {
                        document.title = settings.seo_title;
                    }

                    // Update or create meta description
                    if (settings.seo_description) {
                        let metaDesc = document.querySelector('meta[name="description"]');
                        if (metaDesc) {
                            metaDesc.setAttribute('content', settings.seo_description);
                        }
                    }

                    // Update logo if exists
                    if (settings.app_logo) {
                        document.querySelectorAll('.app-logo').forEach(el => {
                            el.src = settings.app_logo;
                            el.style.display = 'block';
                        });
                    }
                })
                .catch(error => console.error('Error loading settings:', error));
        }

        function setDownloadLinks() {
            fetch("{{ route('apk.list') }}")
                .then(response => response.json())
                .then(apks => {
                    if (apks.length > 0) {
                        const firstApp = apks[0];

                        // Set header download button
                        const headerDownloadBtn = document.getElementById('header-download-btn');
                        if (headerDownloadBtn && firstApp.download_url) {
                            headerDownloadBtn.href = firstApp.download_url;
                        }

                        // Set mobile download button
                        const mobileDownloadBtn = document.getElementById('mobile-download-btn');
                        if (mobileDownloadBtn && firstApp.download_url) {
                            mobileDownloadBtn.href = firstApp.download_url;
                        }

                        // Set hero section buttons
                        const heroPlayBtn = document.getElementById('google-play-btn');
                        const heroApkBtn = document.getElementById('apk-download-btn-hero');

                        if (heroPlayBtn && firstApp.play_store_url) {
                            heroPlayBtn.href = firstApp.play_store_url;
                            heroPlayBtn.target = '_blank';
                        }
                        if (heroApkBtn && firstApp.download_url) {
                            heroApkBtn.href = firstApp.download_url;
                            heroApkBtn.target = '_blank';
                            heroApkBtn.rel = 'noopener';
                            // hint to browsers to download the file when possible
                            try { heroApkBtn.setAttribute('download', ''); } catch(e){}
                        }

                        // Set CTA section buttons
                        const ctaPlayBtn = document.getElementById('google-play-btn-cta');
                        const ctaApkBtn = document.getElementById('apk-download-btn-cta');

                        if (ctaPlayBtn && firstApp.play_store_url) {
                            ctaPlayBtn.href = firstApp.play_store_url;
                            ctaPlayBtn.target = '_blank';
                        }
                        if (ctaApkBtn && firstApp.download_url) {
                            ctaApkBtn.href = firstApp.download_url;
                            ctaApkBtn.target = '_blank';
                            ctaApkBtn.rel = 'noopener';
                            try { ctaApkBtn.setAttribute('download', ''); } catch(e){}
                        }

                        // Header and mobile download buttons (if present)
                        const headerDownloadBtn = document.getElementById('header-download-btn');
                        const mobileDownloadBtn = document.getElementById('mobile-download-btn');
                        if (headerDownloadBtn && firstApp.download_url) {
                            headerDownloadBtn.href = firstApp.download_url;
                            headerDownloadBtn.target = '_blank';
                            headerDownloadBtn.rel = 'noopener';
                            try { headerDownloadBtn.setAttribute('download', ''); } catch(e){}
                        }
                        if (mobileDownloadBtn && firstApp.download_url) {
                            mobileDownloadBtn.href = firstApp.download_url;
                            mobileDownloadBtn.target = '_blank';
                            mobileDownloadBtn.rel = 'noopener';
                            try { mobileDownloadBtn.setAttribute('download', ''); } catch(e){}
                        }
                    }
                })
                .catch(error => console.error('Error loading download links:', error));
        }

        // Mobile Menu Toggle
        const mobileMenuBtn = document.getElementById('mobile-menu-btn');
        const closeMenuBtn = document.getElementById('close-menu-btn');
        const mobileMenu = document.getElementById('mobile-menu');
        const mobileLinks = document.querySelectorAll('.mobile-link');

        function toggleMenu() {
            mobileMenu.classList.toggle('translate-x-full');
            document.body.classList.toggle('overflow-hidden');
        }

        mobileMenuBtn.addEventListener('click', toggleMenu);
        closeMenuBtn.addEventListener('click', toggleMenu);

        // Close menu when clicking a link
        mobileLinks.forEach(link => {
            link.addEventListener('click', toggleMenu);
        });

        // FAQ Accordion
        const faqBtns = document.querySelectorAll('.faq-btn');

        faqBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                const content = btn.nextElementSibling;
                const icon = btn.querySelector('.faq-icon');

                // Toggle current
                content.classList.toggle('hidden');
                icon.classList.toggle('rotate-180');

                // Close others (optional, removing this makes it allow multiple open)
                faqBtns.forEach(otherBtn => {
                    if (otherBtn !== btn) {
                        otherBtn.nextElementSibling.classList.add('hidden');
                        otherBtn.querySelector('.faq-icon').classList.remove('rotate-180');
                    }
                });
            });
        });

        // Sticky Header transparency on scroll
        const navbar = document.getElementById('navbar');
        window.addEventListener('scroll', () => {
            if (window.scrollY > 50) {
                navbar.classList.add('shadow-md');
            } else {
                navbar.classList.remove('shadow-md');
            }
        });
    </script>
</body>
</html>
