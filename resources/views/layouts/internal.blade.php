<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Selesa Salon - Sistem Operasional')</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20,550,1,0&icon_names=admin_panel_settings,analytics,calendar_month,campaign,chevron_right,dashboard,groups,inventory_2,logout,manage_accounts,payments,point_of_sale,receipt_long,spa,store,wallet,work_history&display=block" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/salon.css') }}">
    <link rel="stylesheet" href="{{ asset('css/redesign.css') }}">
    <link rel="stylesheet" href="{{ asset('css/material-icons.css') }}">
    <link rel="stylesheet" href="{{ asset('css/typography.css') }}">
    <link rel="stylesheet" href="{{ asset('css/mockup-dashboard.css') }}?v={{ filemtime(public_path('css/mockup-dashboard.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/access-control.css') }}?v={{ filemtime(public_path('css/access-control.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/sidebar-polish.css') }}?v={{ filemtime(public_path('css/sidebar-polish.css')) }}">
</head>
<body>
<div class="app">
    @include('partials.internal-sidebar')

    <main class="access-main">
        <header class="access-header">
            <div>
                <h1>@yield('heading')</h1>
                <p>@yield('subtitle')</p>
            </div>
            @yield('header-action')
        </header>

        <div class="access-content">
            @if ($errors->any())
                <div class="alert alert-error">
                    <strong>Data belum dapat disimpan.</strong>
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @yield('content')
        </div>
    </main>
</div>

@if (session('success'))
    <div class="success-toast" id="success-toast" role="status" aria-live="polite">
        <i aria-hidden="true">✓</i>
        <div>
            <strong>Berhasil</strong>
            <span>{{ session('success') }}</span>
        </div>
        <button type="button" aria-label="Tutup notifikasi">×</button>
    </div>
@endif

@stack('scripts')
@if (session('success'))
    <script>
        const successToast = document.getElementById('success-toast');
        successToast?.querySelector('button')?.addEventListener('click', () => successToast.remove());
        window.setTimeout(() => successToast?.classList.add('is-hiding'), 4000);
        window.setTimeout(() => successToast?.remove(), 4400);
    </script>
@endif
</body>
</html>
