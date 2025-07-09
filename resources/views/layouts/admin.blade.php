<!DOCTYPE html>
<html class="loading" lang="en" data-textdirection="rtl">
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui">
  <title> EG Followers</title>
   <link rel="shortcut icon" type="image/x-icon" href="{{asset('logo.png')}}">
   <link rel="icon" type="image/x-icon" href="{{asset('logo.png')}}">

   <link rel="stylesheet" type="text/css" href="{{asset('fontawesome/all.min.css')}}">
  <script src="{{asset('fontawesome/js/all.min.js')}}" type="text/javascript"></script>
  <link rel="stylesheet" type="text/css" href="{{asset('app-assets/css-rtl/vendors.css')}}">
  <link rel="stylesheet" type="text/css" href="{{asset('app-assets/vendors/css/weather-icons/climacons.min.css')}}">
  <link rel="stylesheet" type="text/css" href="{{asset('app-assets/fonts/meteocons/style.css')}}">
  <link rel="stylesheet" type="text/css" href="{{asset('app-assets/vendors/css/charts/morris.css')}}">
  <link rel="stylesheet" type="text/css" href="{{asset('app-assets/vendors/css/charts/chartist.css')}}">
  <link rel="stylesheet" type="text/css" href="{{asset('app-assets/vendors/css/charts/chartist-plugin-tooltip.css')}}">
  <!-- END VENDOR CSS-->
  <!-- BEGIN MODERN CSS-->
  <link rel="stylesheet" type="text/css" href="{{asset('app-assets/css-rtl/app.css')}}">
  <link rel="stylesheet" type="text/css" href="{{asset('app-assets/css-rtl/custom-rtl.css')}}">
  <!-- END MODERN CSS-->
  <!-- BEGIN Page Level CSS-->
  <link rel="stylesheet" type="text/css" href="{{asset('app-assets/css-rtl/core/menu/menu-types/vertical-menu-modern.css')}}">
  <link rel="stylesheet" type="text/css" href="{{asset('app-assets/css-rtl/core/colors/palette-gradient.css')}}">
  <link rel="stylesheet" type="text/css" href="{{asset('app-assets/fonts/simple-line-icons/style.css')}}">
  <link rel="stylesheet" type="text/css" href="{{asset('app-assets/css-rtl/core/colors/palette-gradient.css')}}">
  <link rel="stylesheet" type="text/css" href="{{asset('app-assets/css-rtl/pages/timeline.css')}}">
  <link rel="stylesheet" type="text/css" href="{{asset('app-assets/css-rtl/pages/dashboard-ecommerce.css')}}">
  <!-- END Page Level CSS-->
  <!-- BEGIN Custom CSS-->
  <link rel="stylesheet" type="text/css" href="{{asset('assets/css/style-rtl.css')}}">
  <!-- <link rel="stylesheet"  href="{{asset('css/fontawesome.min.css')}}"> -->
  <!-- END Custom CSS-->
   <style>
    body.vertical-layout.vertical-menu-modern.menu-expanded .main-menu .navigation li.has-sub > a:not(.mm-next):after {

    font-family: 'LineAwesome';
    font-size: 1rem;
    display: none;
    position: absolute;
    left: 20px;
    top: 14px;
    -webkit-transform: rotate(0deg);
    -moz-transform: rotate(0deg);
    -ms-transform: rotate(0deg);
    -o-transform: rotate(0deg);
    transform: rotate(0deg);
    -webkit-transition: -webkit-transform 0.2s ease-in-out;
    transition: -webkit-transform 0.2s ease-in-out;
}
    </style>
</head>
<body class="vertical-layout vertical-menu-modern 2-columns   menu-expanded fixed-navbar"
data-open="click" data-menu="vertical-menu-modern" data-col="2-columns">

  <!-- Loading Spinner -->
  <!-- <div id="loading-spinner" style="display:none;">
    <div class="spinner"></div>
  </div> -->

  <!-- fixed-top-->
  <nav class="header-navbar navbar-expand-md navbar navbar-with-menu navbar-without-dd-arrow fixed-top navbar-semi-dark navbar-shadow">
    <div class="navbar-wrapper">
      <div class="navbar-header">
        <ul class="nav navbar-nav flex-row">
          <li class="nav-item mobile-menu d-md-none mr-auto"><a class="nav-link nav-menu-main menu-toggle hidden-xs" href="#"><i class="ft-menu font-large-1"></i></a></li>
          <li class="nav-item mr-auto">
            <a class="navbar-brand" href="{{route('admin.dashboard')}}">
              <img class="brand-logo"  src="{{asset('logo.png')}}">
              <h3 class="brand-text">EG Followers </h3>
            </a>
          </li>
          <li class="nav-item d-none d-md-block float-right"><a class="nav-link modern-nav-toggle pr-0" data-toggle="collapse"><i class="toggle-icon ft-toggle-right font-medium-3 white" data-ticon="ft-toggle-right"></i></a></li>
          <li class="nav-item d-md-none">
            <a class="nav-link open-navbar-container" data-toggle="collapse" data-target="#navbar-mobile"><i class="la la-ellipsis-v"></i></a>
          </li>
        </ul>
      </div>
      <div class="navbar-container content">
        <div class="collapse navbar-collapse" id="navbar-mobile">
          <ul class="nav navbar-nav float-right">
            <li class="dropdown dropdown-user nav-item">
              <a class="dropdown-toggle nav-link dropdown-user-link" href="#" data-toggle="dropdown">
                <span class="mr-1">مرحبا,
                  <span class="user-name text-bold-700"> {{Auth::user()->name}} </span>
                </span>
                <span class="avatar avatar-online">
                  <img src="{{asset('app-assets/images/portrait/small/avatar-s-19.png')}}" alt="avatar"><i></i></span>
              </a>
              <div class="dropdown-menu dropdown-menu-right"><a class="dropdown-item" href="#"><i class="ft-user"></i> تعديل بيانات الحساب </a>
                <div class="dropdown-divider"></div>
                <a class="dropdown-item" href="{{ route('logout') }}"
                  onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
                    <i class="ft-power"></i> تسجيل الخروج
                </a>

                <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display: none;">
                    @csrf
                </form>
              </div>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </nav>
  <!-- ////////////////////////////////////////////////////////////////////////////-->
  <div class="main-menu menu-fixed menu-dark menu-accordion menu-shadow" data-scroll-to-active="true">
    <div class="main-menu-content">
        <ul class="navigation navigation-main" id="main-menu-navigation" data-menu="menu-navigation">

            <li class=" nav-item"><a href="#"><i class="la la-envelope"></i><span class="menu-title" data-i18n="nav.dash.main"> المستخدمين والمدراء </a>
                <ul class="menu-content">
                    <li class="{{ Route::currentRouteName() == 'admin.users.index' ? 'active':'' }} ">
                        <a class="menu-item" href="{{route('admin.users.index')}}" data-i18n="nav.dash.ecommerce"> عرض المدراء </a>
                    </li>
                    <li class="{{ Route::currentRouteName() == 'admin.normal_users.index' ? 'active':'' }} ">
                        <a class="menu-item" href="{{route('admin.normal_users.index')}}" data-i18n="nav.dash.ecommerce"> عرض المستخدمين </a>
                    </li>
                    <li class="{{ Route::currentRouteName() == 'admin.users.create' ? 'active':'' }} ">
                        <a class="menu-item" href="{{route('admin.users.create')}}" data-i18n="nav.dash.crypto"> اضافة المدراء </a>
                    </li>
                </ul>
            </li>

        </ul>
    </div>
</div>



  <div class="app-content content">
    <div class="content-wrapper">
      <div class="content-header row">
      </div>
      <div class="content-body">
            <div class="card">
                @yield('content')
            </div>
      </div>
    </div>
  </div>
  <!-- ////////////////////////////////////////////////////////////////////////////-->
  <footer class="footer footer-static footer-light navbar-border navbar-shadow">
    <p class="clearfix blue-grey lighten-2 text-sm-center mb-0 px-2">
        <span class="float-md-left d-block d-md-inline-block">Copyright &copy;
            <a href="https://wa.me/201555622169" target="_blank" style="color: #25D366; text-decoration: none;">
                <strong>Eng: Bola Eshaq</strong>
            </a>
        </span>
      <span class="float-md-right d-block d-md-inline-blockd-none d-lg-block">01555622169
      <i class="fas fa-phone pink"></i>
      </p>
  </footer>
  <!-- BEGIN VENDOR JS-->
  <script src="{{asset('app-assets/vendors/js/vendors.min.js')}}" type="text/javascript"></script>
  <!-- BEGIN VENDOR JS-->
  <!-- BEGIN PAGE VENDOR JS-->
  <script src="{{asset('app-assets/vendors/js/charts/chartist.min.js')}}" type="text/javascript"></script>
  {{-- <script src="{{asset('app-assets/vendors/js/charts/chartist-plugin-tooltip.min.js')}}" type="text/javascript"></script>--}}
  <script src="{{asset('app-assets/vendors/js/charts/raphael-min.js')}}" type="text/javascript"></script>
  <script src="{{asset('app-assets/vendors/js/charts/morris.min.js')}}" type="text/javascript"></script>
  <script src="{{asset('app-assets/vendors/js/timeline/horizontal-timeline.js')}}" type="text/javascript"></script>
  <!-- END PAGE VENDOR JS-->
  <!-- BEGIN MODERN JS-->
  <script src="{{asset('app-assets/js/core/app-menu.js')}}" type="text/javascript"></script>
  <script src="{{asset('app-assets/js/core/app.js')}}" type="text/javascript"></script>
  <script src="{{asset('app-assets/js/scripts/customizer.js')}}" type="text/javascript"></script>
  <!-- END MODERN JS-->
  <script>
  $(document).ready(function() {
    // Show spinner on page load
    $('#loading-spinner').fadeIn();

    // Hide spinner when the document is ready
    if (document.readyState === 'complete') {
        $('#loading-spinner').fadeOut();
    } else {
        $(window).on('load', function() {
            $('#loading-spinner').fadeOut();
        });
    }
  });

  </script>
  <!-- BEGIN PAGE LEVEL JS-->
 <script src="{{asset('app-assets/js/scripts/pages/dashboard-ecommerce.js')}}" type="text/javascript"></script>

  <!-- END PAGE LEVEL JS-->
  @stack('scripts')
</body>
</html>
