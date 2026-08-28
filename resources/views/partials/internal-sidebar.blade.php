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
        ['page' => 'stok', 'label' => 'Produk & Stok', 'icon' => 'inventory_2', 'permission' => 'products.view'],
        ['page' => 'treatment', 'label' => 'Treatment', 'icon' => 'spa', 'permission' => 'treatments.view'],
        ['page' => 'kasir', 'label' => 'Kasir', 'icon' => 'point_of_sale', 'permission' => 'cashier.view'],
        ['page' => 'penjualan', 'label' => 'Penjualan', 'icon' => 'receipt_long', 'permission' => 'sales.view'],
        ['page' => 'keuangan', 'label' => 'Keuangan', 'icon' => 'payments', 'permission' => 'finance.view'],
        ['page' => 'penggajian', 'label' => 'Penggajian', 'icon' => 'account_balance_wallet', 'permission' => 'payroll.view'],
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
                    <details class="access-menu reservation-menu">
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
            <button type="submit" class="logout" title="Keluar dari sistem">
                <b class="material-symbols-outlined" aria-hidden="true">logout</b><span>Logout</span>
            </button>
        </form>
    </div>
</aside>

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
    const setCollapsed = (collapsed) => {
        document.body.classList.toggle('sidebar-is-collapsed', collapsed);
        toggle.setAttribute('aria-expanded', String(!collapsed));
        toggle.setAttribute('aria-label', collapsed ? 'Buka sidebar' : 'Tutup sidebar');
        toggle.title = collapsed ? 'Buka sidebar' : 'Tutup sidebar';
        toggle.querySelector('span').textContent = collapsed ? 'menu' : 'menu_open';
        localStorage.setItem(storageKey, String(collapsed));
    };

    setCollapsed(localStorage.getItem(storageKey) === 'true');
    toggle.addEventListener('click', () => setCollapsed(!document.body.classList.contains('sidebar-is-collapsed')));
})();
</script>
