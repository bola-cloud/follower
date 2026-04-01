<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Download - Egfollow App</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gradient-to-br from-slate-900 via-purple-900 to-slate-900 min-h-screen">
    <!-- Header -->
    <nav class="bg-white/10 backdrop-blur-md border-b border-white/10 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <div
                    class="w-10 h-10 rounded-lg bg-gradient-to-br from-purple-500 to-pink-500 flex items-center justify-center">
                    <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" />
                    </svg>
                </div>
                <span class="text-2xl font-bold text-white">Egfollow App</span>
            </div>
            <div class="text-white/70 text-sm">Download v<span id="latest-version">1.0</span></div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
        <!-- Hero Section -->
        <div class="text-center mb-16">
            <h1 class="text-5xl sm:text-6xl font-bold text-white mb-6">Download Egfollow App</h1>
            <p class="text-xl text-white/70 mb-8">Get the latest version of our mobile application</p>
            <div id="stats-container" class="grid grid-cols-3 gap-6 mb-12 max-w-md mx-auto">
                <div class="bg-white/10 backdrop-blur-md rounded-xl p-4 border border-white/10">
                    <div class="text-2xl font-bold text-purple-400" id="stat-downloads">0</div>
                    <div class="text-sm text-white/60">Downloads</div>
                </div>
                <div class="bg-white/10 backdrop-blur-md rounded-xl p-4 border border-white/10">
                    <div class="text-2xl font-bold text-pink-400" id="stat-versions">0</div>
                    <div class="text-sm text-white/60">Versions</div>
                </div>
                <div class="bg-white/10 backdrop-blur-md rounded-xl p-4 border border-white/10">
                    <div class="text-2xl font-bold text-blue-400" id="stat-users">0</div>
                    <div class="text-sm text-white/60">Users</div>
                </div>
            </div>
        </div>

        <!-- APK List -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-12" id="apk-grid">
            <div class="col-span-full text-center py-12">
                <div class="inline-block">
                    <svg class="w-12 h-12 text-white/40 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                        </circle>
                        <path class="opacity-75" fill="currentColor"
                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                        </path>
                    </svg>
                </div>
                <p class="text-white/60 mt-4">Loading APKs...</p>
            </div>
        </div>

        <!-- Info Section -->
        <div class="bg-white/5 backdrop-blur-md rounded-2xl border border-white/10 p-8 mb-12">
            <h2 class="text-2xl font-bold text-white mb-6">About Egfollow</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <div>
                    <h3 class="text-lg font-semibold text-purple-400 mb-3">Features</h3>
                    <ul class="space-y-2 text-white/70">
                        <li class="flex items-center gap-2">
                            <svg class="w-5 h-5 text-green-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                    d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                                    clip-rule="evenodd" />
                            </svg>
                            Fast and responsive
                        </li>
                        <li class="flex items-center gap-2">
                            <svg class="w-5 h-5 text-green-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                    d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                                    clip-rule="evenodd" />
                            </svg>
                            Modern design
                        </li>
                        <li class="flex items-center gap-2">
                            <svg class="w-5 h-5 text-green-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                    d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                                    clip-rule="evenodd" />
                            </svg>
                            Secure
                        </li>
                    </ul>
                </div>
                <div>
                    <h3 class="text-lg font-semibold text-pink-400 mb-3">Requirements</h3>
                    <ul class="space-y-2 text-white/70">
                        <li>Android 8.0 or higher</li>
                        <li>Minimum 50MB free space</li>
                        <li>Internet connection required</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="border-t border-white/10 bg-white/5 backdrop-blur-md py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center text-white/60 text-sm">
            <p>&copy; 2025 Egfollow. All rights reserved.</p>
        </div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            loadApks();
        });

        function loadApks() {
            fetch("{{ route('apk.list') }}")
                .then(response => response.json())
                .then(apks => {
                    renderApks(apks);
                })
                .catch(error => {
                    console.error('Error loading APKs:', error);
                    document.getElementById('apk-grid').innerHTML = '<div class="col-span-full text-center py-12 text-white/60">Error loading APKs. Please try again later.</div>';
                });
        }

        function renderApks(apks) {
            const grid = document.getElementById('apk-grid');
            const liveApks = apks.filter(apk => apk.status === 'live');

            // Update stats
            const totalDownloads = apks.reduce((sum, apk) => sum + apk.download_count, 0);
            document.getElementById('stat-downloads').textContent = totalDownloads.toLocaleString();
            document.getElementById('stat-versions').textContent = apks.length;
            document.getElementById('stat-users').textContent = (totalDownloads * 0.85).toLocaleString(undefined, { maximumFractionDigits: 0 });

            if (liveApks.length === 0) {
                grid.innerHTML = '<div class="col-span-full text-center py-12 text-white/60">No APK versions available for download.</div>';
                return;
            }

            // Set latest version
            if (liveApks.length > 0) {
                document.getElementById('latest-version').textContent = liveApks[0].version;
            }

            grid.innerHTML = liveApks.map(apk => {
                const sizeInMb = (apk.file_size / (1024 * 1024)).toFixed(1);
                const uploadDate = new Date(apk.created_at).toLocaleDateString();

                return `
                    <div class="bg-white/10 backdrop-blur-md rounded-xl border border-white/10 p-6 hover:border-purple-500/50 transition-all hover:bg-white/15">
                        <div class="flex items-start justify-between mb-4">
                            <div>
                                <h3 class="text-xl font-bold text-white">v${apk.version}</h3>
                                <p class="text-sm text-white/60">Released ${uploadDate}</p>
                            </div>
                            <span class="px-3 py-1 text-xs font-medium rounded-full bg-green-500/20 text-green-300">Live</span>
                        </div>

                        <div class="space-y-3 mb-6">
                            <div class="flex justify-between text-sm">
                                <span class="text-white/60">File Size</span>
                                <span class="text-white font-semibold">${sizeInMb} MB</span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-white/60">Downloads</span>
                                <span class="text-white font-semibold">${apk.download_count.toLocaleString()}</span>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <a href="#" onclick="downloadApk(event, ${apk.id})" class="w-full py-3 px-4 bg-gradient-to-r from-purple-600 to-pink-600 text-white font-semibold rounded-lg hover:opacity-90 transition-opacity flex items-center justify-center gap-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                </svg>
                                Download APK
                            </a>
                            ${apk.play_store_url ? `<a href="${apk.play_store_url}" target="_blank" rel="noopener" class="w-full py-2 px-4 border border-white/20 text-white font-semibold rounded-lg hover:bg-white/10 transition-colors flex items-center justify-center gap-2">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-5-9h10v2H7z"/>
                                </svg>
                                Play Store
                            </a>` : ''}
                        </div>
                    </div>
                `;
            }).join('');
        }

        function downloadApk(e, apkId) {
            if (e) e.preventDefault();
            if (!apkId) return;

            const btn = e.currentTarget;
            
            // Add loading state
            const originalOpacity = btn.style.opacity;
            btn.style.opacity = '0.7';
            btn.style.pointerEvents = 'none';

            fetch(`/api/apk/generate-link/${apkId}`)
                .then(res => res.json())
                .then(data => {
                    // Revert state
                    btn.style.opacity = originalOpacity || '1';
                    btn.style.pointerEvents = 'auto';

                    if (data.status === 'success') {
                        window.location.href = data.url;
                    } else {
                        alert(data.message || 'Error generating download link.');
                    }
                })
                .catch(err => {
                    btn.style.opacity = originalOpacity || '1';
                    btn.style.pointerEvents = 'auto';
                    alert('An error occurred while generating the secure download link.');
                    console.error(err);
                });
        }
    </script>
</body>

</html>