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
    <style>
        @cannot('reservations.view') #reservasi,.go-reservation{display:none!important} @endcannot
        @cannot('employees.view') #pegawai{display:none!important} @endcannot
        @cannot('cashier.view') #kasir{display:none!important} @endcannot
        @cannot('treatments.view') #treatment{display:none!important} @endcannot
        @cannot('memberships.view') #membership{display:none!important} @endcannot
        @cannot('products.view') #stok,.stock-mini .link{display:none!important} @endcannot
        @cannot('finance.view') #keuangan{display:none!important} @endcannot
        @cannot('payroll.view') #penggajian{display:none!important} @endcannot
        @cannot('activity.view') #log{display:none!important} @endcannot
        @cannot('reservations.create') .open-reservation{display:none!important} @endcannot
        @cannot('employees.create') #open-employee{display:none!important} @endcannot
        @cannot('cashier.process') #open-payment{display:none!important} @endcannot
        @cannot('treatments.create') #treatment .toolbar>.primary{display:none!important} @endcannot
        @cannot('memberships.manage') #membership .card-head>.primary{display:none!important} @endcannot
        @cannot('products.create') #open-product{display:none!important} @endcannot
        @cannot('products.stocktake') #stok .toolbar .secondary{display:none!important} @endcannot
        @cannot('payroll.manage') #penggajian .toolbar>.primary{display:none!important} @endcannot
        @cannot('reservations.update') .status-select{pointer-events:none;opacity:.65} @endcannot
        @cannot('employees.update') .employee-edit{display:none!important} @endcannot
        @cannot('treatments.update') .recipe-button{display:none!important} @endcannot
        @cannot('products.update') .stock-edit,.product-price-edit{display:none!important} @endcannot
        @cannot('payroll.manage') .payroll-edit{display:none!important} @endcannot
    </style>
</head>
<body>
<div class="app">
    @include('partials.internal-sidebar')

    <main>
        <header><div><h1 id="page-title">Dashboard</h1><p id="page-subtitle">Ringkasan operasional salon hari ini</p></div><div class="header-actions"><label class="search"><span class="material-symbols-outlined" aria-hidden="true">search</span><input placeholder="Cari pada halaman aktif..."></label>@can('products.view')<button type="button" class="bell go-stock" title="Buka daftar stok menipis" aria-label="Buka daftar stok menipis"><span class="material-symbols-outlined" aria-hidden="true">notifications</span><sup>0</sup></button>@endcan</div></header>

        <section class="page active" id="dashboard">
            <div class="welcome"><div><small>SABTU, 1 AGUSTUS 2026</small><h2>Selamat pagi, Owner.</h2><p>Hari ini ada <b>8 reservasi</b> dan <b>2 stok produk</b> perlu diperhatikan.</p></div><button class="primary open-reservation"><span class="material-symbols-outlined" aria-hidden="true">add</span> Buat reservasi</button></div>
            <div class="metrics dashboard-metrics">
                <article class="dashboard-metric" role="button" tabindex="0" data-target="reservasi" data-reservation-status="" aria-label="Buka reservasi hari ini"><i class="material-symbols-outlined clay">calendar_month</i><div><small>Reservasi hari ini</small><strong id="metric-reservations">0</strong><span id="metric-serving">0 sedang dilayani</span></div></article>
                <article class="dashboard-metric" role="button" tabindex="0" data-target="reservasi" data-reservation-status="arrived" aria-label="Buka daftar pelanggan yang sudah datang"><i class="material-symbols-outlined green">groups</i><div><small>Pelanggan datang</small><strong id="metric-arrived">0</strong><span id="metric-arrival-rate">0% dari reservasi</span></div></article>
                <article class="dashboard-metric" role="button" tabindex="0" data-target="keuangan" aria-label="Buka laporan pendapatan hari ini"><i class="material-symbols-outlined gold">payments</i><div><small>Pendapatan hari ini</small><strong id="metric-revenue">Rp0</strong><span id="metric-revenue-trend">Belum ada transaksi</span></div></article>
                <article class="dashboard-metric" role="button" tabindex="0" data-target="stok" aria-label="Buka daftar produk dengan stok menipis"><i class="material-symbols-outlined rose">inventory_2</i><div><small>Stok menipis</small><strong id="metric-low-stock">0 produk</strong><span id="metric-stock-note">Stok aman</span></div></article>
            </div>
            <div class="analytics-grid">
                <article class="analytics-card"><div class="analytics-head"><div><h3>Analitik Pendapatan</h3><p>Transaksi dibayar dalam 7 hari terakhir</p></div></div><div class="line-chart" id="revenue-chart"></div></article>
                <article class="analytics-card treatment-performance"><div class="analytics-head"><div><h3>Performa Treatment</h3><p>Treatment dibayar dalam 7 hari terakhir</p></div></div><div class="performance-list" id="treatment-performance"></div></article>
            </div>
            <div class="two-column">
                <div class="card"><div class="card-head"><div><h3>Antrean hari ini</h3><p>Urutan berdasarkan jam reservasi</p></div><button class="link go-reservation">Lihat semua →</button></div><div id="queue-short"></div></div>
                <div class="card"><div class="card-head"><div><h3>Ketersediaan terapis</h3><p>Jadwal aktif hari ini</p></div></div><div class="therapists" id="therapist-availability"></div></div>
            </div>
        </section>

        <section class="page" id="reservasi">
            <div class="reservation-calendar-toolbar">
                <div>
                    <h2>Reservasi</h2>
                    <p>Kelola antrean pelanggan dan jadwal treatment.</p>
                </div>
                <button class="primary open-reservation"><span class="material-symbols-outlined" aria-hidden="true">add</span> Reservasi baru</button>
            </div>
            <div class="reservation-view-tabs" role="tablist" aria-label="Tampilan reservasi">
                <button type="button" class="active" data-reservation-view="queue" role="tab">Antrean hari ini</button>
                <button type="button" data-reservation-view="calendar" role="tab">Kalender</button>
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
            <div id="reservation-queue-view" class="reservation-view">
                <div class="today-queue card reservation-queue-card"><div class="card-head"><div><h3>Antrean hari ini</h3><p id="today-queue-date">Urutan berdasarkan jam reservasi</p></div></div><div id="reservation-queue-list"></div></div>
            </div>
            <div id="reservation-calendar-view" class="reservation-view hidden">
                <div class="calendar-card card"><div id="reservation-calendar" class="reservation-calendar" aria-label="Kalender reservasi mingguan"></div></div>
            </div>
        </section>

        <section class="page" id="pegawai"><div class="toolbar"><div><h3>Master pegawai</h3><p>Data pegawai dan therapist operasional</p></div><button class="primary" id="open-employee"><span class="material-symbols-outlined" aria-hidden="true">add</span> Tambah pegawai</button></div><div class="card"><div class="table employee-table" id="employee-table"></div></div></section>

        <section class="page" id="kasir"><div class="cashier-grid"><div class="card"><div class="card-head"><div><h3>Pilih antrean</h3><p>Pelanggan yang siap diproses</p></div></div><div id="cashier-queue"></div></div><div class="card receipt empty" id="cashier-receipt"><div class="card-head"><div><h3>Transaksi <span id="receipt-number">—</span></h3><p><span id="receipt-name">Pilih antrean terlebih dahulu</span> <b class="member"></b></p></div></div><div id="receipt-items"><p class="empty-state">Belum ada transaksi yang dipilih.</p></div><button class="dashed" id="add-extra" disabled><span class="material-symbols-outlined" aria-hidden="true">add</span> Tambah produk</button><div class="promo"><b class="material-symbols-outlined">campaign</b><span><strong>Diskon membership</strong><small>Event tersedia untuk member</small></span><select id="discount" disabled><option value="0">Tidak menggunakan diskon</option></select></div><div class="totals"><p><span>Subtotal</span><b id="subtotal">Rp0</b></p><p class="discount"><span>Diskon member</span><b id="discount-value">Rp0</b></p><hr><p class="grand"><span>Total pembayaran</span><b id="grand-total">Rp0</b></p></div><button class="primary full" id="open-payment" disabled>Lanjut ke pembayaran →</button></div></div></section>

        <section class="page" id="treatment"><div class="toolbar"><div><h3>Daftar treatment <b id="treatment-count">0</b></h3><p>Filter jenis layanan akan tersedia saat klasifikasi treatment sudah ditetapkan.</p></div><button class="primary" id="open-treatment"><span class="material-symbols-outlined" aria-hidden="true">add</span> Tambah treatment</button></div><div class="treatment-grid" id="treatment-grid"></div></section>

        <section class="page" id="membership"><div class="metrics three"><article><i class="material-symbols-outlined clay">workspace_premium</i><div><small>Member aktif</small><strong id="member-count">0</strong><span id="new-member-count">0 bulan ini</span></div></article><article><i class="material-symbols-outlined gold">campaign</i><div><small>Event aktif</small><strong id="promotion-count">0</strong><span id="ending-promotion-count">0 berakhir bulan ini</span></div></article><article><i class="material-symbols-outlined green">payments</i><div><small>Transaksi member</small><strong id="member-transaction-percent">0%</strong><span>Dari total bulan ini</span></div></article></div><div class="two-column membership-grid"><div class="card"><div class="card-head"><div><h3>Daftar membership</h3><p>Gratis tanpa masa berlaku</p></div><button class="primary" id="open-member"><span class="material-symbols-outlined" aria-hidden="true">add</span> Member baru</button></div><div id="member-list"></div></div><div class="card"><div class="card-head"><div><h3>Event membership</h3><p>Program diskon tersedia</p></div></div><div id="membership-events"></div></div></div></section>

        <section class="page" id="stok"><div class="toolbar"><div class="tabs"><button class="active stock-tab" data-stock="list">Daftar produk <b id="product-count">0</b></button><button class="stock-tab" data-stock="history">Riwayat keluar-masuk</button></div><div><button class="secondary" id="open-stocktake">Stok opname</button><button class="primary" id="open-product"><span class="material-symbols-outlined" aria-hidden="true">add</span> Tambah produk</button></div></div><div class="card"><div id="stock-list" class="table stock-table"></div><div id="stock-history" class="table history-table hidden"></div></div></section>

        <section class="page" id="keuangan"><div class="metrics"><article><i class="material-symbols-outlined green">trending_up</i><div><small>Pemasukan bulan ini</small><strong id="finance-income">Rp0</strong><span>Data arus kas</span></div></article><article><i class="material-symbols-outlined rose">trending_down</i><div><small>Pengeluaran</small><strong id="finance-expense">Rp0</strong><span>Data arus kas</span></div></article><article><i class="material-symbols-outlined gold">account_balance_wallet</i><div><small>Saldo bersih</small><strong id="finance-balance">Rp0</strong><span id="finance-period">Bulan berjalan</span></div></article><article><i class="material-symbols-outlined clay">receipt_long</i><div><small>Transaksi</small><strong id="finance-transaction-count">0</strong><span id="finance-transaction-average">Rata-rata Rp0</span></div></article></div><div class="two-column"><div class="card"><div class="card-head"><div><h3>Arus kas</h3><p>Pemasukan dan pengeluaran bulan berjalan</p></div></div><div class="cash-bars" id="cash-bars"></div></div><div class="card"><div class="card-head"><div><h3>Transaksi terbaru</h3><p>Hari ini</p></div></div><div id="transactions"></div></div></div></section>

        <section class="page" id="penggajian"><div class="toolbar"><div><h3>Periode Agustus 2026</h3><p>Data dapat diubah sebelum ditutup</p></div><p>Rekap dihitung otomatis dari data penggajian.</p></div><div class="card"><div class="table payroll-table" id="payroll-table"></div></div><div class="notice">ⓘ Potongan keterlambatan dimasukkan manual oleh Admin. Komisi dihitung otomatis dari harga normal.</div></section>

        <section class="page" id="log"><div class="card activity-card"><div class="filters"><input id="activity-filter-date" type="date" aria-label="Filter tanggal aktivitas"><select id="activity-filter-user" aria-label="Filter pengguna aktivitas"><option value="">Semua pengguna</option></select><select id="activity-filter-action" aria-label="Filter jenis aktivitas"><option value="">Semua jenis aktivitas</option></select></div><div id="activity-list"></div></div></section>
    </main>
</div>

<div class="modal" id="reservation-modal">
    <div class="modal-box reservation-modal-box">
        <div class="modal-head reservation-modal-head"><div><h2>Reservasi baru</h2><p>Satu kunjungan dapat memuat beberapa treatment dan therapist</p></div><button type="button" class="close-modal reservation-close" aria-label="Tutup form reservasi"><span class="material-symbols-outlined">close</span></button></div>
        <form id="reservation-form" class="reservation-form">
            <div class="form-grid">
                <label>Nama pelanggan<input required name="name" placeholder="Masukkan nama"></label>
                <label>Nomor telepon<input required name="phone" placeholder="08xx xxxx xxxx"></label>
                <label>Tanggal<input required id="reservation-date" name="date" type="date"></label>
                <label>Sumber booking<select name="source"><option value="walk_in">Walk-in</option><option value="whatsapp">WhatsApp</option><option value="phone">Telepon</option><option value="other">Lainnya</option></select></label>
            </div>
            <div class="reservation-items-head"><div><h3>Daftar treatment</h3><p>Atur waktu dan pembagian therapist untuk setiap treatment.</p></div><button type="button" class="secondary" id="add-reservation-item"><span class="material-symbols-outlined" aria-hidden="true">add</span> Tambah treatment</button></div>
            <div id="reservation-items"></div>
            <label class="reservation-notes">Catatan kunjungan<textarea name="notes" placeholder="Permintaan atau catatan umum pelanggan"></textarea></label>
            <div class="conflict-panel hidden" id="reservation-conflict" role="alert"></div>
            <footer class="reservation-footer"><button type="button" class="secondary close-modal">Batal</button><button class="primary"><span class="material-symbols-outlined" aria-hidden="true">check</span> Simpan reservasi</button></footer>
        </form>
    </div>
</div>

<div class="modal" id="payment-modal"><div class="modal-box"><div class="modal-head"><div><h2>Pembayaran</h2><p id="payment-description">Pilih transaksi</p></div><button type="button" class="close-modal"><span class="material-symbols-outlined">close</span></button></div><div class="payment-total"><small>Total invoice</small><strong id="payment-total">Rp0</strong></div><div class="split-payment-head"><div><h3>Metode pembayaran</h3><p>Pembayaran dicatat manual tanpa payment gateway.</p></div><button type="button" class="secondary" id="add-payment-row"><span class="material-symbols-outlined" aria-hidden="true">add</span> Split payment</button></div><div id="payment-rows"></div><div class="payment-reconciliation"><span>Total dicatat <b id="payment-entered">Rp0</b></span><span>Selisih <b id="payment-difference">Rp0</b></span></div><div class="stock-impact"><b>Stok akan berkurang otomatis</b><p>Sesuai resep seluruh treatment pada kunjungan ini.</p></div><footer><button type="button" class="secondary close-modal">Batal</button><button type="button" class="primary" id="complete-payment">Konfirmasi pembayaran</button></footer></div></div>

<div class="modal" id="product-modal"><div class="modal-box"><div class="modal-head"><div><h2>Tambah produk baru</h2><p>Produk dapat digunakan dalam resep treatment</p></div><button class="close-modal"><span class="material-symbols-outlined">close</span></button></div><form id="product-form"><div class="form-grid"><label>Nama produk<input required placeholder="Contoh: Hair Spa L'Oréal"></label><label>Kategori<select><option>Hair</option><option>Facial</option><option>Spa</option><option>Nail</option><option>Konsumsi</option></select></label><label>Stok awal<input type="number" value="500"></label><label>Satuan<select><option>ml</option><option>gr</option><option>pcs</option><option>sachet</option></select></label><label>Batas minimum<input type="number" value="100"></label><label>Harga jual<input type="number" value="0"></label></div><footer><button type="button" class="secondary close-modal">Batal</button><button class="primary">Simpan produk</button></footer></form></div></div>
<div id="toast"></div>
<script>
window.SALON_DATA = @json($salonData ?? []);
window.SALON_CAPABILITIES = @json([
    'override_price' => auth()->user()->can('reservations.override_price'),
    'create_reservation' => auth()->user()->can('reservations.create'),
]);
</script>
<script src="{{ asset('js/salon.js') }}?v={{ filemtime(public_path('js/salon.js')) }}"></script>
</body>
</html>
