# Rencana Pengembangan Sistem Operasional Salon

## Tujuan

Rencana ini mengubah prototype satu reservasi–satu treatment menjadi fondasi operasional yang dapat berkembang menuju pengganti pencatatan Excel. Pengerjaan dilakukan bertahap agar setiap perubahan schema, API, UI, dan aturan finansial dapat diuji secara terpisah.

Dokumen ini menjadi baseline rencana dan acceptance gate. Fondasi Phase 1 telah diimplementasikan pada branch `feat/salon-operational-system`; item phase lanjutan tetap belum dianggap selesai sampai kode, migration, authorization, dan automated test terkait tersedia dan lulus.

## Keputusan desain yang sudah final

| Topik | Keputusan |
|---|---|
| Data lama | Semua data operasional masih demo dan boleh di-reset |
| Migration awal | Boleh dirancang ulang; environment yang pernah menjalankannya harus `migrate:fresh --seed` setelah review dan persetujuan |
| Invoice | Tepat satu invoice untuk satu pelanggan dan satu kunjungan |
| Pembayaran | Dapat dipecah ke beberapa metode; seluruh metode adalah label manual, tanpa payment gateway |
| Pelunasan | Total semua bagian pembayaran harus sama persis dengan grand total; piutang/partial settlement belum termasuk scope |
| Konflik pegawai | Warning; override hanya dengan permission khusus dan alasan wajib |
| Waktu bisnis | `Asia/Jakarta` |
| Excel | Referensi proses dan sumber import staging kelak, bukan sumber perhitungan langsung |
| UI | Mempertahankan layout dan gaya aplikasi sekarang |

Keputusan yang sengaja ditunda sampai phase terkait adalah basis komisi, pembagian komisi, tarif lembur, aturan attendance/keterlambatan, pembulatan payroll, refund, dan import historis.

## Prinsip implementasi

1. Backend menjadi sumber kebenaran untuk harga master, diskon, total invoice, jumlah pembayaran, saldo stok, komisi, dan payroll.
2. Nilai Rupiah disimpan sebagai integer; quantity/faktor konversi menggunakan decimal. `float` tidak digunakan untuk kalkulasi finansial.
3. Data historis menyimpan snapshot nama, harga, rate, dan nominal yang relevan.
4. Checkout dan proses finansial lain berjalan atomik di dalam database transaction dengan row locking.
5. Proses yang dapat diulang oleh browser mempunyai idempotency key atau unique business constraint.
6. Perubahan sensitif tidak menghapus history; phase lanjut menggunakan cancellation/reversal.
7. Permission diperiksa di route/backend. Kondisi UI hanya membantu pengalaman pengguna.
8. Tanggal dan waktu operasional dihitung dalam `Asia/Jakarta` dan diuji di batas pergantian hari.
9. Query list harus disiapkan untuk pagination/eager loading ketika data tidak lagi demo.
10. Setiap phase harus mempunyai test positif, test validasi, test unauthorized, serta test rollback untuk unit kerja kritis.

## Scope implementasi awal yang disetujui

### 1. Fondasi master data

- Pisahkan akun login `users` dari profil operasional `employees`; `employees.user_id` bersifat opsional.
- Satukan therapist ke dalam master employee agar satu identitas dapat dipakai pada jadwal, komisi, lembur, attendance, dan payroll.
- Normalisasi kategori treatment.
- Siapkan master unit serta unit beli/pakai produk dan faktor konversinya.
- Siapkan master metode pembayaran yang aktif dan terurut.
- Pertahankan customer/membership serta promo yang masih relevan.

Deliverable minimum:

- foreign key, unique constraint, dan index sesuai query;
- seeder demo yang idempotent pada database kosong;
- status aktif pada master agar history tidak rusak ketika master dinonaktifkan;
- permission pegawai `employees.view/create/update`;
- permission sensitif `reservations.override_conflict` dan `reservations.override_price`.

### 2. Kunjungan multi-treatment

Gunakan aggregate berikut:

```text
reservations
  └─ reservation_items
       └─ reservation_item_staff
```

`reservations` adalah header satu kunjungan/customer. `reservation_items` menyimpan treatment dan snapshot finansialnya. `reservation_item_staff` menyimpan satu atau beberapa pegawai beserta peran dan alokasi komisinya.

Input awal yang ditargetkan:

```json
{
  "name": "Pelanggan",
  "phone": "0812...",
  "date": "2026-08-06",
  "source": "walk_in",
  "notes": "Catatan kunjungan",
  "items": [
    {
      "treatment_id": 1,
      "start_time": "10:00",
      "notes": "Catatan treatment",
      "staff": [
        { "employee_id": 1, "role": "primary" },
        { "employee_id": 2, "role": "assistant" }
      ]
    }
  ]
}
```

Harga normal dan default komisi dibaca ulang dari master oleh backend. `actual_price`, bila dipakai, harus berupa integer Rupiah dan hanya dapat dikirim pengguna dengan `reservations.override_price`; harga normal tetap disimpan sebagai snapshot terpisah untuk audit.

Status header yang digunakan pada scope awal:

```text
scheduled → arrived → in_service → completed
                   ↘ cancelled
```

Label Indonesia tetap ditampilkan di UI. Status disimpan dengan kode kanonis agar query dan integrasi stabil. Status `no_show` dan alur khusus lain dapat ditambah pada iteration terpisah setelah perilakunya disepakati.

### 3. Pekerjaan per treatment dan timestamp

Status kerja item pada scope awal:

```text
waiting
in_progress
continue
ready
finished
overtime
cancelled
```

Endpoint perubahan status wajib menggunakan state transition yang eksplisit. Timestamp aktual ditetapkan server, bukan dipercaya dari browser:

- memulai pekerjaan mengisi `started_at` bila belum ada;
- menandai ready mengisi `ready_at`;
- menyelesaikan pekerjaan mengisi `finished_at`;
- penandaan lembur disimpan sebagai status/timestamp operasional awal, tetapi nominal lembur belum dihitung sampai phase overtime ledger;
- pembatalan menyimpan actor/waktu audit dan tidak boleh diam-diam menghapus item.

Perubahan status final atau pembukaan ulang harus mempunyai aturan permission dan alasan sebelum dipakai pada data nyata.

### 4. Conflict warning dan override

Benturan dihitung dari rentang jadwal item dan seluruh pegawai yang ditugaskan. Perilaku API yang ditargetkan:

1. tanpa konflik, reservasi dibuat normal;
2. dengan konflik dan tanpa override, API tidak menyimpan data dan mengembalikan HTTP 409 beserta daftar konflik terstruktur;
3. browser menampilkan nama pegawai, kunjungan/item yang bertabrakan, serta rentang waktunya;
4. pengguna dapat mengirim ulang dengan `override_conflict=true` hanya jika memiliki `reservations.override_conflict`;
5. `override_reason` wajib, tidak hanya whitespace, dan dibatasi panjangnya;
6. alasan, actor, dan waktu override disimpan pada penugasan yang bertabrakan dan activity log;
7. pengguna tanpa permission menerima 403 meskipun memodifikasi request melalui DevTools.

Availability di UI tidak lagi men-disable pegawai secara mutlak. Ia menjadi indikator warning karena bisnis mengizinkan overlap yang dapat dipertanggungjawabkan.

### 5. Fondasi invoice dan split payment manual

Schema awal memisahkan:

```text
transactions
transaction_items
transaction_payments
payment_methods
```

Kontrak checkout awal menargetkan satu `reservation_id`, diskon yang diizinkan, daftar pembayaran, dan `idempotency_key`. Contoh:

```json
{
  "reservation_id": 100,
  "discount_percent": 10,
  "idempotency_key": "uuid-dari-client",
  "payments": [
    {
      "payment_method_id": 1,
      "amount": 200000,
      "reference_number": null
    },
    {
      "payment_method_id": 2,
      "amount": 300000,
      "reference_number": "QRIS-REF-123"
    }
  ]
}
```

Backend wajib:

1. mengunci reservation dan data stok terkait;
2. menolak kunjungan cancelled, belum siap, atau sudah mempunyai invoice;
3. membangun transaction items dari reservation items, bukan dari total kiriman frontend;
4. menghitung subtotal, diskon, dan grand total dalam integer Rupiah;
5. memvalidasi payment method aktif;
6. memastikan jumlah semua payment sama persis dengan grand total;
7. membuat invoice, item, bagian pembayaran, cash entry, movement stok, status kunjungan, dan activity log dalam satu database transaction;
8. memastikan retry dengan idempotency key yang sama tidak menggandakan invoice atau stok;
9. memakai nomor invoice yang aman terhadap concurrency;
10. menyimpan nomor referensi sebagai catatan manual, bukan bukti otomatis dari gateway.

Refund, piutang, chargeback, dan rekonsiliasi bank otomatis tidak termasuk scope awal.

### 6. Hardening data dan tampilan

- Ubah timezone aplikasi menjadi `Asia/Jakarta`.
- Jangan kirim seluruh snapshot operasional hanya karena pengguna mempunyai `dashboard.view`.
- Filter section dan field sensitif berdasarkan permission; payroll, activity, finance, dan nomor telepon pelanggan tidak boleh bocor lewat JSON tersembunyi.
- Escape semua data pengguna sebelum dirender ke HTML.
- Gunakan validation terstruktur untuk nested items/staff/payments.
- Tulis activity log di dalam unit kerja yang sama dengan perubahan sensitif.
- Pertahankan CSRF dan permission middleware yang sudah tersedia.
- Pecah tanggung jawab checkout/conflict calculation dari controller bila perubahan mulai membesar.

## Acceptance criteria implementasi awal

Implementasi awal baru dianggap selesai bila seluruh kondisi berikut diuji:

- satu kunjungan dapat dibuat dengan satu treatment;
- satu kunjungan dapat dibuat dengan beberapa treatment;
- treatment berbeda dapat mempunyai pegawai berbeda;
- satu treatment dapat mempunyai lebih dari satu pegawai tanpa duplicate assignment;
- nama, harga normal, harga aktual, diskon, dan default komisi tersimpan sebagai snapshot;
- waktu mulai, ready, selesai, lanjut, lembur, dan pembatalan dicatat sesuai state transition;
- konflik menghasilkan 409 dan tidak menulis data parsial;
- override tanpa permission ditolak, sedangkan override berizin mewajibkan alasan dan menghasilkan audit log;
- satu reservation tidak dapat menghasilkan dua transaction;
- pembayaran satu metode dan split payment valid dapat diproses;
- pembayaran kurang/lebih dari grand total ditolak;
- retry idempotent tidak menggandakan transaction, cash entry, visit count, atau pengurangan stok;
- kegagalan stok menggagalkan seluruh checkout;
- kalkulasi uang tidak menggunakan float;
- response `/operasional/data` tidak mengandung modul/field yang tidak diizinkan;
- output data pelanggan aman dari stored XSS;
- query tanggal harian konsisten dalam `Asia/Jakarta`;
- seluruh test lama yang masih relevan diperbarui dan lulus.

## Strategi migration untuk data demo

### Alasan redesign

Migration operasional awal adalah satu file besar dan local database masih menandainya pending. Environment lain pernah menjalankan nama migration yang sama, tetapi hanya berisi data demo. Menambah compatibility layer akan mempertahankan desain yang belum sesuai dan menambah kompleksitas tanpa memberi manfaat perlindungan data nyata.

### Prosedur

1. Revisi migration operasional awal agar urutan create/drop, foreign key, unique constraint, dan index konsisten dengan ERD.
2. Perbarui seeder agar cocok dengan schema baru dan tidak bergantung pada row order yang rapuh.
3. Jalankan test dengan database SQLite in-memory.
4. Review migration dan backup environment demo bila masih diperlukan untuk pembanding.
5. Pada setiap environment demo yang pernah menjalankan migration lama, jalankan reset secara eksplisit:

   ```bash
   php artisan migrate:fresh --seed
   ```

6. Pada database lokal yang belum menjalankan migration operasional, `php artisan migrate` dapat membentuk schema baru setelah review. Menggunakan `migrate:fresh --seed` tetap lebih deterministik untuk menyamakan demo.
7. Jangan menjalankan perintah reset pada production atau pada database yang belum diverifikasi isinya.

Mengedit file migration lama tidak mengubah database yang sudah mencatat migration tersebut sebagai `Ran`; environment seperti itu wajib di-reset atau diberi migration corrective baru.

### Constraint/index minimum

- `employees.user_id` nullable dan unique;
- nomor booking dan nomor invoice unique;
- satu transaction per reservation melalui unique `reservation_id`;
- idempotency key checkout unique;
- unique assignment employee per reservation item;
- unique resep treatment–product;
- unique payment method code;
- index tanggal/status pada reservation;
- index schedule dan status pada reservation item;
- index employee dan rentang kerja yang dipakai conflict query;
- index transaction date/status/customer;
- index payment method/paid time;
- index product dan waktu movement.

## Matriks risiko dan mitigasi

| Risiko | Probabilitas/dampak | Mitigasi dan gate |
|---|---|---|
| Environment demo tidak di-reset setelah migration direvisi | Tinggi/tinggi | Checklist rollout per environment dan verifikasi `migrate:status`/schema |
| Data penting ternyata berada di database yang dianggap demo | Rendah/kritis | Backup dan konfirmasi pemilik sebelum `migrate:fresh`; beralih ke additive migration bila ditemukan |
| Query/UI lama masih mengakses `reservations.treatment_id` atau `therapist_id` | Tinggi/tinggi | `rg`, test feature seluruh modul, dan larangan merge bila masih ada referensi legacy |
| Benturan terlewat karena hanya memeriksa primary staff | Sedang/tinggi | Conflict query terhadap semua baris `reservation_item_staff` dan seluruh item aktif |
| Override menjadi jalan pintas tanpa pengawasan | Sedang/tinggi | Permission granular, alasan wajib, actor/time, activity log, dan laporan override |
| Checkout ganda atau stok ganda | Sedang/kritis | Unique reservation/idempotency, row lock, atomic transaction, dan concurrency-oriented test |
| Perubahan timezone menggeser data lama | Rendah/sedang untuk demo | Reset demo; test boundary `23:xx`/`00:xx`; simpan datetime secara konsisten |
| Formula komisi dianggap final terlalu dini | Tinggi/tinggi | Simpan snapshot/rate field, tetapi tunda ledger calculator sampai aturan disetujui |
| Payload dashboard membocorkan data | Tinggi/tinggi | Resource/serializer per permission dan negative authorization test |
| Spreadsheet mengandung data rusak/variasi nama | Tinggi/tinggi pada import | Staging, dry-run, mapping master, error report, dan approval manual pada phase import |

## Roadmap sesudah implementasi awal

| Phase | Scope | Gate sebelum mulai |
|---|---|---|
| 2 — Komisi dan lembur | Rule versioning, commission ledger, pembagian staff, overtime ledger, approval, reversal | Basis komisi, rate, split, waktu/tarif lembur, dan pembulatan disetujui |
| 3 — Stok lengkap | Unit conversion execution, receipt, receipt items, usage ledger, sale/damage/return/reversal | Daftar unit/faktor konversi dan aturan stok negatif disetujui |
| 4 — Keuangan dan tutup kas | Expense category, expense, reconciliation per payment method, closing/approve/reopen | Definisi opening cash, expense, zakat/delivery, dan approval disetujui |
| 5 — Attendance dan payroll | Attendance, payroll period/components, calculator, approval/finalization, payslip | JHK, mangkir, terlambat, bonus, tips, kasbon, dan pembulatan disetujui |
| 6 — Laporan | Jadwal, transaction, payment, commission, stock, finance, remunerasi, export | Definisi angka sumber dan hak lihat per laporan disetujui |
| 7 — Import Excel | Template, staging, normalisasi, dry-run, review queue, commit idempotent | Mapping master dan keputusan apakah data historis perlu dimuat disetujui |

Nomor phase di atas mengikuti scope coding yang sudah disepakati setelah audit; setiap phase harus menjadi perubahan terpisah dan tidak menganggap schema persiapan sebagai fitur bisnis yang sudah selesai.

## Perkiraan file terdampak pada implementasi berikutnya

Daftar ini adalah impact map, bukan daftar perubahan yang sudah terjadi.

### Fondasi dan appointment

- `config/app.php`;
- `database/migrations/2026_08_03_120000_create_salon_operation_tables.php`;
- `database/seeders/AccessControlSeeder.php`;
- `database/seeders/SalonOperationSeeder.php`;
- model domain baru di `app/Models/`;
- request/service/action baru di `app/Http/Requests/` dan `app/Http/Services/` bila diperlukan;
- `app/Http/Controllers/SalonController.php` atau controller domain yang lebih kecil;
- `routes/web.php`;
- `resources/views/dashboard.blade.php`;
- `public/js/salon.js` dan CSS terkait;
- `tests/Feature/SalonOperationsTest.php` serta test authorization/concurrency baru.

### Phase komisi/overtime berikutnya

- migration baru untuk `commission_entries`, rule/version table bila diperlukan, dan `overtime_entries`;
- `CommissionCalculator`, approval service, dan reversal service;
- route/controller/report permission untuk komisi dan lembur;
- tampilan review/approval;
- feature test kalkulasi, pembagian, approval, reversal, dan idempotency.

Tidak ada package baru yang diperlukan untuk scope awal. Package PDF/Excel baru hanya boleh dipertimbangkan pada phase export setelah package project diperiksa kembali.

## Validasi dan rollout

Urutan validasi minimum setiap perubahan:

```bash
php artisan optimize:clear
php artisan test
npm run build
```

Untuk database demo yang schema lamanya sudah pernah dijalankan, reset hanya setelah persetujuan eksplisit:

```bash
php artisan migrate:fresh --seed
```

Sebelum merge:

- pastikan working tree hanya memuat perubahan yang dimaksud;
- review SQL/index/foreign key migration;
- pastikan tidak ada credential atau perubahan `.env`;
- verifikasi permission menggunakan request langsung, bukan hanya tampilan sidebar;
- simulasikan satu alur booking, konflik, override, status item, pembayaran tunggal, split payment, dan retry;
- catat migration command serta risiko reset demo pada handoff.
