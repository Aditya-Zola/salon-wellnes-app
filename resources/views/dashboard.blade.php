<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Selesa Salon - Sistem Operasional</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20,300,0,-25&display=block" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/salon.css') }}">
    <link rel="stylesheet" href="{{ asset('css/redesign.css') }}">
    <link rel="stylesheet" href="{{ asset('css/material-icons.css') }}">
    <link rel="stylesheet" href="{{ asset('css/typography.css') }}">
    <link rel="stylesheet" href="{{ asset('css/mockup-dashboard.css') }}?v={{ filemtime(public_path('css/mockup-dashboard.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/access-control.css') }}?v={{ filemtime(public_path('css/access-control.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/sidebar-polish.css') }}?v={{ filemtime(public_path('css/sidebar-polish.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/scheduling.css') }}?v={{ filemtime(public_path('css/scheduling.css')) }}">
    <style>
        @cannot('reservations.view') #reservasi-antrean,#reservasi-kalender,.go-reservation{display:none!important} @endcannot
        @cannot('therapist_attendance.view') #kehadiran-terapis,.dashboard-therapist-attendance,.go-therapist-attendance{display:none!important} @endcannot
        @cannot('cashier.view') #kasir{display:none!important} @endcannot
        @cannot('sales.view') #penjualan{display:none!important} @endcannot
        @cannot('treatments.view') #treatment{display:none!important} @endcannot
        @cannot('memberships.view') #membership{display:none!important} @endcannot
        @cannot('products.view') #stok,#stok-riwayat,#stok-opname,.stock-mini .link{display:none!important} @endcannot
        @cannot('finance.view') #keuangan-arus-kas,#keuangan-laba-rugi,#keuangan-neraca{display:none!important} @endcannot
        @cannot('payroll.view') #penggajian{display:none!important} @endcannot
        @cannot('activity.view') #log{display:none!important} @endcannot
        @cannot('reservations.create') .open-reservation{display:none!important} @endcannot
        @cannot('cashier.process') #open-payment,#add-extra,.cashier-create-transaction{display:none!important} @endcannot
        @cannot('treatments.create') #treatment .toolbar>.primary{display:none!important} @endcannot
        @cannot('memberships.manage') #membership .membership-manage{display:none!important} @endcannot
        @cannot('products.create') #open-product,#open-product-import{display:none!important} @endcannot
        @cannot('products.stocktake') #open-stocktake,#stok-opname{display:none!important} @endcannot
        @cannot('payroll.manage') #open-payroll{display:none!important} @endcannot
        @cannot('reservations.update') .status-select{pointer-events:none;opacity:.65} @endcannot
        @cannot('treatments.update') .recipe-button,.commission-edit{display:none!important} @endcannot
        @cannot('products.update') .product-price-edit,.product-edit{display:none!important} @endcannot
        @cannot('finance.manage') #open-cash-entry{display:none!important} @endcannot
        @cannot('payroll.manage') .payroll-edit{display:none!important} @endcannot
    </style>
</head>
<body>
<div class="app">
    @include('partials.internal-sidebar')

    <main>
        <header><div><h1 id="page-title">Dashboard</h1><p id="page-subtitle">Ringkasan operasional salon hari ini</p></div><div class="header-actions">@can('products.view')<button type="button" class="bell go-stock" title="Buka daftar stok menipis" aria-label="Buka daftar stok menipis"><span class="material-symbols-outlined" aria-hidden="true">notifications</span><sup>0</sup></button>@endcan</div></header>

        <section class="page active" id="dashboard">
            <div class="welcome"><div><small>SABTU, 1 AGUSTUS 2026</small><h2>Selamat pagi, Owner.</h2><p>Hari ini ada <b>8 reservasi</b> dan <b>2 stok produk</b> perlu diperhatikan.</p></div><button class="primary open-reservation"><span class="material-symbols-outlined" aria-hidden="true">add</span> Buat reservasi</button></div>
            <div class="metrics dashboard-metrics">
                <article class="dashboard-metric" role="button" tabindex="0" data-target="reservasi-antrean" data-reservation-status="" aria-label="Buka reservasi hari ini"><i class="material-symbols-outlined clay">calendar_month</i><div><small>Reservasi hari ini</small><strong id="metric-reservations">0</strong><span id="metric-serving">0 sedang dilayani</span></div></article>
                <article class="dashboard-metric" role="button" tabindex="0" data-target="reservasi-antrean" data-reservation-status="arrived" aria-label="Buka daftar pelanggan yang sudah datang"><i class="material-symbols-outlined green">groups</i><div><small>Pelanggan datang</small><strong id="metric-arrived">0</strong><span id="metric-arrival-rate">0% dari reservasi</span></div></article>
                <article class="dashboard-metric" role="button" tabindex="0" data-target="keuangan-arus-kas" aria-label="Buka arus kas hari ini"><i class="material-symbols-outlined gold">payments</i><div><small>Pendapatan hari ini</small><strong id="metric-revenue">Rp0</strong><span id="metric-revenue-trend">Belum ada transaksi</span></div></article>
                <article class="dashboard-metric" role="button" tabindex="0" data-target="stok" aria-label="Buka daftar produk dengan stok menipis"><i class="material-symbols-outlined rose">inventory_2</i><div><small>Stok menipis</small><strong id="metric-low-stock">0 produk</strong><span id="metric-stock-note">Stok aman</span></div></article>
            </div>
            <article class="payment-revenue-card" aria-labelledby="payment-revenue-title">
                <div class="analytics-head"><div><h3 id="payment-revenue-title">Arus pembayaran hari ini</h3><p>Dana masuk dan refund per nama metode pembayaran</p></div><div class="payment-revenue-summary"><small>Arus bersih hari ini</small><strong id="payment-revenue-total">Rp0</strong><span id="payment-revenue-note">0 metode pembayaran</span></div></div>
                <div class="payment-revenue-list" id="payment-revenue-list"></div>
            </article>
            <div class="analytics-grid">
                <article class="analytics-card treatment-volume"><div class="analytics-head"><div><h3>Treatment harian</h3><p>Jumlah treatment yang sudah dibayar pada bulan berjalan</p></div><span class="analytics-period" id="treatment-volume-period">BULAN INI</span></div><div class="treatment-bar-chart" id="treatment-performance"></div></article>
                <article class="analytics-card popular-treatment"><div class="analytics-head"><div><h3>Treatment sering dilakukan</h3><p>Lima treatment teratas dari transaksi lunas bulan berjalan</p></div><span class="analytics-period">BULAN INI</span></div><div class="performance-list" id="popular-treatments"></div></article>
                <article class="analytics-card revenue-analytics"><div class="analytics-head"><div><h3>Tren pendapatan</h3><p id="revenue-chart-description">Transaksi dibayar pada minggu berjalan</p></div><div class="revenue-period-filter" role="group" aria-label="Filter periode tren pendapatan"><button type="button" class="active" data-revenue-period="week" aria-pressed="true">Minggu</button><button type="button" data-revenue-period="month" aria-pressed="false">Bulan</button><button type="button" data-revenue-period="year" aria-pressed="false">Tahun</button></div></div><div class="line-chart" id="revenue-chart"></div></article>
            </div>
            <div class="card dashboard-operational-card">
                <section class="dashboard-operational-item"><div class="card-head"><div><h3>Antrean hari ini</h3><p>Urutan berdasarkan jam reservasi</p></div><button class="link go-reservation">Lihat semua →</button></div><div id="queue-short"></div></section>
                <section class="dashboard-operational-item"><div class="card-head"><div><h3>Ketersediaan menu treatment</h3><p>Peringatan bahan resep yang stoknya menipis</p></div><button class="link go-stock-alerts">Kelola stok →</button></div><div class="treatment-stock-alerts" id="treatment-stock-alerts"></div></section>
                <section class="dashboard-operational-item dashboard-therapist-attendance"><div class="card-head"><div><h3>Kehadiran terapis</h3><p>Status ketersediaan terapis hari ini</p></div><button class="link go-therapist-attendance">Kelola →</button></div><div class="therapist-availability" id="therapist-availability"></div></section>
            </div>
            @can('employees.view')
                <article class="card therapist-rating-overview"><div class="card-head"><div><h3>Penilaian therapist</h3><p>Rekap bulan berjalan dari rating setelah transaksi kasir.</p></div><span class="analytics-period">BULAN INI</span></div><div class="therapist-rating-list" id="therapist-rating-list"></div></article>
            @endcan
        </section>

        <section class="page reservation-page" id="reservasi-antrean">
            <div class="reservation-page-actions">
                <button class="primary open-reservation"><span class="material-symbols-outlined" aria-hidden="true">add</span> Reservasi baru</button>
            </div>
            <div class="today-queue card reservation-queue-card"><div class="card-head"><div><h3>Antrean hari ini</h3><p id="today-queue-date">Urutan berdasarkan jam reservasi</p></div></div><div id="reservation-queue-list"></div></div>
        </section>

        <section class="page reservation-page" id="reservasi-kalender">
            <div class="reservation-page-actions">
                <button type="button" class="secondary" id="export-schedule" title="Ekspor jadwal pada tanggal yang dipilih"><span class="material-symbols-outlined" aria-hidden="true">download</span> Ekspor Excel</button>
                <button class="primary open-reservation"><span class="material-symbols-outlined" aria-hidden="true">add</span> Reservasi baru</button>
            </div>
            <div class="calendar-controls card">
                <div class="calendar-period">
                    <button type="button" class="calendar-nav" id="calendar-prev" aria-label="Minggu sebelumnya">‹</button>
                    <button type="button" class="secondary calendar-today" id="calendar-today">Hari ini</button>
                    <strong id="calendar-period-label">Minggu ini</strong>
                    <button type="button" class="calendar-nav" id="calendar-next" aria-label="Minggu berikutnya">›</button>
                </div>
                <div class="filters calendar-filters">
                    <input id="reservation-calendar-date" type="date" aria-label="Pilih tanggal kalender">
                    <select id="reservation-filter-employee"><option value="">Semua therapist</option></select>
                    <select id="reservation-filter-status"><option value="">Semua status</option><option value="scheduled">Terjadwal</option><option value="arrived">Sudah datang</option><option value="in_service">Sedang dilayani</option><option value="completed">Selesai</option><option value="cancelled">Batal</option></select>
                </div>
            </div>
            <div class="calendar-mode-tabs" role="tablist" aria-label="Mode kalender reservasi">
                <button type="button" class="active" data-calendar-mode="week" role="tab" aria-selected="true">Ringkasan mingguan</button>
                <button type="button" data-calendar-mode="day" role="tab" aria-selected="false">Harian per therapist</button>
            </div>
            <div class="calendar-card card"><div id="reservation-calendar" class="reservation-calendar" aria-label="Kalender reservasi mingguan"></div></div>
        </section>

        <section class="page" id="kehadiran-terapis">
            <div class="therapist-attendance-layout">
                <div class="card therapist-attendance-page"><div id="therapist-attendance" aria-live="polite"></div></div>
                <aside class="card therapist-attendance-calendar-card" aria-label="Kalender kehadiran terapis">
                    <div id="therapist-attendance-calendar" aria-live="polite"></div>
                </aside>
            </div>
        </section>

        <section class="page" id="kasir">
            <div class="cashier-grid cashier-awaiting-selection">
                <div class="card cashier-queue-card">
                    <div class="card-head cashier-queue-head">
                        <div><h3>Pilih transaksi</h3><p>Pilih kunjungan aktif atau buat transaksi walk-in langsung dari kasir.</p></div>
                        <button type="button" class="primary cashier-create-transaction" id="cashier-new-transaction"><span class="material-symbols-outlined" aria-hidden="true">add_shopping_cart</span> Transaksi baru</button>
                    </div>
                    <div id="cashier-queue"></div>
                </div>
                <div class="card receipt empty" id="cashier-receipt" hidden><div class="card-head"><div><h3>Transaksi <span id="receipt-number">—</span></h3><p><span id="receipt-name">Pilih transaksi terlebih dahulu</span> <b class="member"></b></p></div></div><div id="receipt-items"><p class="empty-state">Belum ada transaksi yang dipilih.</p></div><button class="dashed" id="add-extra" disabled><span class="material-symbols-outlined" aria-hidden="true">add</span> Tambahkan</button><p class="cashier-add-note">Pilih produk retail atau treatment tambahan sebelum pembayaran.</p><div class="promo"><b class="material-symbols-outlined">campaign</b><span><strong>Diskon</strong><small>Gunakan event atau masukkan persentase manual.</small></span><select id="discount" disabled><option value="0">Tidak menggunakan event</option></select><input id="manual-discount" type="number" min="0" max="100" step="0.01" inputmode="decimal" placeholder="Manual %" disabled aria-label="Diskon manual persen"></div><div class="totals"><p><span>Subtotal</span><b id="subtotal">Rp0</b></p><p class="discount"><span>Diskon</span><b id="discount-value">Rp0</b></p><hr><p class="grand"><span>Total pembayaran</span><b id="grand-total">Rp0</b></p></div><button class="primary full" id="open-payment" disabled>Lanjut ke pembayaran →</button></div>
            </div>
        </section>

        <section class="page" id="penjualan">
            <div class="sales-view-tabs" role="tablist" aria-label="Tampilan penjualan">
                <button type="button" class="active" data-sales-view="sales" role="tab">Riwayat penjualan</button>
                <button type="button" data-sales-view="returns" role="tab">Riwayat retur</button>
            </div>
            <div class="toolbar sales-toolbar">
                <div><h3 id="sales-history-title">Riwayat penjualan</h3><p id="sales-history-subtitle">Invoice lunas dan cetak ulang nota.</p></div>
                <div class="sales-filters"><input id="sales-search" type="search" placeholder="Cari invoice atau pelanggan..." aria-label="Cari riwayat penjualan"><select id="sales-payment-filter" aria-label="Filter metode pembayaran"><option value="">Semua pembayaran</option></select></div>
            </div>
            <div class="card sales-history-card"><div class="table sales-history-table" id="sales-history"></div><div class="table-pagination" id="sales-pagination"></div></div>
        </section>

        <section class="page" id="treatment"><div class="card treatment-list-card"><div class="toolbar treatment-toolbar"><div><h3>Daftar treatment <b id="treatment-count">0</b></h3><p>Cari atau kelola menu treatment dan resepnya.</p></div><div class="page-toolbar-actions"><label class="page-search"><span class="material-symbols-outlined" aria-hidden="true">search</span><input id="treatment-search" type="search" placeholder="Cari treatment..." aria-label="Cari treatment"></label><button class="primary" id="open-treatment"><span class="material-symbols-outlined" aria-hidden="true">add</span> Tambah treatment</button></div></div><div class="treatment-grid treatment-card-grid" id="treatment-grid"></div></div></section>

        <section class="page" id="membership">
            <div class="metrics three"><article><i class="material-symbols-outlined clay">workspace_premium</i><div><small>Member aktif</small><strong id="member-count">0</strong><span id="new-member-count">0 bulan ini</span></div></article><article><i class="material-symbols-outlined gold">campaign</i><div><small>Event aktif</small><strong id="promotion-count">0</strong><span id="ending-promotion-count">0 berakhir bulan ini</span></div></article><article><i class="material-symbols-outlined green">payments</i><div><small>Transaksi member</small><strong id="member-transaction-percent">0%</strong><span>Dari total bulan ini</span></div></article></div>
            <div class="two-column membership-grid">
                <div class="card">
                    <div class="card-head"><div><h3>Daftar member</h3><p>Member aktif dan riwayat kunjungannya.</p></div><div class="member-list-actions"><input id="member-search" type="search" inputmode="tel" placeholder="Cari nomor telepon..." aria-label="Cari member berdasarkan nomor telepon"><button class="primary membership-manage" id="open-member"><span class="material-symbols-outlined" aria-hidden="true">add</span> Member baru</button></div></div>
                    <div id="member-list"></div><div class="table-pagination" id="member-pagination"></div>
                </div>
                <div class="card">
                    <div class="card-head"><div><h3>Event membership</h3><p>Diskon yang dapat dipakai di kasir.</p></div><button class="secondary membership-manage" id="open-promotion"><span class="material-symbols-outlined" aria-hidden="true">add</span> Event baru</button></div>
                    <div id="membership-events"></div>
                </div>
            </div>
        </section>

        <section class="page" id="stok">
            <div class="toolbar stock-list-toolbar">
                <div class="stock-toolbar-actions">
                    <div id="stock-list-actions" class="stock-action-group">
                        <label class="page-search"><span class="material-symbols-outlined" aria-hidden="true">search</span><input id="stock-search" type="search" placeholder="Cari produk..." aria-label="Cari produk"></label>
                        <button class="secondary" id="open-stocktake"><span class="material-symbols-outlined" aria-hidden="true">inventory</span> Stok opname</button>
                        <button class="secondary" id="open-stock-reduction"><span class="material-symbols-outlined" aria-hidden="true">remove_shopping_cart</span> Pengurangan stok</button>
                        <button class="secondary" id="open-product-import"><span class="material-symbols-outlined" aria-hidden="true">upload_file</span> Import Excel</button>
                        <button class="primary" id="open-product"><span class="material-symbols-outlined" aria-hidden="true">add</span> Tambah produk</button>
                    </div>
                </div>
            </div>
            <div class="card stock-list-card">
                <div id="stock-list" class="table stock-table"></div>
                <div class="table-pagination" id="product-pagination"></div>
            </div>
        </section>

        <section class="page" id="stok-riwayat">
            <div class="toolbar">
                <div><h3>Riwayat keluar-masuk stok</h3><p>Pergerakan stok terbaru dari transaksi, penyesuaian, dan stok opname.</p></div>
                <div class="stock-history-controls">
                    <label class="stock-history-date"><span>Dari tanggal</span><input id="stock-history-from" type="date" value="{{ today()->startOfMonth()->toDateString() }}" aria-label="Tanggal awal riwayat stok"></label>
                    <label class="stock-history-date"><span>Sampai tanggal</span><input id="stock-history-to" type="date" value="{{ today()->toDateString() }}" aria-label="Tanggal akhir riwayat stok"></label>
                    <button class="secondary" id="export-stock-history"><span class="material-symbols-outlined" aria-hidden="true">download</span> Ekspor Excel</button>
                </div>
            </div>
            <div class="card"><div id="stock-history" class="table history-table"></div><div class="table-pagination" id="stock-history-pagination"></div></div>
        </section>

        <section class="page" id="stok-opname">
            <div class="stocktake-page-toolbar">
                <button type="button" class="secondary" id="stocktake-back"><span class="material-symbols-outlined" aria-hidden="true">arrow_back</span> Kembali ke produk</button>
                <div class="stocktake-page-actions">
                    <button type="button" class="secondary" id="stocktake-reset"><span class="material-symbols-outlined" aria-hidden="true">restart_alt</span> Kosongkan isian</button>
                    <button type="submit" class="primary" id="stocktake-submit" form="stocktake-form" disabled><span class="material-symbols-outlined" aria-hidden="true">save</span> Simpan stok masuk</button>
                </div>
            </div>

            <div class="stocktake-overview">
                <article><span class="material-symbols-outlined" aria-hidden="true">inventory_2</span><div><small>Produk tersedia</small><strong id="stocktake-product-count">0</strong></div></article>
                <article><span class="material-symbols-outlined" aria-hidden="true">add_box</span><div><small>Produk diisi</small><strong id="stocktake-filled-count">0</strong></div></article>
            </div>

            <div class="card stocktake-card">
                <div class="stocktake-card-head">
                    <div><h3>Tambah stok masuk</h3><p>Cari produk lalu masukkan jumlah stok yang baru datang. Kolom kosong tidak akan disimpan.</p></div>
                    <div class="stocktake-filters">
                        <label class="page-search stocktake-search"><span class="material-symbols-outlined" aria-hidden="true">search</span><input id="stocktake-search" type="search" placeholder="Cari nama, kode, atau kategori..." aria-label="Cari produk untuk stok opname"></label>
                        <select id="stocktake-category" aria-label="Filter kategori produk"><option value="">Semua kategori</option></select>
                    </div>
                </div>
                <form id="stocktake-form">
                    <div class="stocktake-table-head" aria-hidden="true"><span>PRODUK</span><span>STOK SEKARANG</span><span>JUMLAH MASUK</span><span>CATATAN</span></div>
                    <div id="stocktake-list" class="stocktake-list" aria-live="polite"></div>
                </form>
            </div>
        </section>

        <section class="page finance-page" id="keuangan-arus-kas"><div class="metrics"><article><i class="material-symbols-outlined green">trending_up</i><div><small>Pemasukan kas</small><strong id="finance-income">Rp0</strong><span>Input manual</span></div></article><article><i class="material-symbols-outlined rose">trending_down</i><div><small>Pengeluaran kas</small><strong id="finance-expense">Rp0</strong><span>Input manual</span></div></article><article><i class="material-symbols-outlined gold">account_balance_wallet</i><div><small>Saldo kas</small><strong id="finance-balance">Rp0</strong><span id="finance-period">Bulan berjalan</span></div></article><article><i class="material-symbols-outlined clay">receipt_long</i><div><small>Catatan kas</small><strong id="finance-cash-entry-count">0</strong><span id="finance-cash-entry-note">Bulan berjalan</span></div></article></div><article class="card finance-payment-flow-card"><div class="card-head"><div><h3>Arus pembayaran per rekening</h3><p>Rekap dana masuk dan refund berdasarkan metode yang diatur di Pengaturan Pembayaran.</p></div><div class="finance-payment-flow-summary"><small>Arus bersih bulan ini</small><strong id="finance-payment-flow-total">Rp0</strong></div></div><div class="finance-payment-flow-list" id="finance-payment-flows"></div></article><div class="two-column"><div class="card"><div class="card-head"><div><h3>Ringkasan kas</h3><p>Rekap dari seluruh input kas manual.</p></div></div><div class="cash-bars" id="cash-bars"></div></div><div class="card"><div class="card-head"><div><h3>Pengeluaran per kategori</h3><p>Pengeluaran kas manual bulan berjalan</p></div></div><div class="cash-bars finance-category-bars" id="finance-category-bars"></div></div></div><div class="card finance-history-card"><div class="card-head"><div><h3>Data kas</h3><p>Catat modal, pemasukan, pembelian, dan biaya operasional secara manual.</p></div><button class="primary" id="open-cash-entry"><span class="material-symbols-outlined" aria-hidden="true">add</span> Input kas</button></div><div class="finance-history-filters"><input id="cash-entry-from" type="date" aria-label="Tanggal awal"><input id="cash-entry-to" type="date" aria-label="Tanggal akhir"><select id="cash-entry-type-filter" aria-label="Filter jenis arus kas"><option value="">Semua jenis</option><option value="income">Pemasukan</option><option value="expense">Pengeluaran</option></select><input id="cash-entry-search" type="search" placeholder="Cari kategori atau deskripsi..." aria-label="Cari data kas"></div><div class="table finance-history-table" id="cash-entry-history"></div></div></section>

        <section class="page finance-page" id="keuangan-laba-rugi"><article class="card finance-report-card"><div class="card-head"><div><h3>Laba-rugi sistem</h3><p>Pendapatan transaksi dikurangi HPP tersimpan dan biaya operasional.</p></div></div><div class="finance-statement" id="profit-loss-report"></div></article></section>

        <section class="page finance-page" id="keuangan-neraca"><article class="card finance-report-card"><div class="card-head"><div><h3>Neraca dasar</h3><p>Posisi kas, setiap rekening pembayaran, dan nilai stok berdasarkan HPP.</p></div></div><div class="finance-statement" id="balance-sheet-report"></div></article></section>

        <section class="page" id="penggajian"><div class="toolbar"><div><h3>Periode Agustus 2026</h3><p>Data dapat diubah sebelum ditutup</p></div><div class="payroll-toolbar-actions"><p>Komisi diambil otomatis dari layanan yang sudah dibayar.</p><button type="button" class="primary" id="open-payroll"><span class="material-symbols-outlined" aria-hidden="true">add</span> Input penggajian</button></div></div><div class="card"><div class="table payroll-table" id="payroll-table"></div></div><div class="notice">ⓘ Gaji pokok, bonus, lembur, serta potongan dicatat manual. Komisi dihitung otomatis dari layanan yang sudah dibayar pada periode tersebut.</div></section>

        <section class="page" id="log"><div class="card activity-card"><div class="card-head"><div><h3>Aktivitas perubahan data</h3><p>Jejak reservasi, stok opname, penjualan, dan perubahan data penting.</p></div></div><div class="filters activity-filters"><label class="activity-search"><span class="material-symbols-outlined" aria-hidden="true">search</span><input id="activity-search" type="search" placeholder="Cari customer, aktivitas, atau pengguna..." aria-label="Cari log aktivitas"></label><input id="activity-filter-date" type="date" aria-label="Filter tanggal aktivitas"><select id="activity-filter-user" aria-label="Filter pengguna aktivitas"><option value="">Semua pengguna</option></select><select id="activity-filter-action" aria-label="Filter kategori aktivitas"><option value="">Semua kategori aktivitas</option></select></div><div id="activity-list"></div></div></section>
    </main>
</div>

<div class="modal" id="reservation-modal">
    <div class="modal-box reservation-modal-box">
        <div class="modal-head reservation-modal-head"><div><h2 id="reservation-modal-title">Reservasi baru</h2><p id="reservation-modal-subtitle">Satu kunjungan dapat memuat beberapa treatment dan therapist</p></div><button type="button" class="close-modal reservation-close" aria-label="Tutup form reservasi"><span class="material-symbols-outlined">close</span></button></div>
        <form id="reservation-form" class="reservation-form">
            <fieldset class="reservation-customer-type">
                <legend>Jenis pelanggan</legend>
                <label><input type="radio" name="customer_type" value="guest" checked> Pelanggan umum</label>
                <label><input type="radio" name="customer_type" value="member"> Member terdaftar</label>
            </fieldset>
            <div id="reservation-member-picker" class="reservation-member-picker" hidden>
                <label>Pilih member</label><div class="reservation-member-combobox"><button id="reservation-member-trigger" class="reservation-member-trigger" type="button" aria-expanded="false" aria-controls="reservation-member-results"><span id="reservation-member-trigger-label">Pilih member</span><i class="material-symbols-outlined" aria-hidden="true">expand_more</i></button><div id="reservation-member-results" class="reservation-member-results" role="listbox" hidden><div class="reservation-member-search"><input id="reservation-member-search" type="text" autocomplete="off" placeholder="Cari nama atau nomor telepon..."></div><div id="reservation-member-options" class="reservation-member-options"></div></div></div><input id="reservation-member-id" name="member_id" type="hidden">
                <p id="reservation-member-preview">Pilih member untuk memakai data pelanggan yang sudah terdaftar.</p>
            </div>
            <div class="form-grid">
                <label class="reservation-guest-field">Nama pelanggan<input required name="name" placeholder="Masukkan nama"></label>
                <label class="reservation-guest-field">Nomor telepon<input required name="phone" placeholder="08xx xxxx xxxx"></label>
                <label>Tanggal<input required id="reservation-date" name="date" type="date"></label>
                <label>Sumber booking<select name="source"><option value="walk_in">Walk-in</option><option value="whatsapp">WhatsApp</option><option value="phone">Telepon</option><option value="other">Lainnya</option></select></label>
            </div>
            <div class="reservation-items-head"><div><h3>Daftar treatment</h3><p>Atur waktu dan pembagian therapist untuk setiap treatment.</p></div><button type="button" class="secondary" id="add-reservation-item"><span class="material-symbols-outlined" aria-hidden="true">add</span> Tambah treatment</button></div>
            <div id="reservation-items"></div>
            <label class="reservation-notes">Catatan kunjungan<textarea name="notes" placeholder="Permintaan atau catatan umum pelanggan"></textarea></label>
            <div class="conflict-panel hidden" id="reservation-conflict" role="alert"></div>
            <footer class="reservation-footer"><button type="button" class="secondary close-modal">Batal</button><button class="primary"><span class="material-symbols-outlined" aria-hidden="true">check</span> <span id="reservation-submit-label">Simpan reservasi</span></button></footer>
        </form>
    </div>
</div>

<div class="modal" id="payment-modal"><div class="modal-box"><div class="modal-head"><div><h2>Pembayaran</h2><p id="payment-description">Pilih transaksi</p></div><button type="button" class="close-modal"><span class="material-symbols-outlined">close</span></button></div><div class="payment-total"><small>Total dibayar</small><strong id="payment-total">Rp0</strong><p class="payment-charge-total" id="payment-charge-total" hidden>Termasuk charge <b>Rp0</b></p></div><div class="split-payment-head"><div><h3>Metode pembayaran</h3><p>Pilihan EDC, Bank, dan QRIS mengikuti data aktif di Pengaturan.</p></div><button type="button" class="secondary" id="add-payment-row"><span class="material-symbols-outlined" aria-hidden="true">add</span> Split payment</button></div><div id="payment-rows"></div><div class="payment-reconciliation"><span>Nilai transaksi <b id="payment-base-total">Rp0</b></span><span>Total dicatat <b id="payment-entered">Rp0</b></span><span>Selisih <b id="payment-difference">Rp0</b></span><span id="payment-change" hidden>Kembalian <b>Rp0</b></span></div><div class="stock-impact"><b>Stok akan berkurang otomatis</b><p>Sesuai resep seluruh treatment pada kunjungan ini.</p></div><footer><button type="button" class="secondary close-modal">Batal</button><button type="button" class="primary" id="complete-payment">Konfirmasi pembayaran</button></footer></div></div>

<div class="modal" id="product-import-modal"><div class="modal-box product-import-modal"><div class="modal-head"><div><h2>Import data produk</h2><p>Tambahkan banyak produk sekaligus dari Excel.</p></div><button type="button" class="close-modal" aria-label="Tutup"><span class="material-symbols-outlined">close</span></button></div><form id="product-import-form" enctype="multipart/form-data"><div class="product-import-content"><div class="product-import-guide"><span class="material-symbols-outlined" aria-hidden="true">table_view</span><div><strong>Kolom file Excel</strong><p>Kode produk, nama produk, kategori, satuan, stok awal, stok minimum, harga jual, status, dan deskripsi.</p></div></div><label class="product-import-picker" for="product-import-file"><span class="material-symbols-outlined" aria-hidden="true">upload_file</span><strong>Pilih file Excel</strong><small id="product-import-file-name">Format .xlsx atau .csv, maksimal 5 MB</small><input id="product-import-file" name="file" type="file" accept=".xlsx,.csv" required></label><p class="product-import-warning"><span class="material-symbols-outlined" aria-hidden="true">info</span>Kode produk yang sudah ada akan dilewati agar data dan stok lama tidak tertimpa.</p><div class="product-import-result" id="product-import-result" hidden></div></div><footer><button type="button" class="secondary close-modal">Batal</button><button class="primary" id="submit-product-import"><span class="material-symbols-outlined" aria-hidden="true">upload</span> Import produk</button></footer></form></div></div>
<div class="modal" id="product-modal"><div class="modal-box"><div class="modal-head"><div><h2>Tambah produk baru</h2><p>Produk dapat digunakan dalam resep treatment</p></div><button class="close-modal"><span class="material-symbols-outlined">close</span></button></div><form id="product-form"><div class="form-grid"><label>Nama produk<input required placeholder="Contoh: Hair Spa L'Oréal"></label><label>Kategori<select><option>Hair</option><option>Facial</option><option>Spa</option><option>Nail</option><option>Konsumsi</option></select></label><label>Stok awal<input type="number" value="500"></label><label>Satuan<select><option>ml</option><option>gr</option><option>pcs</option><option>sachet</option></select></label><label>Batas minimum<input type="number" value="100"></label><label>Harga jual<input type="number" value="0"></label><label>HPP per satuan pakai<input type="number" min="0" value="0"></label></div><footer><button type="button" class="secondary close-modal">Batal</button><button class="primary">Simpan produk</button></footer></form></div></div>
<div class="modal" id="product-edit-modal"><div class="modal-box product-edit-modal"><div class="modal-head"><div><h2 id="product-edit-title">Edit produk</h2><p>Perbaiki data master tanpa mengubah riwayat keluar-masuk stok.</p></div><button type="button" class="close-modal"><span class="material-symbols-outlined">close</span></button></div><form id="product-edit-form"><input type="hidden" name="id"><div class="form-grid"><label>Nama produk<input name="name" required maxlength="150"></label><label>Kategori<input name="category" maxlength="100" placeholder="Contoh: Hair"></label><label>Satuan<select name="unit_id" required></select><small>Gunakan bila satuan sebelumnya salah input.</small></label><label>Batas stok minimum<input name="minimum_stock" type="number" min="0" step="0.0001" required></label><label>Harga jual<input name="selling_price" type="number" min="0" step="1" required></label><label>HPP per satuan pakai<input name="cost_price" type="number" min="0" step="1" required><small>Dipakai untuk nilai stok dan laba-rugi.</small></label><label>Status<select name="is_active"><option value="1">Aktif</option><option value="0">Nonaktif</option></select></label><label class="full-width">Catatan produk<textarea name="description" maxlength="2000" placeholder="Opsional"></textarea></label></div><p class="product-edit-note">Mengganti satuan tidak mengonversi angka stok saat ini. Riwayat stok lama tetap disimpan sebagai jejak audit.</p><footer><button type="button" class="secondary close-modal">Batal</button><button class="primary">Simpan perubahan</button></footer></form></div></div>
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
    ];
@endphp
<div id="toast"></div>
<script>
window.SALON_DATA = @json($salonData ?? []);
window.SALON_CAPABILITIES = @json($salonCapabilities);
</script>
<script src="{{ asset('js/salon.js') }}?v={{ filemtime(public_path('js/salon.js')) }}"></script>
</body>
</html>
