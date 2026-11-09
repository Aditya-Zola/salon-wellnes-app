<!DOCTYPE html>
<html lang="id" data-theme="selesa">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk - Selesa Salon</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @vite('resources/css/login.css')
</head>
<body class="min-h-screen bg-base-200 font-sans text-base-content">
<main class="grid min-h-screen lg:grid-cols-2">
    <section class="relative isolate hidden flex-col justify-between overflow-hidden border-r border-base-300 bg-[#102B50] p-12 text-white lg:flex" aria-label="Selesa Salon">
        <img class="absolute inset-0 -z-20 h-full w-full object-cover" src="{{ asset('images/login-salon-exterior.webp') }}" alt="" aria-hidden="true">
        <img class="w-44 brightness-0 invert" src="{{ asset('images/selesa-logo.png') }}" alt="Selesa Salon">
        <div class="max-w-md">
            <h1 class="text-4xl font-semibold leading-tight tracking-tight">Kelola salon dengan lebih tenang.</h1>
            <p class="mt-4 text-white/80">Reservasi, pelayanan, dan transaksi dalam satu tempat.</p>
        </div>
        <p class="text-xs text-white/70">Jl. Telaga Asmara, Tlogosari Kulon, Semarang<br>&copy; {{ now()->year }} Selesa Salon</p>
    </section>
    <section class="grid place-items-center px-5 py-12 sm:px-10">
        <form class="card w-full max-w-md gap-5 border border-base-300 bg-base-100 p-6 sm:p-8 shadow-sm" method="POST" action="{{ route('login.store') }}">
            @csrf
            <header class="mb-2">
                <img class="mb-8 w-32 lg:hidden" src="{{ asset('images/selesa-logo.png') }}" alt="Selesa Salon">
                <h2 class="text-2xl font-semibold">Selamat datang</h2>
            </header>
            @if ($errors->any())
                <div class="alert alert-error alert-soft text-sm" role="alert" id="login-error">{{ $errors->first() }}</div>
            @endif
            <label class="grid gap-2 text-sm" for="username">Username
                <input class="input w-full" id="username" type="text" name="username" value="{{ old('username') }}"
                    placeholder="Masukkan username" autocomplete="username" autocapitalize="none" spellcheck="false"
                    @if ($errors->any()) aria-invalid="true" aria-describedby="login-error" @endif required autofocus>
            </label>
            <div class="grid gap-2">
                <label class="text-sm" for="password">Kata sandi</label>
                <div class="join w-full">
                    <input class="input join-item min-w-0 flex-1" id="password" type="password" name="password" placeholder="Masukkan kata sandi" autocomplete="current-password" required>
                    <button class="btn join-item btn-primary" type="button" id="toggle-password" aria-controls="password" aria-label="Tampilkan kata sandi" aria-pressed="false">Lihat</button>
                </div>
            </div>
            <label class="flex cursor-pointer items-center gap-3 text-sm">
                <input class="checkbox checkbox-primary checkbox-sm" type="checkbox" name="remember" @checked(old('remember'))>
                <span>Ingat saya</span>
            </label>
            <button class="btn w-full btn-primary" type="submit">Masuk</button>
        </form>
    </section>
</main>

<script>
    document.getElementById('toggle-password').addEventListener('click', function () {
        const password = document.getElementById('password');
        const isHidden = password.type === 'password';

        password.type = isHidden ? 'text' : 'password';
        this.textContent = isHidden ? 'Sembunyikan' : 'Lihat';
        this.setAttribute('aria-label', isHidden ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi');
        this.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
    });
</script>
</body>
</html>
