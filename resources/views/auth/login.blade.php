<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk - Selesa Salon</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/login.css') }}?v={{ filemtime(public_path('css/login.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/login-redesign.css') }}?v={{ filemtime(public_path('css/login-redesign.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/typography.css') }}">
</head>
<body>
<main class="login-layout">
    <section class="identity" aria-label="Tentang Selesa Salon">
        <div class="brand-logo">
            <img src="{{ asset('images/selesa-logo.png') }}?v={{ filemtime(public_path('images/selesa-logo.png')) }}" alt="Selesa Salon, Spa, Wellness, Nail, dan Eyelash">
        </div>

        <div class="identity-copy">
            <small>SISTEM OPERASIONAL INTERNAL</small>
            <h1>Kelola salon dengan lebih tenang.</h1>
            <p>Reservasi, pelayanan, stok, transaksi, dan laporan dalam satu tempat.</p>
        </div>

        <p class="address">Jl. Telaga Asmara, Tlogosari Kulon, Semarang</p>
    </section>

    <section class="login-panel">
        <form method="POST" action="{{ route('login.store') }}">
            @csrf

            <header>
                <div class="mobile-brand-logo">
                    <img src="{{ asset('images/selesa-logo.png') }}?v={{ filemtime(public_path('images/selesa-logo.png')) }}" alt="Selesa Salon">
                </div>
                <h2>Selamat datang</h2>
                <p>Masukkan username dan kata sandi untuk melanjutkan.</p>
            </header>

            @if ($errors->any())
                <div class="error" role="alert">{{ $errors->first() }}</div>
            @endif

            <label for="username">Username</label>
            <input
                id="username"
                type="text"
                name="username"
                value="{{ old('username') }}"
                placeholder="Contoh: kasir.selesa"
                autocomplete="username"
                required
                autofocus
            >

            <label for="password">Kata sandi</label>
            <div class="password">
                <input
                    id="password"
                    type="password"
                    name="password"
                    placeholder="Masukkan kata sandi"
                    autocomplete="current-password"
                    required
                >
                <button type="button" id="toggle-password" aria-controls="password" aria-pressed="false">Lihat</button>
            </div>

            <label class="remember">
                <input type="checkbox" name="remember" @checked(old('remember'))>
                <span>Ingat saya di perangkat ini</span>
            </label>

            <button class="submit" type="submit">Masuk ke sistem <span aria-hidden="true">→</span></button>
            <p class="help">Hubungi Super Admin jika Anda lupa akun atau kata sandi.</p>
        </form>
    </section>
</main>

<script>
    document.getElementById('toggle-password').addEventListener('click', function () {
        const password = document.getElementById('password');
        const isHidden = password.type === 'password';

        password.type = isHidden ? 'text' : 'password';
        this.textContent = isHidden ? 'Sembunyikan' : 'Lihat';
        this.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
    });
</script>
</body>
</html>
