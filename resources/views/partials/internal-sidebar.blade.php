@php
    $user = auth()->user();
    $isDashboard = request()->routeIs('dashboard');
    $modules = [
        ['page' => 'membership', 'label' => 'Membership', 'icon' => 'workspace_premium', 'permission' => 'memberships.view'],
        ['page' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard', 'permission' => 'dashboard.view'],
        [
            'page' => 'reservasi',
            'label' => 'Reservasi',
            'icon' => 'calendar_month',
            'permission' => 'reservations.view',
            'children' => [
                ['page' => 'reservasi-antrean', 'label' => 'Antrean Hari Ini'],
                ['page' => 'reservasi-kalender', 'label' => 'Kalender'],
            ],
        ],
        ['page' => 'kehadiran-terapis', 'label' => 'Kehadiran Terapis', 'icon' => 'how_to_reg', 'permission' => 'therapist_attendance.view'],
        [
            'page' => 'stok',
            'label' => 'Produk & Stok',
            'icon' => 'inventory_2',
            'permission' => 'products.view',
            'children' => [
                ['page' => 'stok', 'label' => 'Daftar Produk'],
                ['page' => 'stok-riwayat', 'label' => 'Riwayat Keluar-Masuk'],
            ],
        ],
        ['page' => 'treatment', 'label' => 'Treatment', 'icon' => 'spa', 'permission' => 'treatments.view'],
        ['page' => 'kasir', 'label' => 'Kasir', 'icon' => 'point_of_sale', 'permission' => 'cashier.view'],
        ['page' => 'penjualan', 'label' => 'Penjualan', 'icon' => 'receipt_long', 'permission' => 'sales.view'],
        [
            'page' => 'keuangan',
            'label' => 'Keuangan',
            'icon' => 'payments',
            'permission' => 'finance.view',
            'children' => [
                ['page' => 'keuangan-arus-kas', 'label' => 'Arus Kas'],
                ['page' => 'keuangan-laba-rugi', 'label' => 'Laba-Rugi'],
                ['page' => 'keuangan-neraca', 'label' => 'Neraca'],
            ],
        ],
        [
            'page' => 'penggajian',
            'label' => 'Remunerasi',
            'icon' => 'account_balance_wallet',
            'permission' => 'payroll.view',
            'children' => [
                ['page' => 'panduan-remunerasi', 'label' => 'Panduan Remunerasi'],
                ['page' => 'penggajian', 'label' => 'Penggajian'],
                ['page' => 'remunerasi', 'label' => 'Rekap & Export Excel'],
            ],
        ],
    ];
@endphp

<aside class="sidebar">
    <a class="brand dashboard-brand" href="{{ route('dashboard') }}" aria-label="Selesa Salon - Dashboard">
        <img src="{{ asset('images/selesa-logo.png') }}?v={{ filemtime(public_path('images/selesa-logo.png')) }}" alt="Selesa Salon, Spa, Wellness, Nail, dan Eyelash" width="170" height="56">
    </a>

    <nav id="navigation" aria-label="Navigasi utama">
        @foreach ($modules as $module)
            @can($module['permission'])
                @if (!empty($module['children']))
                    <details class="access-menu {{ match ($module['page']) { 'reservasi' => 'reservation-menu', 'stok' => 'stock-menu', 'keuangan' => 'finance-menu', default => '' } }}">
                        <summary>
                            <b class="material-symbols-outlined nav-icon">{{ $module['icon'] }}</b>
                            <span>{{ $module['label'] }}</span>
                            <i class="material-symbols-outlined">chevron_right</i>
                        </summary>
                        <div class="access-submenu">
                            @foreach ($module['children'] as $child)
                                @if ($isDashboard)
                                    <button type="button" data-page="{{ $child['page'] }}">{{ $child['label'] }}</button>
                                @else
                                    <a href="{{ route('dashboard') }}#{{ $child['page'] }}">{{ $child['label'] }}</a>
                                @endif
                            @endforeach
                        </div>
                    </details>
                @elseif ($isDashboard)
                    <button type="button" class="{{ $module['page'] === 'dashboard' ? 'active' : '' }}" data-page="{{ $module['page'] }}">
                        <b class="material-symbols-outlined nav-icon">{{ $module['icon'] }}</b>
                        <span>{{ $module['label'] }}</span>
                    </button>
                @else
                    <a href="{{ route('dashboard') }}#{{ $module['page'] }}">
                        <b class="material-symbols-outlined nav-icon">{{ $module['icon'] }}</b>
                        <span>{{ $module['label'] }}</span>
                    </a>
                @endif
            @endcan
        @endforeach

        @canany(['access.roles.view', 'access.users.view'])
            <details class="access-menu" @if(request()->routeIs('access.*')) open @endif>
                <summary class="{{ request()->routeIs('access.*') ? 'active' : '' }}">
                    <b class="material-symbols-outlined nav-icon">admin_panel_settings</b>
                    <span>Hak Akses</span>
                    <i class="material-symbols-outlined">chevron_right</i>
                </summary>
                <div class="access-submenu">
                    @can('access.roles.view')
                        <a class="{{ request()->routeIs('access.roles.*') ? 'active' : '' }}" href="{{ route('access.roles.index') }}">
                            <span>Peran</span>
                        </a>
                    @endcan
                    @can('access.users.view')
                        <a class="{{ request()->routeIs('access.users.*') ? 'active' : '' }}" href="{{ route('access.users.index') }}">
                            <span>Pengguna & Karyawan</span>
                        </a>
                    @endcan
                </div>
            </details>
        @endcanany

        @can('settings.manage')
            <details class="access-menu" @if(request()->routeIs('settings.*')) open @endif>
                <summary class="{{ request()->routeIs('settings.*') ? 'active' : '' }}">
                    <b class="material-symbols-outlined nav-icon">settings</b>
                    <span>Pengaturan</span>
                    <i class="material-symbols-outlined">chevron_right</i>
                </summary>
                <div class="access-submenu">
                    <a class="{{ request()->routeIs('settings.sale*') ? 'active' : '' }}" href="{{ route('settings.sale') }}"><span>Penjualan</span></a>
                    <a class="{{ request()->routeIs('settings.payment-methods.*') && request()->route('section') === 'edc' ? 'active' : '' }}" href="{{ route('settings.payment-methods.index', 'edc') }}"><span>EDC</span></a>
                    <a class="{{ request()->routeIs('settings.payment-methods.*') && request()->route('section') === 'bank' ? 'active' : '' }}" href="{{ route('settings.payment-methods.index', 'bank') }}"><span>Bank</span></a>
                    <a class="{{ request()->routeIs('settings.payment-methods.*') && request()->route('section') === 'qris' ? 'active' : '' }}" href="{{ route('settings.payment-methods.index', 'qris') }}"><span>QRIS</span></a>
                </div>
            </details>
        @endcan

        @can('activity.view')
            @if ($isDashboard)
                <button type="button" data-page="log">
                    <b class="material-symbols-outlined nav-icon">history</b>
                    <span>Log Aktivitas</span>
                </button>
            @else
                <a href="{{ route('dashboard') }}#log">
                    <b class="material-symbols-outlined nav-icon">history</b>
                    <span>Log Aktivitas</span>
                </a>
            @endif
        @endcan
    </nav>

    <div class="account">
        <i>{{ strtoupper(substr($user->name, 0, 2)) }}</i>
        <div>
            <strong>{{ $user->name }}</strong>
            <small>{{ $user->role_name }}</small>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="logout" title="Keluar dari sistem" aria-label="Keluar dari sistem">
                <b class="material-symbols-outlined" aria-hidden="true">logout</b><span>Logout</span>
            </button>
        </form>
    </div>
</aside>

<div class="sidebar-scrim" id="sidebar-scrim" aria-hidden="true"></div>

<button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-controls="app-sidebar" aria-expanded="true" aria-label="Tutup sidebar" title="Tutup sidebar">
    <span class="material-symbols-outlined" aria-hidden="true">menu_open</span>
</button>

<script>
(() => {
    const sidebar = document.querySelector('.sidebar');
    const toggle = document.getElementById('sidebar-toggle');
    if (!sidebar || !toggle) return;

    sidebar.id = 'app-sidebar';
    const storageKey = 'selesa-sidebar-collapsed';
    const mobile = window.matchMedia('(max-width: 760px)');
    const scrim = document.getElementById('sidebar-scrim');
    let desktopCollapsed = false;
    try { desktopCollapsed = localStorage.getItem(storageKey) === 'true'; } catch {}
    const updateToggle = (expanded) => {
        toggle.setAttribute('aria-expanded', String(expanded));
        toggle.setAttribute('aria-label', expanded ? 'Tutup menu navigasi' : 'Buka menu navigasi');
        toggle.title = expanded ? 'Tutup menu navigasi' : 'Buka menu navigasi';
        toggle.querySelector('span').textContent = expanded ? (mobile.matches ? 'close' : 'menu_open') : 'menu';
    };
    const setCollapsed = (collapsed) => {
        desktopCollapsed = collapsed;
        document.body.classList.toggle('sidebar-is-collapsed', collapsed);
        updateToggle(!collapsed);
        try { localStorage.setItem(storageKey, String(collapsed)); } catch {}
    };
    const setMobileOpen = (open, returnFocus = false) => {
        document.body.classList.toggle('sidebar-mobile-open', open);
        sidebar.inert = !open;
        updateToggle(open);
        if (returnFocus) toggle.focus();
    };
    const syncViewport = () => {
        document.body.classList.remove('sidebar-mobile-open');
        document.body.classList.toggle('sidebar-is-collapsed', !mobile.matches && desktopCollapsed);
        sidebar.inert = mobile.matches;
        updateToggle(!mobile.matches && !desktopCollapsed);
    };

    sidebar.querySelectorAll('nav > button, nav > a, .access-menu > summary').forEach(item => {
        const label = item.querySelector('span')?.textContent.trim();
        if (label) { item.title = label; item.setAttribute('aria-label', label); }
    });
    syncViewport();
    mobile.addEventListener('change', syncViewport);
    toggle.addEventListener('click', () => {
        if (mobile.matches) setMobileOpen(!document.body.classList.contains('sidebar-mobile-open'));
        else setCollapsed(!desktopCollapsed);
    });
    scrim?.addEventListener('click', () => setMobileOpen(false, true));
    sidebar.addEventListener('click', event => {
        if (!mobile.matches && desktopCollapsed && event.target.closest('summary')) setCollapsed(false);
        if (mobile.matches && event.target.closest('a, button[data-page]')) setMobileOpen(false, true);
    });
    document.addEventListener('keydown', event => {
        if (!mobile.matches || !document.body.classList.contains('sidebar-mobile-open')) return;
        if (event.key === 'Escape') { event.preventDefault(); setMobileOpen(false, true); }
        if (event.key === 'Tab') {
            const focusable = [...sidebar.querySelectorAll('a, button, summary, input')].filter(item => !item.disabled && item.getClientRects().length);
            focusable.push(toggle);
            const current = focusable.indexOf(document.activeElement);
            if (event.shiftKey && current <= 0) { event.preventDefault(); toggle.focus(); }
            else if (!event.shiftKey && (current === -1 || current === focusable.length - 1)) { event.preventDefault(); focusable[0]?.focus(); }
        }
    });
})();
</script>
