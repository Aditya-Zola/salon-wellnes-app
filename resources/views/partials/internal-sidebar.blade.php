@php
    $user = auth()->user();
    $isDashboard = request()->routeIs('dashboard');
    $modules = [
        ['page' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard', 'permission' => 'dashboard.view'],
        ['page' => 'reservasi', 'label' => 'Reservasi', 'icon' => 'calendar_month', 'permission' => 'reservations.view'],
        ['page' => 'pegawai', 'label' => 'Pegawai', 'icon' => 'manage_accounts', 'permission' => 'employees.view'],
        ['page' => 'kasir', 'label' => 'Kasir', 'icon' => 'point_of_sale', 'permission' => 'cashier.view'],
        ['page' => 'treatment', 'label' => 'Treatment', 'icon' => 'spa', 'permission' => 'treatments.view'],
        ['page' => 'membership', 'label' => 'Membership', 'icon' => 'groups', 'permission' => 'memberships.view'],
        ['page' => 'stok', 'label' => 'Produk & Stok', 'icon' => 'inventory_2', 'permission' => 'products.view'],
        ['page' => 'keuangan', 'label' => 'Keuangan', 'icon' => 'wallet', 'permission' => 'finance.view'],
        ['page' => 'penggajian', 'label' => 'Penggajian', 'icon' => 'payments', 'permission' => 'payroll.view'],
        ['page' => 'log', 'label' => 'Log Aktivitas', 'icon' => 'receipt_long', 'permission' => 'activity.view'],
    ];
@endphp

<aside class="sidebar">
    <a class="brand dashboard-brand" href="{{ route('dashboard') }}" aria-label="Selesa Salon - Dashboard">
        <img src="{{ asset('images/selesa-logo.png') }}?v={{ filemtime(public_path('images/selesa-logo.png')) }}" alt="Selesa Salon, Spa, Wellness, Nail, dan Eyelash" width="170" height="56">
    </a>

    <nav id="navigation" aria-label="Navigasi utama">
        @foreach ($modules as $module)
            @can($module['permission'])
                @if ($isDashboard)
                    <button type="button" class="{{ $module['page'] === 'dashboard' ? 'active' : '' }}" data-page="{{ $module['page'] }}">
                        <b class="material-symbols-rounded nav-icon">{{ $module['icon'] }}</b>
                        <span>{{ $module['label'] }}</span>
                    </button>
                @else
                    <a href="{{ route('dashboard') }}#{{ $module['page'] }}">
                        <b class="material-symbols-rounded nav-icon">{{ $module['icon'] }}</b>
                        <span>{{ $module['label'] }}</span>
                    </a>
                @endif
            @endcan
        @endforeach

        @canany(['access.roles.view', 'access.users.view'])
            <details class="access-menu" @if(request()->routeIs('access.*')) open @endif>
                <summary class="{{ request()->routeIs('access.*') ? 'active' : '' }}">
                    <b class="material-symbols-rounded nav-icon">admin_panel_settings</b>
                    <span>Hak Akses</span>
                    <i class="material-symbols-rounded">chevron_right</i>
                </summary>
                <div class="access-submenu">
                    @can('access.roles.view')
                        <a class="{{ request()->routeIs('access.roles.*') ? 'active' : '' }}" href="{{ route('access.roles.index') }}">
                            <span>Peran</span>
                        </a>
                    @endcan
                    @can('access.users.view')
                        <a class="{{ request()->routeIs('access.users.*') ? 'active' : '' }}" href="{{ route('access.users.index') }}">
                            <span>Pengguna</span>
                        </a>
                    @endcan
                </div>
            </details>
        @endcanany
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
                <b>OUT</b><span>Logout</span>
            </button>
        </form>
    </div>
</aside>
