@php
    $user = auth()->user();
    $isDashboard = request()->routeIs('dashboard');
    $modules = [
        ['page' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'space_dashboard', 'permission' => 'dashboard.view'],
        [
            'page' => 'reservasi',
            'label' => 'Reservasi',
            'icon' => 'event_note',
            'permission' => 'reservations.view',
            'children' => [
                ['page' => 'reservasi-antrean', 'label' => 'Antrean Hari Ini'],
                ['page' => 'reservasi-kalender', 'label' => 'Kalender'],
                ['page' => 'reservasi-batal', 'label' => 'Riwayat Dibatalkan', 'route' => 'reservations.cancelled'],
            ],
        ],
        ['page' => 'kasir', 'label' => 'Kasir', 'icon' => 'point_of_sale', 'permission' => 'cashier.view'],
        ['page' => 'penjualan', 'label' => 'Penjualan', 'icon' => 'receipt_long', 'permission' => 'sales.view'],
        ['page' => 'membership', 'label' => 'Membership', 'icon' => 'loyalty', 'permission' => 'memberships.view'],
        ['page' => 'kehadiran-terapis', 'label' => 'Kehadiran Terapis', 'icon' => 'badge', 'permission' => 'therapist_attendance.view'],
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
        ['page' => 'customer-survey', 'label' => 'Customer Survey', 'icon' => 'rate_review', 'permission' => 'sales.view'],
        [
            'page' => 'keuangan', 'label' => 'Keuangan', 'icon' => 'account_balance', 'permission' => 'finance.view',
            'children' => [
                ['page' => 'keuangan-arus-kas', 'label' => 'Arus Kas'],
                ['page' => 'keuangan-laba-rugi', 'label' => 'Laba-Rugi'],
                ['page' => 'keuangan-neraca', 'label' => 'Neraca'],
            ],
        ],
        [
            'page' => 'penggajian',
            'label' => 'Remunerasi',
            'icon' => 'wallet',
            'permission' => 'payroll.view',
            'children' => [
                ['page' => 'penggajian', 'label' => 'Penggajian'],
                ['page' => 'remunerasi', 'label' => 'Rekap & Export Excel'],
                ['page' => 'panduan-remunerasi', 'label' => 'Panduan Remunerasi'],
            ],
        ],
    ];
@endphp

<aside class="sidebar fixed inset-y-0 left-0 z-40 flex w-56 flex-col border-r border-sidebar-hover bg-sidebar p-3 text-sidebar-content">
    <div class="sidebar-brand -mx-3 -mt-3 mb-3 flex h-[var(--app-header-height,5.25rem)] shrink-0 items-center justify-center border-b border-sidebar-hover bg-sidebar-header px-10 [&_img]:mx-auto [&_img]:w-28"><img class="brightness-0 invert" src="{{ asset('images/selesa-logo.png') }}" alt="Selesa Salon"></div>
    <nav id="navigation" class="min-h-0 flex-1 overflow-y-auto overscroll-contain [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" aria-label="Navigasi utama">
        <ul class="menu w-full gap-1 p-0 text-[13px]">
        @foreach ($modules as $module)
            @can($module['permission'])
                <li>
                @if (!empty($module['children']))
                    <details class="access-menu {{ match ($module['page']) { 'reservasi' => 'reservation-menu', 'stok' => 'stock-menu', 'keuangan' => 'finance-menu', default => '' } }}" @if($module['page'] === 'reservasi' && request()->routeIs('reservations.cancelled')) open @endif>
                        <summary class="{{ $module['page'] === 'reservasi' && request()->routeIs('reservations.cancelled') ? 'active' : '' }}"><span class="material-symbols-rounded nav-icon" aria-hidden="true">{{ $module['icon'] }}</span><span>{{ $module['label'] }}</span></summary>
                        <ul class="access-submenu text-sidebar-muted">
                            @foreach ($module['children'] as $child)
                                @if (empty($child['permission']) || $user->can($child['permission']))
                                <li>
                                    @if (isset($child['route']))
                                        <a class="{{ request()->routeIs($child['route']) ? 'active' : '' }}" href="{{ route($child['route']) }}">{{ $child['label'] }}</a>
                                    @elseif ($isDashboard)
                                        <button type="button" data-page="{{ $child['page'] }}">{{ $child['label'] }}</button>
                                    @else
                                        <a href="{{ route('dashboard') }}#{{ $child['page'] }}">{{ $child['label'] }}</a>
                                    @endif
                                </li>
                                @endif
                            @endforeach
                        </ul>
                    </details>
                @elseif ($isDashboard)
                    <button type="button" data-page="{{ $module['page'] }}"><span class="material-symbols-rounded nav-icon" aria-hidden="true">{{ $module['icon'] }}</span><span>{{ $module['label'] }}</span></button>
                @else
                    <a class="{{ $module['page'] === 'membership' && request()->routeIs('members.history') ? 'active' : '' }}" href="{{ route('dashboard') }}#{{ $module['page'] }}"><span class="material-symbols-rounded nav-icon" aria-hidden="true">{{ $module['icon'] }}</span><span>{{ $module['label'] }}</span></a>
                @endif
                </li>
            @endcan
        @endforeach
        @can('activity.view')
            <li>
                @if ($isDashboard)<button type="button" data-page="log"><span class="material-symbols-rounded nav-icon" aria-hidden="true">history</span><span>Log Aktivitas</span></button>
                @else<a href="{{ route('dashboard') }}#log"><span class="material-symbols-rounded nav-icon" aria-hidden="true">history</span><span>Log Aktivitas</span></a>@endif
            </li>
        @endcan
        @canany(['access.roles.view', 'access.users.view'])
            <li><details class="access-menu" @if(request()->routeIs('access.*')) open @endif>
                <summary class="{{ request()->routeIs('access.*') ? 'active' : '' }}"><span class="material-symbols-rounded nav-icon" aria-hidden="true">manage_accounts</span><span>Hak Akses</span></summary>
                <ul class="access-submenu text-sidebar-muted">
                    @can('access.roles.view')<li><a class="{{ request()->routeIs('access.roles.*') ? 'active' : '' }}" href="{{ route('access.roles.index') }}">Peran</a></li>@endcan
                    @can('access.users.view')<li><a class="{{ request()->routeIs('access.users.*') ? 'active' : '' }}" href="{{ route('access.users.index') }}">Pengguna & Karyawan</a></li>@endcan
                </ul>
            </details></li>
        @endcanany
        @can('settings.manage')
            <li><details class="access-menu" @if(request()->routeIs('settings.*')) open @endif>
                <summary class="{{ request()->routeIs('settings.*') ? 'active' : '' }}"><span class="material-symbols-rounded nav-icon" aria-hidden="true">tune</span><span>Pengaturan</span></summary>
                <ul class="access-submenu text-sidebar-muted">
                    <li><a class="{{ request()->routeIs('settings.sale*') ? 'active' : '' }}" href="{{ route('settings.sale') }}">Penjualan</a></li>
                    @foreach (['edc' => 'EDC', 'bank' => 'Bank', 'qris' => 'QRIS'] as $section => $label)
                        <li><a class="{{ request()->routeIs('settings.payment-methods.*') && request()->route('section') === $section ? 'active' : '' }}" href="{{ route('settings.payment-methods.index', $section) }}">{{ $label }}</a></li>
                    @endforeach
                </ul>
            </details></li>
        @endcan
        </ul>
    </nav>
    <div class="sidebar-account mt-3 flex items-center justify-between gap-2 border-t border-sidebar-hover px-2 pt-3 text-sidebar-muted">
        <div class="sidebar-user flex min-w-0 items-center gap-2">
            <span class="grid size-8 shrink-0 place-items-center rounded-full bg-sidebar-header text-xs font-semibold text-sidebar-muted" aria-hidden="true">{{ strtoupper(substr($user->username, 0, 2)) }}</span>
            <strong class="truncate text-xs" title="{{ $user->username }}">{{ $user->username }}</strong>
        </div>
        <form method="POST" action="{{ route('logout') }}">@csrf
            <button type="submit" class="logout btn btn-ghost btn-square btn-sm text-sidebar-content hover:bg-sidebar-hover" title="Keluar dari sistem" aria-label="Keluar dari sistem"><span class="material-symbols-outlined" aria-hidden="true">logout</span></button>
        </form>
    </div>
</aside>

<div class="sidebar-scrim" id="sidebar-scrim" aria-hidden="true"></div>

<button type="button" class="sidebar-toggle btn btn-square btn-ghost fixed left-3 top-4 z-50 bg-sidebar-header text-sidebar-content hover:bg-sidebar-hover" id="sidebar-toggle" aria-controls="app-sidebar" aria-expanded="true" aria-label="Tutup sidebar" title="Tutup sidebar">
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

    sidebar.querySelectorAll('.menu > li > button, .menu > li > a, .access-menu > summary').forEach(item => {
        const label = item.querySelector('span:not(.nav-icon)')?.textContent.trim();
        if (label) { item.title = label; item.setAttribute('aria-label', label); }
    });
    syncViewport();
    document.addEventListener('DOMContentLoaded', () => {
        const header = document.querySelector('.app > main > header');
        if (!header) return;
        new ResizeObserver(() => {
            sidebar.style.setProperty('--app-header-height', `${header.getBoundingClientRect().height}px`);
        }).observe(header);
    });
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
