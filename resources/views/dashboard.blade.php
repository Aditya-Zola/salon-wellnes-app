<!DOCTYPE html>
<html lang="id" data-theme="selesa">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Selesa Salon - Sistem Operasional</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20,300,0,-25&display=block" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20,400,0,0&display=block" rel="stylesheet">
    @vite('resources/css/salon.css')
    <style>
        @cannot('reservations.view') #reservasi-antrean,#reservasi-kalender,.go-reservation{display:none!important} @endcannot
        @cannot('therapist_attendance.view') #kehadiran-terapis,.dashboard-therapist-attendance,.go-therapist-attendance{display:none!important} @endcannot
        @cannot('cashier.view') #kasir{display:none!important} @endcannot
        @cannot('sales.view') #penjualan{display:none!important} @endcannot
        @cannot('treatments.view') #treatment{display:none!important} @endcannot
        @cannot('memberships.view') #membership{display:none!important} @endcannot
        @cannot('products.view') #stok,#stok-riwayat,#stok-opname,.stock-mini .link{display:none!important} @endcannot
        @cannot('finance.view') #keuangan-arus-kas,#keuangan-laba-rugi,#keuangan-neraca{display:none!important} @endcannot
        @cannot('payroll.view') #panduan-remunerasi,#penggajian,#remunerasi,#arsip-remunerasi{display:none!important} @endcannot
        @cannot('activity.view') #log{display:none!important} @endcannot
        @cannot('reservations.create') .open-reservation{display:none!important} @endcannot
        @cannot('cashier.process') #open-payment,#add-extra,.cashier-create-transaction{display:none!important} @endcannot
        @cannot('treatments.create') #treatment .toolbar>.primary{display:none!important} @endcannot
        @cannot('memberships.manage') #membership .membership-manage{display:none!important} @endcannot
        @cannot('products.create') #open-product,#open-product-import{display:none!important} @endcannot
        @cannot('products.stocktake') #stok-opname,.product-stock-action{display:none!important} @endcannot
        @cannot('payroll.manage') #open-payroll,#remuneration-schedule,.remuneration-status{display:none!important} @endcannot
        @cannot('reservations.update') .status-select{pointer-events:none;opacity:.65} @endcannot
        @cannot('treatments.update') .treatment-edit-menu,.recipe-button,.commission-edit,.treatment-price-edit,.bundle-price-edit{display:none!important} @endcannot
        @cannot('treatments.delete') .treatment-delete,.bundle-delete{display:none!important} @endcannot
        @cannot('products.update') .product-price-edit,.product-edit{display:none!important} @endcannot
        @cannot('finance.manage') #open-cash-entry{display:none!important} @endcannot
        @cannot('payroll.manage') .payroll-edit{display:none!important} @endcannot
    </style>
</head>
<body class="ui-shell min-h-screen bg-canvas text-base-content font-sans">
<div class="app">
    @include('partials.internal-sidebar')

    <main>
        <header class="dashboard-header grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3 min-h-20 border-b border-base-300 bg-base-100 px-5 py-4 mb-6"><div class="dashboard-header-copy"><h1 id="page-title">Dashboard</h1><p class="hidden sm:block" id="page-subtitle">Ringkasan operasional salon hari ini</p></div><div class="header-actions flex items-center gap-3"><time class="jakarta-clock hidden sm:grid text-right text-xs text-base-content/60 [&_strong]:text-sm [&_strong]:text-base-content tabular-nums" id="jakarta-clock" aria-label="Waktu Jakarta"><span id="jakarta-clock-date">--</span><strong id="jakarta-clock-time">--.--.-- WIB</strong></time><div class="notification-menu relative"><button type="button" class="bell notification-toggle btn btn-square relative btn-info" id="notification-toggle" title="Pemberitahuan" aria-label="Buka pemberitahuan" aria-expanded="false" aria-controls="notification-panel"><span class="material-symbols-outlined" aria-hidden="true">notifications</span><sup id="notification-count" hidden>0</sup></button><section class="notification-panel absolute right-0 top-12 z-40 w-80 max-w-[calc(100vw-2rem)] rounded-box border border-base-300 bg-base-100 shadow-lg p-4" id="notification-panel" aria-label="Pemberitahuan" hidden><div class="notification-panel-head flex flex-wrap items-center justify-between gap-3"><div><b>Pemberitahuan</b><small>Aktivitas pada sesi ini</small></div><button type="button" class="notification-clear btn btn-xs btn-info" id="notification-clear">Bersihkan</button></div><div class="notification-list grid max-h-80 gap-3 overflow-y-auto" id="notification-list"><p class="notification-empty py-4 text-center text-sm text-base-content/60">Belum ada pemberitahuan.</p></div></section></div></div></header>

        <section class="page active space-y-5" id="dashboard">
            <div class="dashboard-overview-card card overflow-hidden border border-base-300 bg-base-100 shadow-sm divide-y divide-base-300">
            <div class="metrics dashboard-metrics grid gap-4 grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 p-5">
                <article class="dashboard-metric metric-reservations cursor-pointer transition-colors hover:border-primary border border-primary/20 border-t-4 border-t-primary bg-linear-to-br from-primary/10 to-base-100 shadow-sm" role="button" tabindex="0" data-target="reservasi-antrean" data-reservation-status="" aria-label="Buka reservasi hari ini"><div class="metric-icon bg-primary/15 text-primary" aria-hidden="true"><i class="material-symbols-outlined">calendar_month</i></div><div><small>Reservasi hari ini</small><strong id="metric-reservations">0</strong><span id="metric-serving">0 sedang dilayani</span></div></article>
                <article class="dashboard-metric metric-arrivals cursor-pointer transition-colors hover:border-primary border border-secondary/20 border-t-4 border-t-secondary bg-linear-to-br from-secondary/10 to-base-100 shadow-sm" role="button" tabindex="0" data-target="reservasi-antrean" data-reservation-status="arrived" aria-label="Buka daftar pelanggan yang sudah datang"><div class="metric-icon bg-secondary/15 text-secondary" aria-hidden="true"><i class="material-symbols-outlined">groups</i></div><div><small>Pelanggan datang</small><strong id="metric-arrived">0</strong><span id="metric-arrival-rate">0% dari reservasi</span></div></article>
                <article class="dashboard-metric metric-revenue cursor-pointer transition-colors hover:border-primary border border-warning/20 border-t-4 border-t-warning bg-linear-to-br from-warning/10 to-base-100 shadow-sm" role="button" tabindex="0" data-target="keuangan-arus-kas" aria-label="Buka arus kas hari ini"><div class="metric-icon bg-warning/15 text-warning-content" aria-hidden="true"><i class="material-symbols-outlined">payments</i></div><div><small>Pendapatan hari ini</small><strong id="metric-revenue">Rp0</strong><span id="metric-revenue-trend">Belum ada transaksi</span></div></article>
                <article class="dashboard-metric metric-inventory cursor-pointer transition-colors hover:border-primary border border-error/20 border-t-4 border-t-error bg-linear-to-br from-error/10 to-base-100 shadow-sm" role="button" tabindex="0" data-target="stok" aria-label="Buka daftar produk dengan stok menipis"><div class="metric-icon bg-error/15 text-error" aria-hidden="true"><i class="material-symbols-outlined">inventory_2</i></div><div><small>Stok menipis</small><strong id="metric-low-stock">0 produk</strong><span id="metric-stock-note">Stok aman</span></div></article>
            </div>
            <article class="payment-revenue-card card rounded-none bg-base-100 p-5 gap-4" aria-labelledby="payment-revenue-title">
                <div class="analytics-head flex flex-wrap items-center justify-between gap-3"><div class="dashboard-section-title flex items-center gap-3"><i class="material-symbols-outlined" aria-hidden="true">account_balance_wallet</i><div><h3 id="payment-revenue-title">Arus pembayaran hari ini</h3><p>Dana masuk dan refund per nama metode pembayaran</p></div></div><div class="payment-revenue-summary text-right [&_strong]:block [&_strong]:text-xl"><small>Arus bersih hari ini</small><strong id="payment-revenue-total">Rp0</strong><span id="payment-revenue-note">0 metode pembayaran</span></div></div>
                <div class="payment-revenue-list grid grid-cols-1 gap-3 min-[380px]:grid-cols-2 xl:grid-cols-4" id="payment-revenue-list"></div>
            </article>
            <div class="analytics-grid grid xl:grid-cols-2">
                <article class="analytics-card treatment-volume xl:border-r xl:border-base-300 card bg-base-100 rounded-none border-0 p-5 gap-4"><div class="analytics-head flex flex-wrap items-center justify-between gap-3"><div class="dashboard-section-title flex items-center gap-3"><i class="material-symbols-outlined" aria-hidden="true">spa</i><div><h3>Treatment harian</h3><p>Jumlah treatment yang sudah dibayar pada bulan berjalan</p></div></div><span class="analytics-period" id="treatment-volume-period">BULAN INI</span></div><div class="treatment-bar-chart" id="treatment-performance"></div></article>
                <article class="analytics-card popular-treatment card bg-base-100 rounded-none border-0 p-5 gap-4"><div class="analytics-head flex flex-wrap items-center justify-between gap-3"><div class="dashboard-section-title flex items-center gap-3"><i class="material-symbols-outlined" aria-hidden="true">bar_chart</i><div><h3>Treatment sering dilakukan</h3><p>Lima treatment teratas dari transaksi lunas bulan berjalan</p></div></div><span class="analytics-period">BULAN INI</span></div><div class="performance-list" id="popular-treatments"></div></article>
                <article class="analytics-card revenue-analytics border-t border-base-300 xl:col-span-2 card bg-base-100 rounded-none border-0 p-5 gap-4"><div class="analytics-head flex flex-wrap items-center justify-between gap-3"><div><h3>Tren pendapatan</h3><p id="revenue-chart-description">Transaksi dibayar pada minggu berjalan</p></div><div class="revenue-period-filter" role="group" aria-label="Filter periode tren pendapatan"><button type="button" class="active btn btn-sm btn-info" data-revenue-period="week" aria-pressed="true">Minggu</button><button class="btn btn-sm btn-info" type="button" data-revenue-period="month" aria-pressed="false">Bulan</button><button class="btn btn-sm btn-info" type="button" data-revenue-period="year" aria-pressed="false">Tahun</button></div></div><div class="line-chart" id="revenue-chart"></div></article>
            </div>
            <div class="dashboard-operational-card grid divide-y divide-base-300 xl:grid-cols-3 xl:divide-x xl:divide-y-0">
                <section class="dashboard-operational-item card min-w-0 gap-4 rounded-none border-0 bg-base-100 p-5"><div class="card-head flex flex-wrap items-center justify-between gap-3"><div><h3>Antrean hari ini</h3></div><button class="link go-reservation btn btn-sm no-underline btn-info">Lihat semua →</button></div><div id="queue-short"></div></section>
                <section class="dashboard-operational-item card min-w-0 gap-4 rounded-none border-0 bg-base-100 p-5"><div class="card-head flex flex-wrap items-center justify-between gap-3"><div><h3>Bahan treatment</h3></div><button class="link go-stock-alerts btn btn-sm no-underline btn-info">Kelola stok →</button></div><div class="treatment-stock-alerts" id="treatment-stock-alerts"></div></section>
                <section class="dashboard-operational-item dashboard-therapist-attendance card min-w-0 gap-4 rounded-none border-0 bg-base-100 p-5"><div class="card-head flex flex-wrap items-center justify-between gap-3"><div><h3>Kehadiran terapis</h3></div><button class="link go-therapist-attendance btn btn-sm no-underline btn-info">Kelola →</button></div><div class="therapist-availability" id="therapist-availability"></div></section>
            </div>
            @can('employees.view')
                <article class="card therapist-rating-overview rounded-none bg-base-100 p-5 gap-4"><div class="card-head flex flex-wrap items-center justify-between gap-3"><div><h3>Customer Survey</h3><p>Rekap kepuasan pelanggan dari transaksi bulan berjalan.</p></div>@can('sales.view')<button type="button" class="link open-therapist-ratings btn btn-sm no-underline btn-info">Lihat semua →</button>@endcan</div><div id="customer-survey-overview"></div></article>
            @endcan
            </div>
        </section>

        <section class="page reservation-page space-y-5" id="reservasi-antrean">
            <x-page-toolbar><x-slot:actions><button type="button" class="primary ui-action-create open-reservation btn btn-primary"><span class="material-symbols-outlined" aria-hidden="true">add</span>Reservasi baru</button></x-slot:actions></x-page-toolbar>
            <div class="today-queue card reservation-queue-card bg-base-100 border border-base-300 p-5 gap-4 shadow-sm"><div id="reservation-queue-list"></div></div>
        </section>

        <section class="page reservation-page space-y-5" id="reservasi-kalender">
            <x-page-toolbar><x-slot:actions>
                <button type="button" class="secondary btn btn-secondary" id="export-schedule" title="Ekspor jadwal pada tanggal yang dipilih"><span class="material-symbols-outlined" aria-hidden="true">download</span> Ekspor Excel</button>
                <button class="primary ui-action-create open-reservation btn btn-primary"><span class="material-symbols-outlined" aria-hidden="true">add</span> Reservasi baru</button>
            </x-slot:actions></x-page-toolbar>
            <div class="calendar-controls flex flex-col gap-4 rounded-box border border-base-300 bg-base-100 p-4 shadow-sm 2xl:flex-row 2xl:items-center 2xl:justify-between">
                <div class="calendar-period flex flex-wrap items-center gap-3">
                    <button type="button" class="calendar-nav btn btn-square btn-sm btn-info" id="calendar-prev" aria-label="Minggu sebelumnya">‹</button>
                    <button type="button" class="secondary calendar-today btn btn-secondary" id="calendar-today">Hari ini</button>
                    <strong id="calendar-period-label">Minggu ini</strong>
                    <button type="button" class="calendar-nav btn btn-square btn-sm btn-info" id="calendar-next" aria-label="Minggu berikutnya">›</button>
                </div>
                <div class="calendar-filters grid gap-3 md:grid-cols-3">
                    <input class="input w-full min-w-0" id="reservation-calendar-date" type="date" aria-label="Pilih tanggal kalender">
                    <select class="select w-full min-w-0" id="reservation-filter-employee"><option value="">Semua therapist</option></select>
                    <select class="select w-full min-w-0" id="reservation-filter-status"><option value="">Semua status</option><option value="scheduled">Terjadwal</option><option value="arrived">Sudah datang</option><option value="in_service">Sedang dilayani</option><option value="completed">Selesai</option><option value="cancelled">Batal</option></select>
                </div>
            </div>
            <div class="calendar-mode-tabs tabs tabs-border" role="tablist" aria-label="Mode kalender reservasi">
                <button type="button" class="active btn btn-sm btn-info" data-calendar-mode="week" role="tab" aria-selected="true">Ringkasan mingguan</button>
                <button class="btn btn-sm btn-info" type="button" data-calendar-mode="day" role="tab" aria-selected="false">Harian per therapist</button>
            </div>
            <div class="calendar-card card bg-base-100 border border-base-300 p-5 gap-4 shadow-sm"><div id="reservation-calendar" class="reservation-calendar" aria-label="Kalender reservasi mingguan"></div></div>
        </section>

        <section class="page space-y-5" id="kehadiran-terapis">
            <div class="therapist-attendance-layout grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_320px]">
                <div class="card therapist-attendance-page bg-base-100 border border-base-300 p-5 gap-4 shadow-sm"><div id="therapist-attendance" aria-live="polite"></div></div>
                <div class="therapist-attendance-calendars grid content-start gap-5">
                    <aside class="card therapist-attendance-calendar-card bg-base-100 border border-base-300 p-5 gap-4 shadow-sm" aria-label="Kalender kehadiran terapis">
                        <div id="therapist-attendance-calendar" aria-live="polite"></div>
                    </aside>
                    <aside class="card therapist-attendance-calendar-card therapist-overtime-calendar-card bg-base-100 border border-base-300 p-5 gap-4 shadow-sm" aria-label="Kalender lembur terapis">
                        <div id="therapist-overtime-calendar" aria-live="polite"></div>
                    </aside>
                </div>
            </div>
        </section>

        <section class="page space-y-5" id="kasir">
            <div class="cashier-grid grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
                <div class="card cashier-queue-card bg-base-100 border border-base-300 p-5 gap-4 shadow-sm">
                    <div class="card-head cashier-queue-head flex flex-wrap items-center justify-between gap-3">
                        <div><h3>Pilih transaksi</h3><p></p></div>
                        <button type="button" class="primary cashier-create-transaction btn btn-primary" id="cashier-new-transaction"><span class="material-symbols-outlined" aria-hidden="true">add_shopping_cart</span> Transaksi baru</button>
                    </div>
                    <div id="cashier-queue"></div>
                </div>
                <div class="card receipt empty bg-base-100 border border-base-300 p-5 gap-4 shadow-sm" id="cashier-receipt"><div class="card-head flex flex-wrap items-center justify-between gap-3"><div><h3>Transaksi <span id="receipt-number">—</span></h3><p><span id="receipt-name">Pilih transaksi terlebih dahulu</span> <b class="member"></b></p></div></div><div id="receipt-items"><p class="empty-state py-8 text-center text-sm text-base-content/60">Belum ada transaksi yang dipilih.</p></div><button class="dashed btn border-dashed w-full btn-secondary" id="add-extra" disabled><span class="material-symbols-outlined" aria-hidden="true">add_shopping_cart</span> Tambah produk atau treatment</button><div class="promo grid grid-cols-1 gap-3 rounded-box bg-base-200 p-4 sm:grid-cols-2"><span class="col-span-full"><strong>Diskon</strong></span><select class="select w-full min-w-0" id="discount" disabled><option value="0">Tanpa diskon</option></select><input class="input w-full min-w-0" id="manual-discount" type="number" min="0" max="100" step="0.01" inputmode="decimal" placeholder="Manual %" disabled aria-label="Diskon manual persen"></div><div class="totals grid gap-3 py-4 [&_p]:flex [&_p]:justify-between [&_.grand]:text-lg"><p><span>Subtotal</span><b id="subtotal">Rp0</b></p><p class="discount text-success"><span>Diskon</span><b id="discount-value">Rp0</b></p><hr><p class="grand font-semibold"><span>Total pembayaran</span><b id="grand-total">Rp0</b></p></div><button class="primary full btn w-full btn-primary" id="open-payment" disabled>Lanjut ke pembayaran →</button></div>
            </div>
        </section>

        <section class="page space-y-5" id="penjualan">
            <div class="sales-view-tabs tabs tabs-border mb-5" role="tablist" aria-label="Tampilan penjualan">
                <button type="button" class="active btn btn-sm btn-info" data-sales-view="sales" role="tab">Riwayat penjualan</button>
                <button class="btn btn-sm btn-info" type="button" data-sales-view="deposits" role="tab">Riwayat DP</button>
                <button class="btn btn-sm btn-info" type="button" data-sales-view="returns" role="tab">Riwayat retur</button>
            </div>
            <x-page-toolbar>
                <h3 id="sales-history-title">Riwayat penjualan</h3>
                <x-slot:actions>
                    <input class="input w-full sm:w-72" id="sales-search" type="search" placeholder="Cari invoice atau pelanggan" aria-label="Cari riwayat penjualan">
                    <select class="select w-full sm:w-56" id="sales-payment-filter" aria-label="Filter metode pembayaran"><option value="">Semua pembayaran</option></select>
                </x-slot:actions>
            </x-page-toolbar>
            <p class="sr-only" id="sales-history-subtitle">Invoice lunas dan cetak ulang nota.</p>
            <div id="sales-deposit-dates" class="flex flex-wrap gap-3" hidden>
                <label>Dari tanggal<input type="date" id="sales-deposit-from" class="input"></label>
                <label>Sampai tanggal<input type="date" id="sales-deposit-to" class="input"></label>
            </div>
            <div class="card sales-history-card bg-base-100 border border-base-300 p-5 gap-4 shadow-sm"><div class="data-table sales-history-table overflow-x-auto" id="sales-history"></div><div class="table-pagination flex flex-wrap items-center justify-between gap-3 pt-4 text-xs text-base-content/60" id="sales-pagination"></div></div>
        </section>

        <section class="page space-y-5" id="treatment">
            <article class="card treatment-list-card gap-5 border border-base-300 bg-base-100 p-5 shadow-sm">
            <x-page-toolbar :panel="false">
                <h3>Daftar treatment <span class="badge badge-primary badge-soft badge-sm" id="treatment-count">0</span></h3>
                <x-slot:actions><input class="input w-full sm:w-56" id="treatment-search" type="search" placeholder="Cari treatment" aria-label="Cari treatment">@can('treatments.create')
                    <button type="button" class="btn btn-primary" id="open-treatment-bundle"><span class="material-symbols-outlined" aria-hidden="true">inventory_2</span>Buat bundle</button>
                    <button type="button" class="primary ui-action-create btn btn-primary" id="open-treatment"><span class="material-symbols-outlined" aria-hidden="true">add</span>Tambah treatment</button>
                @endcan</x-slot:actions>
            </x-page-toolbar>
                <div class="treatment-grid treatment-card-grid grid gap-4 md:grid-cols-2 2xl:grid-cols-3" id="treatment-grid"></div>
                <h3 class="border-t border-base-300 pt-5">Treatment bundling</h3>
                <div class="treatment-grid treatment-card-grid grid gap-4 md:grid-cols-2" id="treatment-bundle-grid"></div>
            </article>
        </section>

        <section class="page space-y-5" id="customer-survey">
            <article class="card gap-5 border border-base-300 bg-base-100 p-5 shadow-sm">
            <h3>Customer Survey</h3>
            <x-page-toolbar :panel="false">
                <x-slot:filters>
                    <input class="input w-full" id="customer-survey-search" type="search" placeholder="Cari invoice, nama, atau telepon" aria-label="Cari customer survey">
                    <label>Dari tanggal<input class="input w-full" id="customer-survey-from" type="date"></label>
                    <label>Sampai tanggal<input class="input w-full" id="customer-survey-to" type="date"></label>
                    <select class="select w-full" id="customer-survey-status" aria-label="Filter status customer survey"><option value="">Semua status</option><option value="draft">Belum diisi</option><option value="submitted">Sudah diisi</option></select>
                </x-slot:filters>
            </x-page-toolbar>
            <section class="customer-survey-therapist-summary grid gap-4" id="customer-survey-therapist-summary"></section>
            <div class="data-table overflow-x-auto" id="customer-survey-list"></div><div class="table-pagination flex flex-wrap items-center justify-between gap-3 pt-4 text-xs text-base-content/60" id="customer-survey-pagination"></div></article>
        </section>

        <section class="page space-y-5" id="membership">
            <div class="metrics three grid gap-4 grid-cols-1 md:grid-cols-3 mb-5"><article class="border border-primary/20 border-t-4 border-t-primary bg-linear-to-br from-primary/10 to-base-100 shadow-sm"><div class="metric-icon bg-primary/15 text-primary" aria-hidden="true"><i class="material-symbols-outlined">workspace_premium</i></div><div><small>Member aktif</small><strong id="member-count">0</strong><span id="new-member-count">0 bulan ini</span></div></article><article class="border border-warning/20 border-t-4 border-t-warning bg-linear-to-br from-warning/10 to-base-100 shadow-sm"><div class="metric-icon bg-warning/15 text-warning-content" aria-hidden="true"><i class="material-symbols-outlined">campaign</i></div><div><small>Event aktif</small><strong id="promotion-count">0</strong><span id="ending-promotion-count">0 berakhir bulan ini</span></div></article><article class="border border-secondary/20 border-t-4 border-t-secondary bg-linear-to-br from-secondary/10 to-base-100 shadow-sm"><div class="metric-icon bg-secondary/15 text-secondary" aria-hidden="true"><i class="material-symbols-outlined">payments</i></div><div><small>Transaksi member</small><strong id="member-transaction-percent">0%</strong><span>Dari total bulan ini</span></div></article></div>
            <div class="two-column membership-grid grid items-start gap-5 xl:grid-cols-[minmax(0,1.55fr)_minmax(0,1fr)]">
                <div class="card bg-base-100 border border-base-300 p-5 gap-4 shadow-sm">
                    <div class="card-head flex flex-wrap items-center justify-between gap-3">
                        <h3 class="flex items-center gap-2"><span class="material-symbols-outlined text-primary" aria-hidden="true">workspace_premium</span>Daftar member</h3>
                        <div class="member-list-actions flex w-full flex-wrap items-center gap-2 sm:w-auto">
                            <input class="input min-w-0 w-full sm:w-44" id="member-search" type="search" inputmode="tel" placeholder="Cari nomor telepon" aria-label="Cari member berdasarkan nomor telepon">
                            <button type="button" class="primary membership-manage btn btn-primary" id="open-member"><span class="material-symbols-outlined" aria-hidden="true">add</span>Member baru</button>
                        </div>
                    </div>
                    <div id="member-list"></div><div class="table-pagination flex flex-wrap items-center justify-between gap-3 pt-4 text-xs text-base-content/60" id="member-pagination"></div>
                </div>
                <div class="card bg-base-100 border border-base-300 p-5 gap-4 shadow-sm">
                    <div class="card-head flex flex-wrap items-center justify-between gap-3"><h3 class="flex items-center gap-2"><span class="material-symbols-outlined text-accent" aria-hidden="true">campaign</span>Event membership</h3><button class="secondary membership-manage btn btn-secondary" id="open-promotion"><span class="material-symbols-outlined" aria-hidden="true">add</span> Event baru</button></div>
                    <div id="membership-events" class="grid gap-3"></div>
                </div>
            </div>
        </section>

        <section class="page space-y-5" id="stok">
            <x-page-toolbar>
                <x-slot:filters>
                    <input class="input w-full" id="stock-search" type="search" placeholder="Cari produk" aria-label="Cari produk">
                    <select id="stock-sort" class="stock-sort select w-full" aria-label="Filter produk berdasarkan jumlah stok"><option value="">Semua stok</option><option value="lowest">Stok terendah</option><option value="highest">Stok terbanyak</option></select>
                </x-slot:filters>
                <x-slot:actions>
                    <button type="button" class="secondary btn btn-secondary" id="open-product-import"><span class="material-symbols-outlined" aria-hidden="true">upload_file</span>Import Excel</button>
                    <button type="button" class="primary ui-action-create btn btn-primary" id="open-product"><span class="material-symbols-outlined" aria-hidden="true">add</span>Tambah produk</button>
                </x-slot:actions>
            </x-page-toolbar>
            <div class="card stock-list-card bg-base-100 border border-base-300 p-5 gap-4 shadow-sm">
                <div id="stock-list" class="data-table stock-table overflow-x-auto"></div>
                <div class="table-pagination flex flex-wrap items-center justify-between gap-3 pt-4 text-xs text-base-content/60" id="product-pagination"></div>
            </div>
        </section>

        <section class="page space-y-5" id="stok-riwayat">
            <x-page-toolbar>
                <h3>Riwayat keluar-masuk stok</h3>
                <x-slot:actions>
                    <label>Dari tanggal<input class="input w-full sm:w-44" id="stock-history-from" type="date" value="{{ today()->startOfMonth()->toDateString() }}"></label>
                    <label>Sampai tanggal<input class="input w-full sm:w-44" id="stock-history-to" type="date" value="{{ today()->toDateString() }}"></label>
                    <button type="button" class="secondary btn btn-secondary" id="export-stock-history"><span class="material-symbols-outlined" aria-hidden="true">download</span>Ekspor Excel</button>
                </x-slot:actions>
            </x-page-toolbar>
            <div class="card bg-base-100 border border-base-300 p-5 gap-4 shadow-sm"><div id="stock-history" class="data-table history-table overflow-x-auto"></div><div class="table-pagination flex flex-wrap items-center justify-between gap-3 pt-4 text-xs text-base-content/60" id="stock-history-pagination"></div></div>
        </section>

        <section class="page space-y-5" id="stok-opname">
            <x-page-toolbar :panel="false">
                <button type="button" class="secondary btn btn-secondary" id="stocktake-back"><span class="material-symbols-outlined" aria-hidden="true">arrow_back</span> Kembali ke produk</button>
                <x-slot:actions>
                    <button type="button" class="secondary btn btn-secondary" id="stocktake-reset"><span class="material-symbols-outlined" aria-hidden="true">restart_alt</span> Kosongkan isian</button>
                    <button type="submit" class="primary btn btn-primary" id="stocktake-submit" form="stocktake-form" disabled><span class="material-symbols-outlined" aria-hidden="true">save</span> Simpan stok masuk</button>
                </x-slot:actions>
            </x-page-toolbar>

            <div class="stocktake-overview grid gap-4 sm:grid-cols-2 mb-5">
                <article class="border border-primary/20 bg-primary/5 shadow-sm"><span class="material-symbols-outlined" aria-hidden="true">inventory_2</span><div><small>Produk tersedia</small><strong id="stocktake-product-count">0</strong></div></article>
                <article class="border border-secondary/20 bg-secondary/5 shadow-sm"><span class="material-symbols-outlined" aria-hidden="true">add_box</span><div><small>Produk diisi</small><strong id="stocktake-filled-count">0</strong></div></article>
            </div>

            <div class="card stocktake-card bg-base-100 border border-base-300 p-5 gap-4 shadow-sm">
                <x-page-toolbar :panel="false"><h3>Tambah stok masuk</h3><x-slot:actions>
                    <input class="input w-full sm:w-64" id="stocktake-search" type="search" placeholder="Cari nama atau kode produk" aria-label="Cari produk untuk stok opname">
                    <select class="select w-full sm:w-56" id="stocktake-category" aria-label="Filter kategori produk"><option value="">Semua kategori</option></select>
                </x-slot:actions></x-page-toolbar>
                <form id="stocktake-form">
                    <div class="stocktake-table-head" aria-hidden="true"><span>PRODUK</span><span>STOK SEKARANG</span><span>JUMLAH MASUK</span><span>CATATAN</span></div>
                    <div id="stocktake-list" class="stocktake-list" aria-live="polite"></div>
                </form>
            </div>
        </section>

        <section class="page finance-page space-y-5" id="keuangan-arus-kas"><div class="metrics grid gap-4 grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 mb-5"><article class="border border-secondary/20 border-t-4 border-t-secondary bg-linear-to-br from-secondary/10 to-base-100 shadow-sm"><div class="metric-icon bg-secondary/15 text-secondary" aria-hidden="true"><i class="material-symbols-outlined">trending_up</i></div><div><small>Pemasukan kas</small><strong id="finance-income">Rp0</strong><span>Input manual</span></div></article><article class="border border-error/20 border-t-4 border-t-error bg-linear-to-br from-error/10 to-base-100 shadow-sm"><div class="metric-icon bg-error/15 text-error" aria-hidden="true"><i class="material-symbols-outlined">trending_down</i></div><div><small>Pengeluaran kas</small><strong id="finance-expense">Rp0</strong><span>Input manual</span></div></article><article class="border border-warning/20 border-t-4 border-t-warning bg-linear-to-br from-warning/10 to-base-100 shadow-sm"><div class="metric-icon bg-warning/15 text-warning-content" aria-hidden="true"><i class="material-symbols-outlined">account_balance_wallet</i></div><div><small>Saldo kas</small><strong id="finance-balance">Rp0</strong><span id="finance-period">Bulan berjalan</span></div></article><article class="border border-primary/20 border-t-4 border-t-primary bg-linear-to-br from-primary/10 to-base-100 shadow-sm"><div class="metric-icon bg-primary/15 text-primary" aria-hidden="true"><i class="material-symbols-outlined">receipt_long</i></div><div><small>Catatan kas</small><strong id="finance-cash-entry-count">0</strong><span id="finance-cash-entry-note">Bulan berjalan</span></div></article></div><article class="card finance-payment-flow-card bg-base-100 border border-base-300 p-5 gap-4 shadow-sm"><div class="card-head flex flex-wrap items-center justify-between gap-3"><div><h3>Arus pembayaran per rekening</h3><p>Rekap dana masuk dan refund berdasarkan metode yang diatur di Pengaturan Pembayaran.</p></div><div class="finance-payment-flow-summary text-right [&_strong]:block [&_strong]:text-xl"><small>Arus bersih bulan ini</small><strong id="finance-payment-flow-total">Rp0</strong></div></div><div class="finance-payment-flow-list grid gap-3" id="finance-payment-flows"></div></article><div class="two-column grid gap-5 xl:grid-cols-2"><div class="card bg-base-100 border border-base-300 p-5 gap-4 shadow-sm"><div class="card-head flex flex-wrap items-center justify-between gap-3"><div><h3>Ringkasan kas</h3><p>Rekap dari seluruh input kas manual.</p></div></div><div class="cash-bars" id="cash-bars"></div></div><div class="card bg-base-100 border border-base-300 p-5 gap-4 shadow-sm"><div class="card-head flex flex-wrap items-center justify-between gap-3"><div><h3>Pengeluaran per kategori</h3><p>Pengeluaran kas manual bulan berjalan</p></div></div><div class="cash-bars finance-category-bars" id="finance-category-bars"></div></div></div><div class="card finance-history-card bg-base-100 border border-base-300 p-5 gap-4 shadow-sm"><div class="card-head flex flex-wrap items-center justify-between gap-3"><div><h3>Data kas</h3><p>Catat modal, pemasukan, pembelian, dan biaya operasional secara manual.</p></div><button class="primary btn btn-primary" id="open-cash-entry"><span class="material-symbols-outlined" aria-hidden="true">add</span> Input kas</button></div><div class="finance-history-filters flex flex-wrap items-center justify-between gap-3"><input class="input w-full min-w-0" id="cash-entry-from" type="date" aria-label="Tanggal awal"><input class="input w-full min-w-0" id="cash-entry-to" type="date" aria-label="Tanggal akhir"><select class="select w-full min-w-0" id="cash-entry-type-filter" aria-label="Filter jenis arus kas"><option value="">Semua jenis</option><option value="income">Pemasukan</option><option value="expense">Pengeluaran</option></select><input class="input w-full min-w-0" id="cash-entry-search" type="search" placeholder="Cari kategori atau deskripsi..." aria-label="Cari data kas"></div><div class="data-table finance-history-table overflow-x-auto" id="cash-entry-history"></div></div></section>

        <section class="page finance-page space-y-5" id="keuangan-laba-rugi"><article class="card finance-report-card bg-base-100 border border-base-300 p-5 gap-4 shadow-sm"><div class="card-head flex flex-wrap items-center justify-between gap-3"><div><h3>Laba-rugi sistem</h3><p>Pendapatan transaksi dikurangi HPP tersimpan dan biaya operasional.</p></div></div><div class="finance-statement grid gap-3" id="profit-loss-report"></div></article></section>

        <section class="page finance-page space-y-5" id="keuangan-neraca"><article class="card finance-report-card bg-base-100 border border-base-300 p-5 gap-4 shadow-sm"><div class="card-head flex flex-wrap items-center justify-between gap-3"><div><h3>Neraca dasar</h3><p>Posisi kas, setiap rekening pembayaran, dan nilai stok berdasarkan HPP.</p></div></div><div class="finance-statement grid gap-3" id="balance-sheet-report"></div></article></section>

        <section class="page remuneration-guide-page space-y-5" id="panduan-remunerasi">
            <div class="remuneration-guide-copy card bg-base-100 border border-base-300 p-5 gap-4 shadow-sm">
                <div class="remuneration-guide-title flex items-center gap-3"><span class="material-symbols-outlined" aria-hidden="true">info</span><h3>Panduan remunerasi</h3></div>
                <p class="remuneration-guide-lead">Alur penggajian: catat kehadiran, isi komponen gaji per karyawan dan periode, lalu periksa rekap sebelum mengunduh slip.</p>
                <ol>
                    <li><strong>Isi komponen gaji untuk setiap karyawan dan periode.</strong><p>Di menu <button type="button" class="remuneration-guide-go btn btn-sm btn-info" data-remuneration-page="penggajian">Penggajian</button>, pilih periode gaji lalu isi hari kerja dibayar, bayaran per hari, bonus, tunjangan, mangkir, keterlambatan, kasbon, dan potongan lain sesuai kebijakan salon. Gaji pokok periode dihitung dari hari dibayar dan bayaran per hari. Bulan berikutnya dibuat sebagai periode baru; data bulan sebelumnya tidak direset.</p></li>
                    <li><strong>Status masuk, libur, dan lembur diisi manual per tanggal.</strong><p>Catatan kehadiran digunakan untuk mencatat kondisi kerja karyawan pada setiap tanggal. Pilih status masuk, libur, atau lembur pada halaman Kehadiran Terapis. Jika memilih lembur, nominal lembur wajib diisi pada tanggal yang sama agar masuk ke kalender lembur dan rekap KOM-LEM. Status libur adalah catatan kehadiran; keputusan hari tersebut dibayar atau tidak tetap ditentukan melalui JHK atau mangkir pada data remunerasi. Buka <button type="button" class="remuneration-guide-go btn btn-sm btn-info" data-remuneration-page="kehadiran-terapis">Kehadiran Terapis</button>.</p></li>
                    <li><strong>Komisi treatment dihitung otomatis dari transaksi yang sudah lunas.</strong><p>Sistem mengambil treatment yang telah dibayar di kasir, lalu memasukkan komisi kepada therapist yang tercatat mengerjakan treatment tersebut. Jika satu treatment dikerjakan lebih dari satu therapist, pembagian mengikuti profil komisi yang diatur pada menu Treatment. Komisi tidak perlu diinput ulang pada remunerasi dan akan tercatat pada tanggal transaksi dinyatakan lunas.</p></li>
                    <li><strong>Periksa hasil, lalu unduh rekap dan slip gaji.</strong><p>Sistem menjumlahkan gaji pokok, komisi, lembur, bonus, tunjangan, dan TIP; kemudian mengurangi mangkir, keterlambatan, kasbon, serta potongan lain. Di <button type="button" class="remuneration-guide-go btn btn-sm btn-info" data-remuneration-page="remunerasi">Rekap &amp; Export Excel</button>, pilih rentang tanggal, periksa detail tiap karyawan, lalu unduh hasilnya. Tanggal gajian dan cutoff adalah penanda periode, bukan tombol pembayaran atau reset.</p></li>
                    <li><strong>Pembayaran gaji belum dicatat otomatis.</strong><p>Saat ini menyimpan data atau mengunduh slip tidak menandai gaji sebagai sudah dibayar. Belum ada tombol pembayaran gaji atau pencatatan tanggal dan nominal gaji yang benar-benar dibayarkan di alur ini.</p></li>
                </ol>
            </div>
        </section>

        <section class="page space-y-5" id="penggajian"><x-page-toolbar><h3>Penggajian</h3><x-slot:actions><button type="button" class="primary btn btn-primary" id="open-payroll"><span class="material-symbols-outlined" aria-hidden="true">add</span>Tambah data</button></x-slot:actions></x-page-toolbar><div class="card payroll-card bg-base-100 border border-base-300 p-5 gap-4 shadow-sm"><div class="data-table payroll-table overflow-x-auto" id="payroll-table"></div></div><div class="notice alert alert-soft text-sm mt-5">ⓘ Data tersimpan per periode, tidak direset setelah gajian. Periksa dan unduh slip di Rekap &amp; Export Excel; pembayaran gaji belum tercatat otomatis.</div></section>

        <section class="page remuneration-page space-y-5" id="remunerasi">
            <x-page-toolbar>
                <h3>Rekap &amp; Export Remunerasi</h3>
                <x-slot:actions>
                    <label>Dari<input class="input w-full sm:w-40" id="remuneration-from" type="date"></label>
                    <label>Sampai<input class="input w-full sm:w-40" id="remuneration-to" type="date"></label>
                    <button type="button" class="secondary btn btn-secondary" id="open-remuneration-archives"><span class="material-symbols-outlined" aria-hidden="true">inventory_2</span>Arsip</button>
                    @can('payroll.manage')<button type="button" class="primary btn btn-primary" id="finalize-remuneration"><span class="material-symbols-outlined" aria-hidden="true">lock</span>Finalisasi</button>@endcan
                    <button type="button" class="secondary btn btn-secondary" id="export-remuneration" title="Rekap dan slip tiap karyawan"><span class="material-symbols-outlined" aria-hidden="true">download</span>Ekspor rekap & slip</button>
                    <button type="button" class="secondary btn btn-square btn-secondary" id="remuneration-schedule" aria-label="Atur tanggal gajian dan cutoff"><span class="material-symbols-outlined" aria-hidden="true">settings</span></button>
                </x-slot:actions>
            </x-page-toolbar>
            <div class="remuneration-period-note flex items-start gap-3 rounded-box border border-base-300 bg-base-100 p-4 text-sm text-base-content/60" id="remuneration-period-note"></div>
            <div class="remuneration-summary grid gap-4 grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 mb-5" id="remuneration-summary"></div>
            <article class="card remuneration-table-card bg-base-100 border border-base-300 p-5 gap-4 shadow-sm"><div class="card-head flex flex-wrap items-center justify-between gap-3"><div><h3>Per karyawan</h3></div></div><div class="data-table remuneration-table overflow-x-auto" id="remuneration-table"></div></article>
            <div class="notice alert alert-soft text-sm mt-5">ⓘ Tanggal gajian dan cutoff hanya penanda periode. Sistem tidak menghapus atau mereset gaji, komisi, bonus, maupun keterlambatan.</div>
        </section>

        <section class="page remuneration-archive-page space-y-5" id="arsip-remunerasi">
            <x-page-toolbar>
                <h3>Arsip Remunerasi</h3>
                <x-slot:actions><label>Tahun<input class="input w-full sm:w-32" id="remuneration-archive-year" type="number" min="2000" max="2100"></label><button type="button" class="secondary btn btn-secondary" id="back-to-remuneration"><span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>Kembali ke rekap</button></x-slot:actions>
            </x-page-toolbar>
            <div class="remuneration-archive-grid grid gap-4 md:grid-cols-2 xl:grid-cols-3" id="remuneration-archive-grid"></div>
            <div class="remuneration-archive-detail card bg-base-100 border border-base-300 p-5 gap-4 shadow-sm" id="remuneration-archive-detail" hidden></div>
        </section>

        <section class="page space-y-5" id="log">
            <article class="card activity-card gap-4 border border-base-300 bg-base-100 p-5 shadow-sm">
            <h3>Aktivitas perubahan data</h3>
            <x-page-toolbar :panel="false">
                <x-slot:filters>
                    <input class="input w-full" id="activity-search" type="search" placeholder="Cari customer, aktivitas, atau pengguna" aria-label="Cari log aktivitas">
                    <input class="input w-full" id="activity-filter-date" type="date" aria-label="Tanggal aktivitas">
                    <select class="select w-full" id="activity-filter-user" aria-label="Pengguna"><option value="">Semua pengguna</option></select>
                    <select class="select w-full" id="activity-filter-action" aria-label="Kategori aktivitas"><option value="">Semua aktivitas</option></select>
                </x-slot:filters>
            </x-page-toolbar>
            <div class="overflow-x-auto">
                <table class="table table-zebra w-full border-collapse border border-base-300 [&_th]:border-r [&_th]:border-base-300 [&_td]:border-r [&_td]:border-base-300" aria-label="Log aktivitas pengguna">
                    <thead class="bg-primary/10 text-primary"><tr><th scope="col">Waktu</th><th scope="col">Pengguna</th><th scope="col">Jenis aktivitas</th><th scope="col">Keterangan</th><th scope="col">Pelanggan / Reservasi</th></tr></thead>
                    <tbody id="activity-list"></tbody>
                </table>
            </div>
            <p class="border-t border-base-300 pt-3 text-xs text-base-content/60" id="activity-count" aria-live="polite"></p>
            </article>
        </section>
    </main>
</div>

<div class="modal p-4" id="reservation-modal">
    <div class="modal-box reservation-modal-box w-full max-w-2xl max-h-[calc(100dvh-2rem)] overflow-y-auto">
        <div class="modal-head reservation-modal-head grid grid-cols-[minmax(0,1fr)_auto] items-start gap-3 border-b border-base-300 pb-4 mb-5"><div><h2 id="reservation-modal-title">Reservasi baru</h2><p id="reservation-modal-subtitle">Satu kunjungan dapat memuat beberapa treatment dan therapist</p></div><button type="button" class="close-modal reservation-close btn btn-sm btn-info" aria-label="Tutup form reservasi"><span class="material-symbols-outlined">close</span></button></div>
        <form id="reservation-form" class="reservation-form grid gap-4">
            <section class="grid gap-3 rounded-box border border-base-300 bg-base-200/40 p-4">
            <fieldset class="reservation-customer-type flex flex-wrap gap-3">
                <legend>Jenis pelanggan</legend>
                <label><input class="radio radio-primary radio-sm" type="radio" name="customer_type" value="guest" checked> Pelanggan umum</label>
                <label><input class="radio radio-primary radio-sm" type="radio" name="customer_type" value="member"> Member terdaftar</label>
            </fieldset>
            <div class="reservation-customer-fields">
                <div id="reservation-member-picker" class="reservation-member-picker grid min-w-0 gap-2 md:col-span-2" hidden>
                    <label id="reservation-member-label">Member</label>
                    <div class="reservation-member-combobox min-w-0">
                        <button id="reservation-member-trigger" class="reservation-member-trigger select min-w-0 w-full text-left" type="button" aria-labelledby="reservation-member-label reservation-member-trigger-label" aria-expanded="false" aria-controls="reservation-member-results"><span id="reservation-member-trigger-label" class="min-w-0 truncate">Pilih member</span></button>
                        <div id="reservation-member-results" class="reservation-member-results" role="listbox" aria-label="Member terdaftar" hidden><div class="reservation-member-search mb-2"><input class="input w-full min-w-0" id="reservation-member-search" type="text" autocomplete="off" aria-label="Cari member" placeholder="Cari nama atau nomor telepon..."></div><div id="reservation-member-options" class="reservation-member-options"></div></div>
                    </div>
                    <input id="reservation-member-id" name="member_id" type="hidden">
                    <p id="reservation-member-preview" class="text-xs leading-relaxed text-base-content/60" hidden></p>
                </div>
                <label class="reservation-guest-field">Nama pelanggan<input class="input w-full min-w-0" required name="name" placeholder="Masukkan nama"></label>
                <label class="reservation-guest-field">Nomor telepon<input class="input w-full min-w-0" required name="phone" placeholder="08xx xxxx xxxx"></label>
                <label>Tanggal<input class="input w-full min-w-0" required id="reservation-date" name="date" type="date"></label>
                <label>Sumber booking<select class="select w-full min-w-0" name="source"><option value="walk_in">Walk-in</option><option value="whatsapp">WhatsApp</option><option value="phone">Telepon</option><option value="other">Lainnya</option></select></label>
            </div>
            </section>
            <div class="reservation-items-head gap-3"><h3>Daftar treatment</h3><button type="button" class="ui-action-create btn btn-sm btn-primary" id="add-reservation-item"><span class="material-symbols-outlined" aria-hidden="true">add</span> Tambah treatment</button></div>
            <div id="reservation-items" class="grid gap-3"></div>
            @canany(['reservations.create', 'cashier.process'])
                <fieldset class="reservation-customer-type flex flex-wrap gap-3" id="reservation-deposit-choice">
                    <legend>Jenis pembayaran</legend>
                    <label><input class="radio radio-primary radio-sm" type="radio" name="payment_stage" value="unpaid" checked> Pembayaran langsung</label>
                    <label><input class="radio radio-primary radio-sm" type="radio" name="payment_stage" value="deposit"> Pembayaran DP</label>
                    <div class="form-grid grid gap-4 md:grid-cols-2" id="reservation-deposit-fields" hidden>
                        <label>Nominal DP<input class="input w-full min-w-0" name="deposit_amount" type="number" min="1" step="1" inputmode="numeric" placeholder="Otomatis 50% dari total"><small id="reservation-deposit-hint">Otomatis 50% dari total treatment dan tetap dapat diubah.</small></label>
                    </div>
                </fieldset>
            @endcanany
            <label class="reservation-notes">Catatan kunjungan<textarea class="textarea w-full min-w-0" name="notes" placeholder="Permintaan atau catatan umum pelanggan"></textarea></label>
            <div class="conflict-panel hidden" id="reservation-conflict" role="alert"></div>
            <footer class="reservation-footer"><button type="button" class="secondary close-modal btn btn-secondary">Batal</button><button class="primary btn btn-primary"><span class="material-symbols-outlined" aria-hidden="true">check</span> <span id="reservation-submit-label">Simpan reservasi</span></button></footer>
        </form>
    </div>
</div>

<div class="modal p-4" id="payment-modal"><div class="modal-box w-full max-w-2xl max-h-[calc(100dvh-2rem)] overflow-y-auto"><div class="modal-head grid grid-cols-[minmax(0,1fr)_auto] items-start gap-3 border-b border-base-300 pb-4 mb-5"><div><h2>Pembayaran</h2><p id="payment-description">Pilih transaksi</p></div><button type="button" class="close-modal btn btn-sm btn-info"><span class="material-symbols-outlined">close</span></button></div><div class="payment-total rounded-box bg-base-200 p-5 text-center [&_strong]:block [&_strong]:text-3xl [&_strong]:font-semibold mb-5"><small>Total harus dibayar</small><strong id="payment-total">Rp0</strong><p class="payment-charge-total" id="payment-charge-total" hidden>Termasuk charge <b>Rp0</b></p></div><div class="split-payment-head flex flex-wrap items-center justify-between gap-3"><h3>Cara bayar</h3><button type="button" class="secondary btn btn-secondary" id="add-payment-row"><span class="material-symbols-outlined" aria-hidden="true">add</span> Tambah pembayaran</button></div><div id="payment-rows"></div><div class="payment-reconciliation grid gap-2 sm:grid-cols-2 sm:gap-x-6 rounded-box bg-base-200 p-4 my-4 [&>span]:flex [&>span]:justify-between"><span>Sebelum charge <b id="payment-base-total">Rp0</b></span><span>Charge <b id="payment-charge-value">Rp0</b></span><span class="font-semibold">Total harus dibayar <b id="payment-payable-total">Rp0</b></span><span>Nominal dibayar <b id="payment-entered">Rp0</b></span><span>Status <b id="payment-difference">Pas</b></span><span id="payment-change" hidden>Kembalian <b>Rp0</b></span></div><div class="stock-impact alert alert-info alert-soft text-sm"><b>Stok akan berkurang otomatis</b><p>Sesuai resep seluruh treatment pada kunjungan ini.</p></div><footer><button type="button" class="secondary close-modal btn btn-secondary">Batal</button><button type="button" class="primary btn btn-primary" id="complete-payment">Konfirmasi pembayaran</button></footer></div></div>

<div class="modal p-4" id="product-import-modal"><div class="modal-box product-import-modal w-full max-w-2xl max-h-[calc(100dvh-2rem)] overflow-y-auto"><div class="modal-head grid grid-cols-[minmax(0,1fr)_auto] items-start gap-3 border-b border-base-300 pb-4 mb-5"><div><h2>Import data produk</h2><p>Tambahkan banyak produk sekaligus dari Excel.</p></div><button type="button" class="close-modal btn btn-sm btn-info" aria-label="Tutup"><span class="material-symbols-outlined">close</span></button></div><form id="product-import-form" enctype="multipart/form-data"><div class="product-import-content grid gap-5"><div class="product-import-guide flex items-start gap-3 rounded-box bg-base-200 p-4"><span class="material-symbols-outlined" aria-hidden="true">table_view</span><div><strong>Kolom file Excel</strong><p>Kode produk, nama produk, kategori, satuan, stok awal, stok minimum, harga jual, status, dan deskripsi.</p></div></div><label class="product-import-picker grid gap-2 rounded-box border border-dashed border-base-300 p-4" for="product-import-file"><span class="material-symbols-outlined" aria-hidden="true">upload_file</span><strong>Pilih file Excel</strong><small id="product-import-file-name">Format .xlsx atau .csv, maksimal 5 MB</small><input class="file-input w-full" id="product-import-file" name="file" type="file" accept=".xlsx,.csv" required></label><p class="product-import-warning flex items-start gap-2 text-xs text-base-content/60"><span class="material-symbols-outlined" aria-hidden="true">info</span>Kode produk yang sudah ada akan dilewati agar data dan stok lama tidak tertimpa.</p><div class="product-import-result" id="product-import-result" hidden></div></div><footer><button type="button" class="secondary close-modal btn btn-secondary">Batal</button><button class="primary btn btn-primary" id="submit-product-import"><span class="material-symbols-outlined" aria-hidden="true">upload</span> Import produk</button></footer></form></div></div>
<div class="modal p-4" id="product-modal"><div class="modal-box w-full max-w-2xl max-h-[calc(100dvh-2rem)] overflow-y-auto"><div class="modal-head grid grid-cols-[minmax(0,1fr)_auto] items-start gap-3 border-b border-base-300 pb-4 mb-5"><div><h2>Tambah produk baru</h2><p>Produk dapat digunakan dalam resep treatment</p></div><button class="close-modal btn btn-sm btn-info"><span class="material-symbols-outlined">close</span></button></div><form id="product-form"><div class="form-grid grid gap-4 md:grid-cols-2"><label>Nama produk<input class="input w-full min-w-0" required placeholder="Contoh: Hair Spa L'Oréal"></label><label>Kategori<select class="select w-full min-w-0"><option>Hair</option><option>Facial</option><option>Spa</option><option>Nail</option><option>Konsumsi</option></select></label><label>Stok awal<input class="input w-full min-w-0" type="number" value="500"></label><label>Satuan<select class="select w-full min-w-0"><option>ml</option><option>gr</option><option>pcs</option><option>sachet</option></select></label><label>Batas minimum<input class="input w-full min-w-0" type="number" value="100"></label><label>Harga jual<input class="input w-full min-w-0" type="number" value="0"></label><label>HPP per satuan pakai<input class="input w-full min-w-0" type="number" min="0" value="0"></label></div><footer><button type="button" class="secondary close-modal btn btn-secondary">Batal</button><button class="primary btn btn-primary">Simpan produk</button></footer></form></div></div>
<div class="modal p-4" id="product-edit-modal"><div class="modal-box product-edit-modal w-full max-w-2xl max-h-[calc(100dvh-2rem)] overflow-y-auto"><div class="modal-head grid grid-cols-[minmax(0,1fr)_auto] items-start gap-3 border-b border-base-300 pb-4 mb-5"><div><h2 id="product-edit-title">Edit produk</h2><p>Perbaiki data master tanpa mengubah riwayat keluar-masuk stok.</p></div><button type="button" class="close-modal btn btn-sm btn-info"><span class="material-symbols-outlined">close</span></button></div><form id="product-edit-form"><input type="hidden" name="id"><div class="form-grid grid gap-4 md:grid-cols-2"><label>Nama produk<input class="input w-full min-w-0" name="name" required maxlength="150"></label><label>Kategori<input class="input w-full min-w-0" name="category" maxlength="100" placeholder="Contoh: Hair"></label><label>Satuan<select class="select w-full min-w-0" name="unit_id" required></select><small>Gunakan bila satuan sebelumnya salah input.</small></label><label>Batas stok minimum<input class="input w-full min-w-0" name="minimum_stock" type="number" min="0" step="0.0001" required></label><label>Harga jual<input class="input w-full min-w-0" name="selling_price" type="number" min="0" step="1" required></label><label>HPP per satuan pakai<input class="input w-full min-w-0" name="cost_price" type="number" min="0" step="1" required><small>Dipakai untuk nilai stok dan laba-rugi.</small></label><label>Status<select class="select w-full min-w-0" name="is_active"><option value="1">Aktif</option><option value="0">Nonaktif</option></select></label><label class="full-width col-span-full">Catatan produk<textarea class="textarea w-full min-w-0" name="description" maxlength="2000" placeholder="Opsional"></textarea></label></div><p class="product-edit-note">Mengganti satuan tidak mengonversi angka stok saat ini. Riwayat stok lama tetap disimpan sebagai jejak audit.</p><footer><button type="button" class="secondary close-modal btn btn-secondary">Batal</button><button class="primary btn btn-primary">Simpan perubahan</button></footer></form></div></div>
@php
    $salonCapabilities = [
        'override_price' => auth()->user()->can('reservations.override_price'),
        'create_reservation' => auth()->user()->can('reservations.create'),
        'update_reservation' => auth()->user()->can('reservations.update'),
        'manage_finance' => auth()->user()->can('finance.manage'),
        'manage_memberships' => auth()->user()->can('memberships.manage'),
        'view_products' => auth()->user()->can('products.view'),
        'view_sales' => auth()->user()->can('sales.view'),
        'refund_sales' => auth()->user()->can('cashier.refund'),
        'view_memberships' => auth()->user()->can('memberships.view') || auth()->user()->can('memberships.manage'),
        'manage_therapist_attendance' => auth()->user()->can('therapist_attendance.manage'),
        'manage_payroll' => auth()->user()->can('payroll.manage'),
        'process_cashier' => auth()->user()->can('cashier.process'),
        'stocktake_products' => auth()->user()->can('products.stocktake'),
        'update_products' => auth()->user()->can('products.update'),
    ];
@endphp
<div id="toast"></div>
<script>
window.SALON_DATA = @json($salonData ?? []);
window.SALON_CAPABILITIES = @json($salonCapabilities);
window.SALON_RECEIPT_WIDTH_MM = @json(config('printing.receipt_width_mm', 58));
</script>
<script src="{{ asset('js/salon.js') }}?v={{ filemtime(public_path('js/salon.js')) }}"></script>
</body>
</html>
