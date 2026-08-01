<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Selesa Salon - Sistem Operasional</title>
    <link rel="stylesheet" href="{{ asset('css/salon.css') }}">
    <link rel="stylesheet" href="{{ asset('css/roles.css') }}">
</head>
<body data-portal="{{ $portal ?? auth()->user()->role }}">
<div class="app">
    <aside class="sidebar">
        <div class="brand"><span>S</span><div><strong>selesa</strong><small>Salon & Wellness</small></div></div>
        <nav id="navigation">
            <button class="active" data-page="dashboard"><b>⌂</b><span>Dashboard</span></button>
            <button data-page="reservasi"><b>▦</b><span>Reservasi</span></button>
            <button data-page="kasir"><b>▣</b><span>Kasir</span></button>
            <button data-page="treatment"><b>✦</b><span>Treatment</span></button>
            <button data-page="membership"><b>◇</b><span>Membership</span></button>
            <button data-page="stok"><b>▤</b><span>Produk & Stok</span></button>
            <button data-page="keuangan"><b>↗</b><span>Keuangan</span></button>
            <button data-page="penggajian"><b>◎</b><span>Penggajian</span></button>
            <button data-page="log"><b>≡</b><span>Log Aktivitas</span></button>
        </nav>
        <div class="account"><i>{{ strtoupper(substr(auth()->user()->name, 0, 2)) }}</i><div><strong>{{ auth()->user()->name }}</strong><small>{{ ucwords(str_replace('_', ' ', auth()->user()->role)) }}</small></div><form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="logout" title="Keluar dari sistem"><b>OUT</b><span>Logout</span></button></form></div>
    </aside>

    <main>
        <header><div><h1 id="page-title">Dashboard</h1><p id="page-subtitle">Ringkasan operasional salon hari ini</p></div><div class="header-actions"><label class="search">⌕ <input placeholder="Cari pelanggan, transaksi..."></label><button class="bell">♢<sup>3</sup></button></div></header>

        <section class="page active" id="dashboard">
            <div class="welcome"><div><small>SABTU, 1 AGUSTUS 2026</small><h2>Selamat pagi, Owner.</h2><p>Hari ini ada <b>8 reservasi</b> dan <b>2 stok produk</b> perlu diperhatikan.</p></div><button class="primary open-reservation">＋ Buat reservasi</button></div>
            <div class="metrics">
                <article><i class="clay">▦</i><div><small>Reservasi hari ini</small><strong>8</strong><span>2 sedang dilayani</span></div></article>
                <article><i class="green">◇</i><div><small>Pelanggan datang</small><strong>5</strong><span>62% dari reservasi</span></div></article>
                <article><i class="gold">↗</i><div><small>Pendapatan hari ini</small><strong>Rp1.485.000</strong><span>Naik 12% dari kemarin</span></div></article>
                <article><i class="rose">!</i><div><small>Stok menipis</small><strong>2 produk</strong><span>Perlu ditambah</span></div></article>
            </div>
            <div class="two-column">
                <div class="card"><div class="card-head"><div><h3>Antrean hari ini</h3><p>Urutan berdasarkan jam reservasi</p></div><button class="link go-reservation">Lihat semua →</button></div><div id="queue-short"></div></div>
                <div class="card"><div class="card-head"><div><h3>Ketersediaan terapis</h3><p>Jadwal aktif hari ini</p></div></div><div class="therapists">
                    <div><i>DI</i><span><b>Dita</b><small>Hair therapist</small></span><em class="busy">Melayani A001</em></div>
                    <div><i>RA</i><span><b>Rani</b><small>Beauty therapist</small></span><em>Siap 10.30</em></div>
                    <div><i>MA</i><span><b>Maya</b><small>Hair therapist</small></span><em>Tersedia</em></div>
                    <div><i>SA</i><span><b>Sari</b><small>Nail artist</small></span><em>Tersedia</em></div>
                </div></div>
            </div>
        </section>

        <section class="page" id="reservasi"><div class="toolbar"><div class="tabs"><button class="active">Hari ini <b>8</b></button><button>Mendatang</button><button>Riwayat</button></div><button class="primary open-reservation">＋ Reservasi baru</button></div><div class="card"><div class="filters"><input type="date" value="2026-08-01"><select><option>Semua terapis</option><option>Dita</option><option>Rani</option><option>Maya</option></select><select><option>Semua status</option><option>Terjadwal</option><option>Sudah datang</option><option>Selesai</option></select></div><div class="table reservation-table"><div class="tr th"><span>ANTREAN</span><span>PELANGGAN</span><span>TREATMENT</span><span>TERAPIS</span><span>STATUS</span><span>AKSI</span></div><div id="queue-table"></div></div></div></section>

        <section class="page" id="kasir"><div class="cashier-grid"><div class="card"><div class="card-head"><div><h3>Pilih antrean</h3><p>Pelanggan yang siap diproses</p></div></div><div id="cashier-queue"></div></div><div class="card receipt"><div class="card-head"><div><h3>Transaksi <span id="receipt-number">A002</span></h3><p><span id="receipt-name">Nadia Prameswari</span> · <b class="member">MEMBER</b></p></div></div><div id="receipt-items"></div><button class="dashed" id="add-extra">＋ Tambah Oxy Spray</button><div class="promo"><b>◇</b><span><strong>Diskon membership</strong><small>Event tersedia untuk member</small></span><select id="discount"><option value="10">Member Facial Week - 10%</option><option value="0">Tidak menggunakan diskon</option></select></div><div class="totals"><p><span>Subtotal</span><b id="subtotal">Rp95.000</b></p><p class="discount"><span>Diskon member</span><b id="discount-value">-Rp9.500</b></p><hr><p class="grand"><span>Total pembayaran</span><b id="grand-total">Rp85.500</b></p></div><button class="primary full" id="open-payment">Lanjut ke pembayaran →</button></div></div></section>

        <section class="page" id="treatment"><div class="toolbar"><div class="tabs"><button class="active">Semua menu <b>58</b></button><button>Treatment</button><button>Tambahan</button><button>Paket</button></div><button class="primary show-toast" data-message="Form treatment baru siap dikembangkan">＋ Tambah treatment</button></div><div class="treatment-grid" id="treatment-grid"></div></section>

        <section class="page" id="membership"><div class="metrics three"><article><i class="clay">◇</i><div><small>Member aktif</small><strong>248</strong><span>+18 bulan ini</span></div></article><article><i class="gold">✦</i><div><small>Event aktif</small><strong>3</strong><span>2 berakhir bulan ini</span></div></article><article><i class="green">↗</i><div><small>Transaksi member</small><strong>68%</strong><span>Dari total bulan ini</span></div></article></div><div class="two-column membership-grid"><div class="card"><div class="card-head"><div><h3>Daftar membership</h3><p>Gratis tanpa masa berlaku</p></div><button class="primary show-toast" data-message="Form member baru siap dikembangkan">＋ Member baru</button></div><div id="member-list"></div></div><div class="card"><div class="card-head"><div><h3>Event membership</h3><p>Program diskon tersedia</p></div></div><div class="event"><small>AKTIF</small><h3>Member Facial Week</h3><p>Diskon 10% seluruh Facial Ritual</p><span>1-31 Agustus 2026 · 42x digunakan</span></div><div class="event pale"><small>AKTIF</small><h3>Hair Care Bundle</h3><p>Harga khusus paket hair treatment</p><span>15 Juli-15 Agustus 2026 · 18x digunakan</span></div></div></div></section>

        <section class="page" id="stok"><div class="toolbar"><div class="tabs"><button class="active stock-tab" data-stock="list">Daftar produk <b>42</b></button><button class="stock-tab" data-stock="history">Riwayat keluar-masuk</button></div><div><button class="secondary show-toast" data-message="Form stok opname siap dikembangkan">Stok opname</button><button class="primary" id="open-product">＋ Tambah produk</button></div></div><div class="card"><div id="stock-list" class="table stock-table"></div><div id="stock-history" class="table history-table hidden"></div></div></section>

        <section class="page" id="keuangan"><div class="metrics"><article><i class="green">↗</i><div><small>Pemasukan bulan ini</small><strong>Rp42.850.000</strong><span>Naik 9,2% dari Juli</span></div></article><article><i class="rose">↘</i><div><small>Pengeluaran</small><strong>Rp18.420.000</strong><span>Termasuk gaji dan stok</span></div></article><article><i class="gold">◎</i><div><small>Saldo bersih</small><strong>Rp24.430.000</strong><span>Per 1 Agustus</span></div></article><article><i class="clay">▣</i><div><small>Transaksi</small><strong>286</strong><span>Rata-rata Rp149.825</span></div></article></div><div class="two-column"><div class="card"><div class="card-head"><div><h3>Arus kas</h3><p>Pemasukan dan pengeluaran bulan berjalan</p></div></div><div class="cash-bars" id="cash-bars"></div></div><div class="card"><div class="card-head"><div><h3>Transaksi terbaru</h3><p>Hari ini</p></div></div><div id="transactions"></div></div></div></section>

        <section class="page" id="penggajian"><div class="toolbar"><div><h3>Periode Agustus 2026</h3><p>Data dapat diubah sebelum ditutup</p></div><button class="primary show-toast" data-message="Rekap gaji berhasil disiapkan">Buat rekap gaji</button></div><div class="card"><div class="table payroll-table" id="payroll-table"></div></div><div class="notice">ⓘ Potongan keterlambatan dimasukkan manual oleh Admin. Komisi dihitung otomatis dari harga normal.</div></section>

        <section class="page" id="log"><div class="card activity-card"><div class="filters"><input type="date" value="2026-08-01"><select><option>Semua pengguna</option><option>Super Admin</option><option>Admin</option><option>Marketing</option><option>Kasir</option></select><select><option>Semua aktivitas</option><option>Transaksi</option><option>Stok</option><option>Pengaturan</option></select></div><div id="activity-list"></div></div></section>
    </main>
</div>

<div class="modal" id="reservation-modal"><div class="modal-box"><div class="modal-head"><div><h2>Reservasi baru</h2><p>Nomor antrean dibuat otomatis sesuai tanggal</p></div><button class="close-modal">×</button></div><form id="reservation-form"><div class="form-grid"><label>Nama pelanggan<input required name="name" placeholder="Masukkan nama"></label><label>Nomor telepon<input required placeholder="08xx xxxx xxxx"></label><label>Tanggal<input type="date" value="2026-08-01"></label><label>Jam<input type="time" value="14:00"></label><label>Treatment<select><option>Skin Barrier Facial - Rp95.000</option><option>L'Oréal Hair Spa - Rp185.000</option><option>Makarizo Creambath - Rp125.000</option></select></label><label>Terapis tersedia<select><option>Maya</option><option>Sari</option></select></label></div><label>Catatan<textarea placeholder="Permintaan pelanggan"></textarea></label><footer><button type="button" class="secondary close-modal">Batal</button><button class="primary">Simpan reservasi</button></footer></form></div></div>

<div class="modal" id="payment-modal"><div class="modal-box small"><div class="modal-head"><div><h2>Pembayaran</h2><p>TRX-004 · Nadia Prameswari</p></div><button class="close-modal">×</button></div><div class="payment-total"><small>Total pembayaran</small><strong id="payment-total">Rp85.500</strong></div><div class="payment-methods"><button class="active">Tunai</button><button>QRIS</button><button>Transfer</button><button>Kartu</button></div><div class="stock-impact"><b>Stok akan berkurang otomatis</b><p>Skin Barrier Mask -1 pcs · Cleanser -10 ml · Herbal Drink -1 sachet</p></div><footer><button class="secondary close-modal">Batal</button><button class="primary" id="complete-payment">Bayar & cetak struk</button></footer></div></div>

<div class="modal" id="product-modal"><div class="modal-box"><div class="modal-head"><div><h2>Tambah produk baru</h2><p>Produk dapat digunakan dalam resep treatment</p></div><button class="close-modal">×</button></div><form id="product-form"><div class="form-grid"><label>Nama produk<input required placeholder="Contoh: Hair Spa L'Oréal"></label><label>Kategori<select><option>Hair</option><option>Facial</option><option>Spa</option><option>Nail</option><option>Konsumsi</option></select></label><label>Stok awal<input type="number" value="500"></label><label>Satuan<select><option>ml</option><option>gr</option><option>pcs</option><option>sachet</option></select></label><label>Batas minimum<input type="number" value="100"></label><label>Harga jual<input type="number" value="0"></label></div><footer><button type="button" class="secondary close-modal">Batal</button><button class="primary">Simpan produk</button></footer></form></div></div>
<div id="toast"></div>
<script src="{{ asset('js/salon.js') }}"></script>
</body>
</html>
