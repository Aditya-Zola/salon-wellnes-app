<!DOCTYPE html>
<html lang="id" data-theme="selesa">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Selesa Salon - Sistem Operasional')</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20,300,0,-25&display=block" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20,400,0,0&display=block" rel="stylesheet">
    @vite('resources/css/salon.css')
</head>
<body class="ui-shell min-h-screen bg-canvas text-base-content font-sans">
<div class="app">
    @include('partials.internal-sidebar')

    <main class="access-main">
        <header class="access-header grid grid-cols-1 sm:grid-cols-[minmax(0,1fr)_auto] items-center gap-3 min-h-20 border-b border-base-300 bg-base-100 px-5 py-4 mb-6">
            <div>
                <h1>@yield('heading')</h1>
                <p class="hidden sm:block">@yield('subtitle')</p>
            </div>
            <div class="header-actions flex flex-wrap items-center gap-2 sm:justify-end">@yield('header-action')</div>
        </header>

        <div class="access-content space-y-5">
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
    <div class="success-toast alert alert-success fixed bottom-5 right-5 z-50 w-auto max-w-[calc(100vw-2rem)]" id="success-toast" role="status" aria-live="polite">
        <i class="material-symbols-outlined" aria-hidden="true">check_circle</i>
        <div>
            <strong>Berhasil</strong>
            <span>{{ session('success') }}</span>
        </div>
        <button type="button" class="material-symbols-outlined btn btn-sm btn-info" aria-label="Tutup notifikasi">close</button>
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
