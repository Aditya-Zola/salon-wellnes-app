<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Selesa Salon - Sistem Operasional')</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20,300,0,-25&display=block" rel="stylesheet">
    @vite('resources/css/salon.css')
</head>
<body class="ui-shell">
<div class="app">
    @include('partials.internal-sidebar')

    <main class="access-main">
        <header class="access-header">
            <div>
                <h1>@yield('heading')</h1>
                <p>@yield('subtitle')</p>
            </div>
            <div class="header-actions">
                @yield('header-action')
            </div>
        </header>

        <div class="access-content">
            @if (session('error'))
                <div class="alert alert-error"><strong>Tindakan tidak dapat dilakukan.</strong><span>{{ session('error') }}</span></div>
            @endif
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
        <i class="material-symbols-outlined" aria-hidden="true">check_circle</i>
        <div>
            <strong>Berhasil</strong>
            <span>{{ session('success') }}</span>
        </div>
        <button type="button" class="material-symbols-outlined" aria-label="Tutup notifikasi">close</button>
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
