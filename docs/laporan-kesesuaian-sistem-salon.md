# Laporan Kesesuaian Sistem Operasional Salon

**Tanggal pemeriksaan:** 22 September 2026  
**Status:** Draft verifikasi operasional  
**Dasar pemeriksaan:** kode aplikasi, alur UI, dan 73 Feature Test dengan 708 assertion.

## Ringkasan

Fondasi sistem sudah berjalan dan seluruh Feature Test saat ini lulus. Modul utama—reservasi, kasir, pembayaran, stok, keuangan, remunerasi, akses pengguna, dan pengaturan metode pembayaran—sudah saling terhubung.

Laporan ini membedakan fitur yang sudah terbukti di sistem dari aturan bisnis yang masih perlu disetujui oleh salon. Persetujuan salon tetap diperlukan untuk memastikan istilah, nominal, dan kebiasaan kerja di lapangan sama dengan implementasi.

## Status per modul

| Modul | Kondisi saat ini | Status |
|---|---|---|
| Login dan hak akses | Login tanpa pilihan role, role/permission membatasi halaman dan aksi backend | Sesuai secara teknis |
| Reservasi | Satu kunjungan dapat memiliki beberapa treatment dan therapist; data customer, jadwal, catatan, dan status tersimpan | Sesuai secara teknis |
| Jadwal therapist | Therapist tetap dapat dipilih walaupun ada jadwal lain; benturan tidak memblokir penyimpanan | Perlu konfirmasi SOP salon |
| Antrean | Ada status reservasi, status treatment, informasi DP/sisa, metode pembayaran, dan tombol selesai | Sesuai rancangan terakhir |
| Penyelesaian treatment | Operator dapat menandai selesai; sistem memberi penyelesaian otomatis setelah estimasi + toleransi 15 menit | Perlu uji lapangan |
| DP reservasi | Pilihan DP menampilkan nominal awal yang dapat diubah; sisa pembayaran dihitung otomatis | Sesuai rancangan terakhir |
| Kasir | Mendukung cash, kartu/EDC, transfer, QRIS, split payment, produk tambahan, dan invoice | Sesuai secara teknis |
| Charge pembayaran | Pilihan 0%, 2%, dan 3,5%; charge dihitung pada akhir berdasarkan nilai tagihan, bukan DP | Perlu konfirmasi kebijakan salon |
| Referensi pembayaran | Nomor referensi dibuat opsional | Sesuai keputusan terakhir |
| Refund/retur | Operator memulai refund secara manual; setelah dikonfirmasi, arus pembayaran dan stok diperbarui sesuai pilihan restock | Sesuai rancangan terakhir |
| Metode pembayaran | QRIS, EDC, dan bank dapat dikelola; metode yang sudah dipakai diarsipkan, bukan menghapus histori transaksi | Sesuai secara teknis |
| Produk dan stok | Produk, satuan, minimum stok, resep, stok masuk/keluar, opname, import Excel, dan histori tersedia | Sesuai secara teknis |
| Keuangan | Arus kas, laba-rugi, neraca, refund, HPP, dan biaya manual tersedia dengan filter tanggal otomatis | Sesuai secara teknis |
| Penggajian/remunerasi | Input gaji manual, komisi dari transaksi lunas, attendance/lembur, rekap, export, dan arsip final tersedia | Perlu konfirmasi formula salon |
| Notifikasi | Toast aksi juga masuk ke panel bell selama sesi berjalan | Perlu keputusan apakah harus tersimpan lintas login |

## Alur kerja yang saat ini dipakai

1. Operator membuat reservasi dan memilih treatment, therapist, serta pembayaran awal bila ada DP.
2. Reservasi masuk ke antrean dan kalender. Jadwal dapat bertumpuk; status menjadi informasi operasional, bukan pengunci jadwal.
3. Treatment dapat diselesaikan melalui tombol **Selesai** atau ditutup otomatis setelah estimasi selesai dan toleransi 15 menit terlewati.
4. Kasir membuka invoice, menerima pelunasan atau pembayaran split, memilih metode, dan memilih charge bila diperlukan.
5. Setelah pembayaran berhasil, stok resep berkurang dan transaksi masuk ke laporan keuangan serta sumber komisi.
6. Refund tidak berjalan otomatis. Operator memilih transaksi, alasan, metode pengembalian, dan apakah barang dikembalikan ke stok.
7. Penggajian diinput manual per periode. Remunerasi membaca komisi dari transaksi lunas, lalu hasilnya dapat difinalisasi dan disimpan sebagai arsip.

## Hal yang perlu disetujui pihak salon

- Apakah toleransi otomatis selesai 15 menit sudah tepat untuk semua jenis treatment?
- Apakah jadwal bertumpuk selalu boleh, atau hanya therapist tertentu yang boleh menerima overlap?
- Apakah charge 2% dan 3,5% berlaku untuk semua kartu/EDC dan transfer, atau hanya metode tertentu?
- Apakah DP boleh kurang/lebih dari 50% tanpa batas minimum?
- Siapa yang berhak melakukan refund, dan apakah refund harus melalui persetujuan owner?
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

**Hasil automated test terakhir:** 73 lulus, 708 assertion, tanpa kegagalan.
