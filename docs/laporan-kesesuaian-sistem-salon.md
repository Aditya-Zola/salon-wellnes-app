# Laporan Kesesuaian Sistem Operasional Salon

**Tanggal pemeriksaan:** 9 November 2026
**Status:** Validasi lokal selesai untuk refund DP penuh dan penggantian treatment; penyelesaian total baru sama dengan/di bawah DP belum didukung
**Dasar pemeriksaan:** kode aplikasi, 108 tes dengan 1.301 assertion, audit browser desktop/HP, serta pembayaran uji di database QA terpisah.

## Ringkasan

Fondasi sistem sudah berjalan dan seluruh Feature Test saat ini lulus. Modul utama—reservasi, kasir, pembayaran, stok, keuangan, remunerasi, akses pengguna, dan pengaturan metode pembayaran—sudah saling terhubung.

Laporan ini membedakan fitur yang sudah terbukti di sistem dari aturan bisnis yang masih perlu disetujui oleh salon. Persetujuan salon tetap diperlukan untuk memastikan istilah, nominal, dan kebiasaan kerja di lapangan sama dengan implementasi.

## Status per modul

| Modul | Kondisi saat ini | Status |
|---|---|---|
| Login dan hak akses | Login tanpa pilihan role, role/permission membatasi halaman dan aksi backend | Sesuai secara teknis |
| Reservasi | Satu kunjungan dapat memiliki beberapa treatment dan therapist; data customer, jadwal, catatan, dan status tersimpan | Sesuai secara teknis |
| Jadwal therapist | Dropdown putih membuka ke bawah dan langsung menampilkan daftar, status sesuai jadwal pilihan, serta jam siap kembali; terapis libur tidak bisa dipilih. Jadwal bertumpuk tetap mengikuti aturan yang sudah ada | Terverifikasi; SOP overlap tetap perlu disepakati |
| Antrean | Ada status reservasi, status treatment, informasi DP/sisa, metode pembayaran, dan tombol selesai | Sesuai rancangan terakhir |
| Penyelesaian treatment | Operator dapat menandai selesai; sistem memberi penyelesaian otomatis setelah estimasi + toleransi 15 menit | Perlu uji lapangan |
| DP reservasi | Pembuatan reservasi menyimpan rencana DP; uang baru tercatat setelah dikonfirmasi kasir. Nota DP dan pelunasan dapat dicetak terpisah berdasarkan waktu pembayarannya | Terverifikasi |
| Kasir | Mendukung cash, kartu/EDC, transfer, QRIS, split payment, produk tambahan, dan invoice | Sesuai secara teknis |
| Charge pembayaran | Pilihan 0-20% dengan selisih 0,5%; nominal bayar otomatis mencakup charge. Satu metode memakai total tagihan sebelum DP; split menghitung charge dari alokasi masing-masing metode | Terverifikasi |
| Referensi pembayaran | Nomor referensi dibuat opsional | Sesuai keputusan terakhir |
| Refund/retur | Refund dicatat manual dengan struk 58 mm dan nota A4. Riwayat mengacu pada invoice asal; retur sisa dibatasi nominal/kuantitas yang belum dikembalikan | Terverifikasi |
| Pembatalan reservasi | Riwayat Dibatalkan memuat pembatalan penuh/sebagian. DP pada pembatalan penuh dikembalikan seluruhnya melalui konfirmasi kasir; sebelum konfirmasi berstatus menunggu. Penerimaan DP tetap tersimpan dan refund menjadi arus keluar pada waktu konfirmasi | Terverifikasi |
| Penggantian treatment setelah DP | Tombol Ganti treatment tersedia di antrean dan detail. Treatment lama dibatalkan dan pengganti ditambahkan dalam satu transaksi database; DP dan nota awal tetap utuh. Treatment selesai atau transaksi lunas tidak dapat diganti | Terverifikasi; total baru sama dengan/di bawah DP belum dapat diselesaikan |
| Format dokumen | Prefix invoice, DP, retur, dan pembatalan dapat diatur. Pengaturan baru tidak mengganti nomor dokumen lama | Terverifikasi |
| Membership | Tombol Riwayat membuka halaman tersendiri dengan pencarian dan paginasi 20 layanan; pilihan member di form baru muncul setelah mengetik | Terverifikasi |
| Metode pembayaran | QRIS, EDC, dan bank dapat dikelola; metode yang sudah dipakai diarsipkan, bukan menghapus histori transaksi | Sesuai secara teknis |
| Produk dan stok | Produk, satuan, minimum stok, resep, stok masuk/keluar, opname, import Excel, dan histori tersedia | Sesuai secara teknis |
| Keuangan | Arus kas, laba-rugi, neraca, refund, HPP, dan biaya manual tersedia dengan filter tanggal otomatis | Sesuai secara teknis |
| Penggajian/remunerasi | Input gaji manual, komisi dari transaksi lunas, attendance/lembur, rekap, export, dan arsip final tersedia | Perlu konfirmasi formula salon |
| Notifikasi | Toast aksi juga masuk ke panel bell selama sesi berjalan | Perlu keputusan apakah harus tersimpan lintas login |

## Alur kerja yang saat ini dipakai

1. Operator membuat reservasi dan memilih treatment, terapis, serta nominal rencana DP bila diperlukan.
2. Kasir mengonfirmasi penerimaan DP; tanggal/jam penerimaan dicatat saat konfirmasi, kemudian struk/nota DP dapat dicetak.
3. Reservasi masuk ke antrean dan kalender. Jadwal dapat bertumpuk; status menjadi informasi operasional, bukan pengunci jadwal.
4. Treatment dapat diselesaikan melalui tombol **Selesai** atau ditutup otomatis setelah estimasi selesai dan toleransi 15 menit terlewati.
5. Kasir membuka invoice, menerima pelunasan atau pembayaran split, memilih metode, dan memilih charge bila diperlukan.
6. Setelah pembayaran berhasil, stok resep berkurang dan transaksi masuk ke laporan keuangan serta sumber komisi.
7. Refund tidak berjalan otomatis. Operator memilih transaksi, alasan, metode pengembalian, dan apakah barang dikembalikan ke stok.
8. Penggajian diinput manual per periode. Remunerasi membaca komisi dari transaksi lunas, lalu hasilnya dapat difinalisasi dan disimpan sebagai arsip.

## Hal yang perlu disetujui pihak salon

- Apakah toleransi otomatis selesai 15 menit sudah tepat untuk semua jenis treatment?
- Apakah jadwal bertumpuk selalu boleh, atau hanya therapist tertentu yang boleh menerima overlap?
- Tarif charge yang dipilih kasir untuk masing-masing metode pembayaran mengikuti kebijakan salon; sistem menyediakan 0-20%.
- Apakah DP boleh kurang/lebih dari 50% tanpa batas minimum?
- Refund DP pada pembatalan penuh sudah disepakati dikonfirmasi kasir. Apakah refund/retur transaksi lunas tetap memerlukan persetujuan owner?
- Apakah nominal lembur hanya dimasukkan di Penggajian, bukan di Kehadiran Terapis?
- Kapan periode gaji ditutup dan difinalisasi setiap bulan?
- Apakah notifikasi cukup selama sesi login, atau perlu histori permanen per pengguna?
- Nama metode pembayaran apa yang harus tampil persis di kasir dan laporan?

## Temuan yang belum menjadi fitur laporan salon terpadu

Sistem sudah memiliki laporan keuangan, penjualan, stok, dan remunerasi secara terpisah. Namun belum ada satu halaman **Laporan Salon** yang menggabungkan seluruh ringkasan operasional dalam satu export. Jika diperlukan, halaman berikutnya dapat dibuat dengan filter tanggal dan bagian:

- ringkasan reservasi dan status treatment;
- pendapatan per metode pembayaran;
- DP, pelunasan, dan piutang/sisa pembayaran;
- refund/retur;
- treatment terlaris dan komisi therapist;
- stok masuk, pemakaian resep, dan stok akhir;
- pengeluaran kas dan laba bersih;
- remunerasi per therapist/pegawai.

## Kesimpulan sementara

Secara teknis, sistem sudah cukup untuk diuji oleh operator salon menggunakan skenario nyata. Status final belum boleh disebut 100% sesuai sebelum daftar pertanyaan bisnis di atas dijawab dan dilakukan uji penerimaan di halaman reservasi, kasir, refund, keuangan, stok, dan penggajian.

**Hasil automated test terakhir:** 108 lulus, 1.301 assertion, tanpa kegagalan.

Kasus yang belum ditangani: total transaksi setelah pergantian treatment sama dengan atau lebih kecil dari DP. Checkout saat ini menolak kondisi tersebut; penyelesaian tanpa pembayaran tambahan dan refund selisih belum tersedia.

## Pemeriksaan kesiapan push - 9 November 2026

- Build Vite final berhasil, 19 berkas sumber PHP yang berubah/baru lolos pemeriksaan sintaks, seluruh Blade berhasil dikompilasi, dan `composer.json` valid.
- Audit 70 tampilan browser mencakup Dashboard sampai Pengaturan, stok opname, arsip remunerasi, riwayat member/pembatalan, halaman edit hak akses, login, serta dialog utama pada desktop dan HP.
- Pembayaran uji yang benar-benar disimpan di database QA: total Rp125.000 + DP Rp62.500 + charge 3,5% menghasilkan pelunasan Rp66.875 dan total invoice Rp129.375; pembayaran langsung dengan charge 20%; serta split dengan charge 7,5%.
- Seluruh 41 pilihan charge (0-20%) diperiksa di browser. Nota DP, pelunasan, retur A4, dan struk retur 58 mm berhasil diterbitkan.
- Kasir memperlihatkan nilai sebelum charge, charge, total yang harus dibayar, dan nominal dibayar. Kartu pelunasan serta ringkasan invoice mengikuti perubahan charge.
- Riwayat pembatalan membedakan pembatalan seluruh reservasi dan sebagian treatment. Nomor pembatalan disimpan saat aksi berlangsung, termasuk ketika item terakhir mengubah reservasi menjadi batal seluruhnya.
- Pengujian tambahan membuktikan refund DP penuh hanya bisa dilakukan kasir, tidak tercatat dua kali, tidak mengubah nota/penerimaan DP, serta masuk laporan arus pembayaran dan neraca sesuai tanggal serta jenis bayar pengembalian. Refund DP bukan biaya operasional.
- Alur browser desktop/HP diuji sampai penyimpanan penggantian treatment Rp95.000 menjadi Rp125.000 dengan DP tetap Rp47.500, dilanjutkan pembatalan dan konfirmasi refund penuh. Data uji hanya berada di database QA.
- Form tambah/ganti treatment menggunakan kontrol terapis dan komisi dari reservasi: tambah/hapus hingga 10 terapis, peran utama/pendamping, status jadwal, serta komisi per terapis. Browser memverifikasi penyimpanan dua terapis dengan komisi 15% dan 10%; backend menolak komisi negatif, di atas 100%, atau alokasi gabungan melebihi 100%.
- Uji remunerasi tambahan memastikan DP saja belum menghasilkan komisi. Setelah penggantian facial Rp95.000 (komisi lama 30%) menjadi creambath Rp125.000 dan pelunasan dengan charge 3,5%, rekap serta draft penggajian hanya memuat komisi pengganti 10% = Rp12.500 dan 5% = Rp6.250. Rincian komisi hanya memuat dua terapis pada layanan pengganti; DP, charge, dan komisi layanan lama tidak menambah nilainya.
- Menu Edit treatment menyediakan Atur durasi (1-1.440 menit), dibatasi hak treatments.update dan dicatat di log aktivitas. Durasi baru berlaku untuk input layanan berikutnya; snapshot durasi, jadwal, harga, dan komisi reservasi lama tetap utuh. Tiga tes terkait durasi/harga/komisi lulus (32 assertion); menu dan penyimpanan durasi sudah diperiksa di desktop/HP.
- Nomor DP baru disimpan permanen; DP lama tanpa nomor tersimpan tetap memakai format lamanya. Prefix retur/invoice yang sudah terbit tidak diubah ketika pengaturan diganti.
- Ringkasan arus pembayaran Dashboard mengikuti jumlah arus bersih kartu metode; angka pendapatan penjualan tetap terpisah sesuai sumber datanya.
- Background login memakai foto salon asli tanpa overlay/filter; banner Dashboard dihapus. Batas area logo sidebar mengikuti tinggi header, terverifikasi tanpa selisih di desktop/HP, saat judul membungkus, serta setelah sidebar dilipat/dibuka.
- Log Aktivitas memakai tabel dengan garis vertikal, tanggal/jam, pengguna, jenis, keterangan, dan pelanggan/reservasi. Filter pengguna, kategori, tanggal, pencarian, serta hasil kosong telah diperiksa di browser; layar kecil memakai gulir tabel tanpa memperlebar halaman.
- `.nvmrc` memakai Node 22, sesuai workflow CI. Versi Node lokal yang dipakai memverifikasi build adalah 22.12.0.
- Migrasi `2026_11_09_090000_add_document_numbers.php` hanya menambah kolom nomor nullable serta tabel urutan pembatalan. Sudah diterapkan di QA dan MySQL lokal; pemeriksaan nilai pembayaran sebelum/sesudah tetap sama.
- Migrasi `2026_11_09_100000_create_reservation_deposit_refunds.php` menambah pencatatan refund DP. Sudah diterapkan di QA dan MySQL lokal; seluruh baris penerimaan DP dan pembayaran transaksi tetap utuh.
- Workflow migrasi produksi yang ada berjalan setelah tes lulus untuk push ke `main` pada repositori `RezaAmru/salon-wellnes-app`. Pengiriman branch fitur tidak menjalankan workflow migrasi produksi tersebut; produksi belum diverifikasi pada pemeriksaan lokal ini.

Pengiriman ditujukan ke branch `feat/salon-ui-sidebar-dashboard-20261028` pada `origin` dan `vercel`, dengan commit yang sama untuk kedua repo. Berkas aplikasi/migrasi/view baru disertakan; `.env`, hasil build, dan artefak QA di `storage/framework/testing` tetap diabaikan Git.
