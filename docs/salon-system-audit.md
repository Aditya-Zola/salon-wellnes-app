# Audit Sistem Operasional Salon

## Status dokumen

Dokumen ini mencatat kondisi repository pada commit dasar `f1d225d` di branch `feat/salon-operational-system`. Bagian **kondisi saat ini** hanya memuat kemampuan yang benar-benar ditemukan pada kode. Bagian **target** atau **rencana** bukan pernyataan bahwa fitur tersebut sudah tersedia.

Sumber audit:

- repository Laravel dan test yang tersedia;
- `TEMPLATE JADWAL SELESA (7).xlsx` sebagai referensi alur jadwal;
- `CONTOH REKAP REMUNERASI SELESA.xlsx` sebagai referensi remunerasi;
- keputusan bisnis yang sudah dikonfirmasi pada 6 Agustus 2026.

Keputusan yang sudah dikunci:

- seluruh environment masih berisi data demo, sehingga struktur migration operasional awal boleh dirancang ulang;
- setiap invoice hanya untuk satu pelanggan dan satu kunjungan;
- satu invoice boleh dibayar dengan beberapa metode pembayaran;
- metode pembayaran adalah label pencatatan manual, tanpa payment gateway;
- benturan jadwal menghasilkan peringatan dan hanya dapat di-override oleh pengguna berizin dengan alasan wajib;
- timezone bisnis adalah `Asia/Jakarta`.

## Ringkasan eksekutif

Repository sudah memiliki fondasi autentikasi, RBAC, dashboard operasional, reservasi sederhana, checkout sederhana, stok, payroll ringkas, dan activity log. Namun, bentuk data saat ini masih satu reservasi–satu treatment–satu therapist. Pembayaran hanya menyimpan satu label metode, payroll berupa total bulanan tanpa relasi pegawai, dan domain operasional ditangani langsung oleh satu controller menggunakan query builder.

Dengan demikian, sistem saat ini adalah prototype operasional yang dapat dipakai sebagai fondasi UI dan akses, tetapi belum dapat menggantikan workbook jadwal dan remunerasi. Prioritas awal adalah memperbaiki model kunjungan, memisahkan akun dari pegawai, mendukung beberapa treatment dan beberapa pegawai per treatment, mencatat waktu kerja, serta menutup celah otorisasi dan konsistensi transaksi.

## Stack dan arsitektur saat ini

| Area | Kondisi terverifikasi |
|---|---|
| Runtime lokal | PHP 8.4.15; requirement project PHP `^8.2` |
| Framework | Laravel 12.64.0 |
| Database lokal | MySQL; automated test menggunakan SQLite in-memory |
| Frontend | Blade, vanilla JavaScript, dan CSS; Vite tersedia tetapi halaman operasional memuat aset dari `public/` |
| Autentikasi | Session-based login/logout Laravel |
| Otorisasi | `spatie/laravel-permission` 6.x, middleware permission, dan `Gate::before` untuk `super-admin` |
| Domain access | Sebagian besar operasi berada di `SalonController` melalui `DB::table`; belum ada model, service, action, policy, atau Form Request khusus domain salon |
| Timezone aplikasi | Masih `UTC` pada `config/app.php`; belum sesuai keputusan `Asia/Jakarta` |
| Baseline test | 17 test dan 73 assertion lulus sebelum perubahan Phase 1 |

## Autentikasi, role, dan permission

### Role bawaan

| Role | Hak akses saat ini |
|---|---|
| `super-admin` | Seluruh permission melalui `Gate::before` dan sinkronisasi seluruh permission |
| `admin` | Seluruh permission operasional, kecuali permission berawalan `access.` |
| `marketing` | Dashboard, reservasi, treatment view, membership, produk/stok, dan stok opname sesuai daftar seeder |
| `kasir` | Dashboard, reservasi view, kasir, treatment view, membership view, dan produk view |

### Kelompok permission yang tersedia

Permission tersimpan dengan pola `module.action`:

- `dashboard.view`;
- `reservations.view`, `reservations.create`, `reservations.update`, `reservations.delete`;
- `cashier.view`, `cashier.process`, `cashier.refund`;
- `treatments.view`, `treatments.create`, `treatments.update`, `treatments.delete`;
- `memberships.view`, `memberships.manage`;
- `products.view`, `products.create`, `products.update`, `products.delete`, `products.stocktake`;
- `finance.view`, `finance.manage`;
- `payroll.view`, `payroll.manage`;
- `activity.view`;
- `access.roles.view`, `access.roles.manage`, `access.users.view`, `access.users.manage`.

Route mutasi yang tersedia sudah memakai permission middleware. Sidebar dan sebagian tombol juga disembunyikan dengan Blade `@can`. Ini dapat digunakan kembali, tetapi penyembunyian UI bukan pengganti pembatasan data di backend.

## Struktur database operasional saat ini

Migration `2026_08_03_120000_create_salon_operation_tables` saat audit mendefinisikan tabel berikut.

| Tabel | Fungsi dan batasan saat ini |
|---|---|
| `therapists` | Master therapist sederhana; tidak terhubung dengan akun `users` dan tidak mencakup pegawai non-therapist |
| `treatments` | Nama, kategori berbentuk teks, durasi, harga, persentase komisi default, dan status aktif |
| `products` | Nama, kategori teks, saldo stok, satu unit teks, minimum stok, dan harga jual |
| `treatment_product` | Resep treatment–produk dengan satu angka quantity, tanpa master unit/konversi |
| `customers` | Nama, telepon unik, status membership, tanggal member, dan visit count |
| `promotions` | Promo persentase berbasis periode dan flag member |
| `reservations` | Satu customer, satu treatment, satu therapist, satu tanggal/jam, status teks, dan catatan |
| `transactions` | Satu reservation opsional, satu customer opsional, total snapshot, dan satu `payment_method` berbentuk teks |
| `transaction_items` | Snapshot item sederhana; belum terhubung ke item pekerjaan/reservasi |
| `stock_movements` | Mutasi produk dengan type/source/reference teks |
| `cash_entries` | Pemasukan/pengeluaran ringkas tanpa relasi detail pembayaran |
| `payrolls` | Nama dan posisi pegawai berupa snapshot teks serta total komponen per bulan |
| `activity_logs` | Actor, action, subject generik, description, dan metadata JSON |

Tabel autentikasi/RBAC meliputi `users`, `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, dan `role_has_permissions`, di samping tabel standar cache/jobs Laravel.

Belum ada model Eloquent domain yang mendefinisikan relasi tersebut. Relasi saat ini diwujudkan oleh foreign key dan query manual dalam controller.

## Alur yang benar-benar tersedia

### Reservasi

1. Endpoint menerima nama dan telepon pelanggan, tanggal, jam, satu treatment, satu therapist, dan catatan.
2. Customer dicari berdasarkan nomor telepon; nama diperbarui atau customer baru dibuat.
3. Durasi treatment dipakai untuk mencari irisan jadwal therapist.
4. Benturan saat ini di-hard-block dengan HTTP 422.
5. Reservasi disimpan lalu nomor antrean harian diurutkan ulang berdasarkan jam.
6. Pembuatan dan perubahan status ditulis ke activity log.

Status yang diterima saat ini adalah `Terjadwal`, `Sudah datang`, `Sedang dilayani`, `Selesai`, dan `Batal`. Belum ada item treatment terpisah, staf tambahan, state machine, waktu aktual mulai/selesai/ready, status lanjut, atau data override benturan.

### Transaksi dan pembayaran

1. Kasir memilih satu reservasi dan satu label metode: Tunai, QRIS, Transfer, atau Kartu.
2. Backend membaca harga treatment, menghitung diskon member, membuat satu transaction dan satu transaction item.
3. Resep treatment dibaca; stok dikurangi dan stock movement dibuat.
4. Reservasi ditandai selesai, visit count customer bertambah, cash entry dibuat, lalu activity log ditulis.
5. Langkah tersebut dibungkus satu database transaction.

Alur ini sudah memiliki transaksi database dasar, tetapi belum memiliki split payment, master metode pembayaran, idempotency key, unique constraint satu invoice per kunjungan, refund/reversal, atau penomoran yang aman terhadap dua checkout bersamaan. Kalkulasi diskon juga masih melewati cast `float`.

Tidak ada payment gateway. Label seperti Tunai, QRIS BCA, Transfer, atau Debit hanya dimaksudkan untuk rekonsiliasi manual. Karena itu sistem tidak memerlukan token kartu, webhook, callback gateway, atau penyimpanan credential pembayaran.

### Stok

Produk dapat dibuat, stok dapat disesuaikan untuk masuk/keluar/opname, dan resep produk per treatment dapat dicatat. Checkout mengurangi stok berdasarkan resep dan mencatat movement. Belum ada master unit, konversi kemasan ke unit penggunaan, receipt, usage ledger yang terhubung ke appointment item, reversal, maupun perlindungan lengkap terhadap pengurangan ganda saat request bersamaan.

### Payroll

Payroll saat ini hanya menyimpan nama pegawai, posisi, periode, gaji pokok, bonus, potongan terlambat, komisi, dan teks durasi terlambat. Admin dapat mengubah beberapa angka tersebut. Belum ada employee foreign key, payroll period, attendance, komponen terurai, commission/overtime ledger, approval, finalization, locking, atau payslip yang dapat diaudit.

### Dashboard dan activity log

Dashboard menghitung antrean hari ini, kedatangan, pendapatan, stok minimum, tren tujuh hari, membership, promo, dan arus kas dari data operasional. Activity log dasar tersedia untuk sejumlah mutasi. Keduanya dapat dipakai kembali setelah query disesuaikan dengan struktur baru.

## Perbandingan kondisi lama dan kebutuhan awal

| Area | Struktur/kode saat ini | Struktur target awal | Alasan | Risiko perubahan |
|---|---|---|---|---|
| Pegawai | `therapists` berdiri sendiri | `employees` dengan `user_id` opsional | Satu orang perlu menjadi staf treatment, penerima komisi/lembur, dan payroll tanpa wajib mempunyai akun | Nama therapist demo harus dipetakan atau database demo di-reset |
| Kategori treatment | Teks pada `treatments.category` | `treatment_categories` | Normalisasi filter dan menghindari variasi penulisan | Seeder/UI perlu memakai foreign key |
| Reservasi | Header memuat satu `treatment_id` dan `therapist_id` | `reservations` sebagai kunjungan, `reservation_items`, `reservation_item_staff` | Satu kunjungan dapat berisi beberapa treatment dan setiap item dapat dikerjakan beberapa pegawai | Seluruh query jadwal, kasir, dashboard, dan test berubah |
| Waktu/status | Satu tanggal/jam dan status Indonesia bebas | Jadwal item serta timestamp aktual; status kanonis | Memisahkan jam booking dari mulai, selesai, ready, lanjut, dan lembur | Perlu aturan transisi dan penyajian label Indonesia |
| Konflik jadwal | Hard-block | Warning; override dengan permission, alasan, actor, dan waktu | Praktik Excel menunjukkan overlap yang memang kadang diizinkan | Override tanpa audit dapat disalahgunakan |
| Harga/komisi | Harga treatment dibaca saat checkout | Snapshot harga normal/aktual/diskon/komisi pada item | Perubahan master tidak boleh mengubah sejarah | Aturan komisi final belum dikunci |
| Pembayaran | Satu string pada transaction | `payment_methods` dan banyak `transaction_payments` | Mendukung split payment serta konsistensi label | Jumlah bagian wajib sama persis dengan total invoice |
| Invoice | Belum ada unique satu transaksi per reservasi | Satu transaction per reservation/customer | Keputusan bisnis satu invoice satu pelanggan/kunjungan | Perlu unique constraint dan proteksi concurrent request |
| Unit/stok | Unit berupa teks pada produk/resep | Master `units`, unit beli/pakai, faktor konversi, ledger movement | Excel mencampur ml, gram, pcs, sachet, dan kemasan | Konversi salah akan merusak saldo stok |
| Payroll | Nama/posisi dan total bulanan | Employee FK dan, pada phase lanjut, period/component ledger | Payroll perlu dapat ditelusuri ke sumber | Formula remunerasi belum disepakati |
| Activity log | Subject generik dan deskripsi | Tetap dipakai, metadata before/after dan alasan sensitif | Audit override, checkout, reversal, dan finalisasi | Payload sensitif harus dibatasi |

## Bagian yang dapat digunakan kembali

- session authentication, CSRF middleware, dan model `User`;
- Spatie RBAC, halaman pengelolaan role/user, permission middleware, dan super-admin gate;
- layout, sidebar, pola modal, tema visual, dan format tampilan yang sudah ada;
- master customer, treatment, product, promo, dan resep sebagai konsep bisnis, walaupun skemanya perlu dinormalisasi;
- pola database transaction pada checkout dan stock adjustment;
- activity log dasar;
- dashboard analytics dan test scaffold sebagai baseline regresi.

Bagian yang tidak layak dipertahankan sebagai desain akhir adalah satu controller monolitik, query domain langsung di controller, kalkulasi finansial bercampur dengan HTTP flow, status bebas tanpa state machine, dan render HTML dinamis yang tidak konsisten melakukan escaping.

## Temuan keamanan dan integritas

| Prioritas | Temuan saat ini | Dampak | Arah perbaikan |
|---|---|---|---|
| Kritis | `/operasional/data` hanya membutuhkan `dashboard.view`, tetapi snapshot memuat reservasi, telepon, transaksi, payroll, dan activity log sekaligus | Pengguna dapat menerima data modul yang tidak boleh dilihat meskipun elemen UI disembunyikan | Filter payload per permission atau pecah endpoint per modul; tambahkan test akses langsung |
| Tinggi | Nomor transaction memakai `count()+1` dan `transactions.reservation_id` tidak unik | Dua request bersamaan dapat membuat nomor bentrok atau invoice ganda | Unique constraint, row lock, dan nomor yang aman terhadap concurrency |
| Tinggi | Tidak ada idempotency key pada checkout | Double-click/retry dapat mencoba memproses pembayaran dan stok lebih dari sekali | Simpan idempotency key unik dan kembalikan hasil request pertama |
| Tinggi | Nilai uang dihitung menggunakan cast `float` untuk diskon | Pembulatan tidak deterministik untuk nilai finansial | Integer Rupiah dan aturan pembulatan eksplisit di backend |
| Tinggi | Produk resep belum dikunci secara konsisten selama checkout | Checkout bersamaan dapat membaca stok lama yang sama | Lock baris produk, hitung saldo di backend, dan rollback seluruh unit kerja |
| Tinggi | Status reservasi dapat diganti langsung tanpa aturan transisi | Alur dapat melompat atau status final dibuka tanpa kontrol | State machine, authorization, alasan untuk aksi sensitif, dan audit trail |
| Sedang | Sejumlah nilai server dirender ke `innerHTML` tanpa escaping seragam | Data yang dimasukkan pengguna dapat menjadi stored XSS | Gunakan escaping/DOM text nodes dan test payload berbahaya |
| Sedang | Timezone aplikasi masih UTC | Tanggal antrean, invoice harian, dan laporan dapat bergeser dari hari bisnis | Jadikan `Asia/Jakarta` default dan test batas tengah malam |
| Sedang | Master metode pembayaran berupa string yang dikirim client | Label bisa tidak konsisten dan sulit direkonsiliasi | Foreign key ke master aktif; backend menentukan nama/kategori |
| Sedang | Activity log belum mencatat seluruh before/after, alasan, dan request identifier | Investigasi perubahan sensitif terbatas | Metadata terstruktur dan log dalam transaction yang sama |

“Keamanan transaksi” dalam project ini berarti otorisasi, perhitungan server-side, konsistensi database, pencegahan duplikasi, auditability, dan rekonsiliasi label pembayaran. Istilah ini tidak berarti integrasi payment gateway.

## Temuan dari workbook

Workbook dipakai untuk memahami proses, bukan sebagai sumber angka yang langsung diimpor.

- Jadwal berisi banyak sheet harian dengan format yang berubah sepanjang periode.
- Satu pelanggan dapat memiliki beberapa treatment dan therapist berbeda pada setiap treatment.
- Satu treatment dapat dibagi antara beberapa pegawai; workbook juga menunjukkan overlap jadwal yang perlu warning, bukan hard-block mutlak.
- Waktu dan label seperti `READY`, `LANJUT`, `PULANG`, dan `LEMBUR` tercampur dalam sel; sistem harus menyimpannya sebagai timestamp/status terstruktur.
- Metode pembayaran ditulis bebas dan dapat berupa split payment.
- Contoh komisi memperlihatkan variasi persentase dan pembagian; aturan final belum boleh diasumsikan.
- Workbook remunerasi memiliki external link dan perbedaan antarrekap/slip, sehingga backend harus menghitung ulang dari ledger yang disetujui.
- Sheet stok lebih menyerupai kalkulator kapasitas daripada ledger dan mencampur beberapa unit.

Import historis tidak termasuk Phase 1. Ketika dibuat nanti, data wajib masuk staging/dry-run, dinormalisasi, dan ditinjau sebelum commit.

## Strategi kompatibilitas data demo

Migration operasional lama masih pending di database lokal, tetapi pernah dijalankan pada environment lain. Pemilik project telah memastikan seluruh data tersebut hanya demo dan tidak wajib dipertahankan. Karena itu strategi yang disetujui adalah:

1. desain ulang migration operasional awal agar schema baru bersih dan konsisten;
2. jangan mencoba menjalankan migration bernama sama di atas environment yang sudah mencatatnya sebagai `Ran`, karena perubahan file tidak akan dieksekusi ulang;
3. reset hanya database demo dengan `php artisan migrate:fresh --seed`, setelah perubahan dan test direview serta dengan persetujuan pemilik environment;
4. jangan menjalankan reset pada production atau database yang ternyata mengandung data penting;
5. jika sebelum rollout ditemukan data yang perlu dipertahankan, hentikan reset dan ganti strategi menjadi migration additive serta backfill idempotent.

Risiko utama bukan kehilangan data bisnis, melainkan environment demo yang berbeda schema bila tidak di-reset serempak.

## Kesimpulan audit

Fondasi project cukup untuk dilanjutkan tanpa mengganti framework, autentikasi, permission system, atau desain UI. Perubahan awal harus berpusat pada aggregate reservasi/kegiatan, master pegawai, snapshot finansial, conflict override yang diaudit, pemisahan detail pembayaran, serta hardening backend. Komisi, lembur, payroll lengkap, cash closing, laporan, dan import Excel tetap phase terpisah sampai aturan bisnisnya dikunci.
