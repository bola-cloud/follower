@extends('layouts.reactx')

@section('title','APK & Downloads Dashboard')

@section('content')
  <!-- Display success/error messages -->
  @if ($message = Session::get('success'))
    <div class="mb-6 p-4 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 rounded-lg">
      {{ $message }}
    </div>
  @endif

  @if ($message = Session::get('error'))
    <div class="mb-6 p-4 bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 rounded-lg">
      {{ $message }}
    </div>
  @endif

  <!-- AJAX flash messages (injected by JS) -->
  <div id="ajax-flash"></div>

  <!-- Page Header -->
  <div class="mb-8">
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-white">APK & Downloads Dashboard</h1>
    <p class="mt-2 text-gray-600 dark:text-gray-400">Manage your mobile app APK files and monitor download statistics</p>
  </div>

  <!-- Statistics Cards -->
  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6 mb-8">
    <!-- Total Downloads -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl p-6 shadow-sm border border-gray-100 dark:border-gray-700">
      <div class="flex items-center justify-between">
        <div>
          <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Downloads</p>
          <p class="text-3xl font-bold text-gray-900 dark:text-white mt-1" id="total-downloads">0</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center">
          <svg class="w-6 h-6 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
          </svg>
        </div>
      </div>
      <div class="mt-4 flex items-center gap-2">
        <span class="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">+12.5%</span>
        <span class="text-xs text-gray-500 dark:text-gray-400">vs last week</span>
      </div>
    </div>

    <!-- Active APKs -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl p-6 shadow-sm border border-gray-100 dark:border-gray-700">
      <div class="flex items-center justify-between">
        <div>
          <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Active APKs</p>
          <p class="text-3xl font-bold text-gray-900 dark:text-white mt-1" id="active-apks">0</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-pink-100 dark:bg-pink-900/30 flex items-center justify-center">
          <svg class="w-6 h-6 text-pink-600 dark:text-pink-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
          </svg>
        </div>
      </div>
      <div class="mt-4 flex items-center gap-2">
        <span class="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">+2</span>
        <span class="text-xs text-gray-500 dark:text-gray-400">from last week</span>
      </div>
    </div>

    <!-- Total APKs -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl p-6 shadow-sm border border-gray-100 dark:border-gray-700">
      <div class="flex items-center justify-between">
        <div>
          <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total APKs</p>
          <p class="text-3xl font-bold text-gray-900 dark:text-white mt-1" id="total-apks">0</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
          <svg class="w-6 h-6 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
          </svg>
        </div>
      </div>
      <div class="mt-4 flex items-center gap-2">
        <span class="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">+5.3%</span>
        <span class="text-xs text-gray-500 dark:text-gray-400">vs last week</span>
      </div>
    </div>

    <!-- Crash Rate -->
    {{-- <div class="bg-white dark:bg-gray-800 rounded-2xl p-6 shadow-sm border border-gray-100 dark:border-gray-700">
      <div class="flex items-center justify-between">
        <div>
          <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Crash Rate</p>
          <p class="text-3xl font-bold text-gray-900 dark:text-white mt-1">0.12%</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-orange-100 dark:bg-orange-900/30 flex items-center justify-center">
          <svg class="w-6 h-6 text-orange-600 dark:text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
          </svg>
        </div>
      </div>
      <div class="mt-4 flex items-center gap-2">
        <span class="px-2 py-1 text-xs font-medium rounded-full bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400">+0.02%</span>
        <span class="text-xs text-gray-500 dark:text-gray-400">vs last week</span>
      </div>
    </div> --}}
  </div>

  <!-- APK Management & Chart Row -->
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <!-- APK Management Card -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl p-6 shadow-sm border border-gray-100 dark:border-gray-700">
      <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-6">APK Management</h2>
      <form class="space-y-5" method="POST" action="{{ route('admin.apk.store') }}" enctype="multipart/form-data">
        @csrf
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Version Number</label>
          <input
            type="text"
            name="version"
            placeholder="e.g., 2.4.1"
            class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all"
            required
          >
          <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Enter the APK version (e.g., 2.4.1)</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Google Play Store URL</label>
          <input
            type="url"
            name="play_url"
            placeholder="https://play.google.com/store/apps/details?id=..."
            class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all"
          >
          <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Enter the full Play Store URL to sync app information</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Upload APK File</label>
          <div class="relative">
            <input
              type="file"
              name="apk_file"
              accept=".apk"
              class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-white file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-purple-100 file:text-purple-700 dark:file:bg-purple-900/30 dark:file:text-purple-400 hover:file:bg-purple-200 dark:hover:file:bg-purple-900/50 cursor-pointer transition-all"
              required
            >
          </div>
          <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Maximum file size: 150MB. Supported format: .apk</p>
        </div>
        <button
          type="submit"
          class="w-full py-3 px-6 rounded-xl bg-gradient-to-r from-purple-600 to-pink-600 text-white font-semibold shadow-lg shadow-purple-500/30 hover:shadow-purple-500/50 hover:scale-[1.02] active:scale-[0.98] transition-all duration-200"
        >
          Save & Upload
        </button>
      </form>
    </div>

    <!-- Downloads Chart -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl p-6 shadow-sm border border-gray-100 dark:border-gray-700">
      <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-6">Downloads (Last 7 Days)</h2>
      <div class="flex items-end justify-between gap-2 h-48">
        <div class="flex-1 flex flex-col items-center gap-2">
          <div class="w-full bg-gradient-to-t from-purple-500 to-pink-500 rounded-t-lg" style="height: 60%;"></div>
          <span class="text-xs text-gray-500 dark:text-gray-400">Mon</span>
        </div>
        <div class="flex-1 flex flex-col items-center gap-2">
          <div class="w-full bg-gradient-to-t from-purple-500 to-pink-500 rounded-t-lg" style="height: 80%;"></div>
          <span class="text-xs text-gray-500 dark:text-gray-400">Tue</span>
        </div>
        <div class="flex-1 flex flex-col items-center gap-2">
          <div class="w-full bg-gradient-to-t from-purple-500 to-pink-500 rounded-t-lg" style="height: 45%;"></div>
          <span class="text-xs text-gray-500 dark:text-gray-400">Wed</span>
        </div>
        <div class="flex-1 flex flex-col items-center gap-2">
          <div class="w-full bg-gradient-to-t from-purple-500 to-pink-500 rounded-t-lg" style="height: 90%;"></div>
          <span class="text-xs text-gray-500 dark:text-gray-400">Thu</span>
        </div>
        <div class="flex-1 flex flex-col items-center gap-2">
          <div class="w-full bg-gradient-to-t from-purple-500 to-pink-500 rounded-t-lg" style="height: 70%;"></div>
          <span class="text-xs text-gray-500 dark:text-gray-400">Fri</span>
        </div>
        <div class="flex-1 flex flex-col items-center gap-2">
          <div class="w-full bg-gradient-to-t from-purple-500 to-pink-500 rounded-t-lg" style="height: 55%;"></div>
          <span class="text-xs text-gray-500 dark:text-gray-400">Sat</span>
        </div>
        <div class="flex-1 flex flex-col items-center gap-2">
          <div class="w-full bg-gradient-to-t from-purple-500 to-pink-500 rounded-t-lg" style="height: 100%;"></div>
          <span class="text-xs text-gray-500 dark:text-gray-400">Sun</span>
        </div>
      </div>
      <div class="mt-6 flex items-center justify-between text-sm">
        <div class="flex items-center gap-2">
          <div class="w-3 h-3 rounded-full bg-gradient-to-r from-purple-500 to-pink-500"></div>
          <span class="text-gray-600 dark:text-gray-400" id="download-total">Total: 0 downloads</span>
        </div>
        <span class="text-green-600 dark:text-green-400 font-medium">↑ 12% from last week</span>
      </div>
    </div>
  </div>

  <!-- Recent APK Uploads Table -->
  <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden">
    <div class="p-6 border-b border-gray-100 dark:border-gray-700">
      <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Recent APK Uploads</h2>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full">
        <thead class="bg-gray-50 dark:bg-gray-700/50">
          <tr>
            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Version</th>
            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">File Name</th>
            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Upload Date</th>
            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Size</th>
            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Downloads</th>
            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-gray-700" id="apk-table-body">
          <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors">
            <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400 text-center" colspan="7">No APK files uploaded yet</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <script>
    // Load APK statistics on page load
    document.addEventListener('DOMContentLoaded', function() {
      loadApkData();
      // Refresh every 30 seconds
      setInterval(loadApkData, 30000);
    });

    function loadApkData() {
      fetch('{{ route("admin.apk.stats") }}')
        .then(response => response.json())
        .then(data => {
          document.getElementById('total-downloads').textContent = data.total_downloads.toLocaleString();
          document.getElementById('download-total').textContent = 'Total: ' + data.total_downloads.toLocaleString() + ' downloads';
        })
        .catch(error => console.error('Error loading stats:', error));

      // Load APK list
      fetch("{{ route('admin.apk.list') }}")
        .then(response => response.json())
        .then(apks => {
          renderApkTable(apks);
        })
        .catch(error => console.error('Error loading APKs:', error));
    }

    function renderApkTable(apks) {
      const tbody = document.getElementById('apk-table-body');

      if (apks.length === 0) {
        tbody.innerHTML = '<tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors"><td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400 text-center" colspan="7">No APK files uploaded yet</td></tr>';
        document.getElementById('active-apks').textContent = '0';
        document.getElementById('total-apks').textContent = '0';
        return;
      }

      let liveCount = 0;
      tbody.innerHTML = apks.map(apk => {
        if (apk.status === 'live') liveCount++;

        const sizeInMb = (apk.file_size / (1024 * 1024)).toFixed(1);
        const uploadDate = new Date(apk.created_at).toLocaleDateString();

        return `
          <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors">
            <td class="px-6 py-4 text-sm font-medium text-gray-900 dark:text-white">v${apk.version}</td>
            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">${apk.file_name}</td>
            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">${uploadDate}</td>
            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">${sizeInMb} MB</td>
            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300 font-semibold">${apk.download_count}</td>
            <td class="px-6 py-4">
              <span class="px-3 py-1 text-xs font-medium rounded-full ${apk.status === 'live' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : apk.status === 'pending' ? 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400' : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'} capitalize">${apk.status}</span>
            </td>
            <td class="px-6 py-4">
              <div class="flex items-center gap-2">
                <a href="${apk.download_url}" class="p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-600 text-gray-500 dark:text-gray-400 transition-colors" title="Download">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                  </svg>
                </a>
                <button onclick="deleteApk(${apk.id})" class="p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-600 text-gray-500 dark:text-gray-400 transition-colors" title="Delete">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                  </svg>
                </button>
              </div>
            </td>
          </tr>
        `;
      }).join('');

      document.getElementById('active-apks').textContent = liveCount;
      document.getElementById('total-apks').textContent = apks.length;
    }

    const apkDeleteBase = "{{ url('front/admin/apk') }}";

    function deleteApk(id) {
      if (confirm('Are you sure you want to delete this APK?')) {
        fetch(`${apkDeleteBase}/${id}`, {
          method: 'DELETE',
          headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          }
        })
        .then(response => response.json().then(body => ({ ok: response.ok, status: response.status, body })))
        .then(result => {
          if (result.ok) {
            showMessage(result.body.message || 'APK deleted successfully!', 'success');
            loadApkData();
          } else {
            showMessage(result.body.message || 'Failed to delete APK', 'error');
          }
        })
        .catch(error => {
          console.error('Error deleting APK:', error);
          showMessage('An error occurred while deleting the APK', 'error');
        });
      }
    }

    function showMessage(message, type = 'success') {
      const container = document.getElementById('ajax-flash');
      if (!container) return;
      const bg = type === 'success' ? 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400' : 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400';
      container.innerHTML = `<div class="mb-6 p-4 ${bg} rounded-lg">${message}</div>`;
      // Auto-hide after 6 seconds
      setTimeout(() => { container.innerHTML = ''; }, 6000);
      // Scroll to top so the message is visible
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // Prevent submitting files larger than server/app limits to avoid PostTooLargeException
    (function(){
      const form = document.querySelector('form[action="{{ route('admin.apk.store') }}"]');
      if (!form) return;

      form.addEventListener('submit', function(e){
        const fileInput = form.querySelector('input[type="file"][name="apk_file"]');
        if (!fileInput || !fileInput.files || fileInput.files.length === 0) return;

        const file = fileInput.files[0];

        // Parse PHP upload_max_filesize (e.g., 40M)
        const phpMax = "{{ ini_get('upload_max_filesize') }}"; // string like '40M'
        const match = phpMax.match(/^(\d+)([KMGkmg]?)$/);
        let phpMaxBytes = 0;
        if (match) {
          let n = parseInt(match[1], 10);
          const unit = (match[2] || '').toUpperCase();
          if (unit === 'G') n = n * 1024 * 1024 * 1024;
          else if (unit === 'M') n = n * 1024 * 1024;
          else if (unit === 'K') n = n * 1024;
          phpMaxBytes = n;
        }

        // Application validator max (150MB) in bytes
        const appMaxBytes = 153600 * 1024; // 153600 KB = 150 MB

        if (phpMaxBytes && file.size > phpMaxBytes) {
          alert('Selected file exceeds server upload_max_filesize ("' + phpMax + '"). Please choose a smaller file or increase the server limits.');
          e.preventDefault();
          return false;
        }

        if (file.size > appMaxBytes) {
          alert('Selected file exceeds the application limit of 150 MB. Please choose a smaller file.');
          e.preventDefault();
          return false;
        }
      });
    })();
  </script>
@endsection
