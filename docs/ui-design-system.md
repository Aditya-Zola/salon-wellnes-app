# Tampilan operasional Selesa

UI memakai Laravel Blade, Tailwind CSS v4, dan Vite. Halaman operasional memiliki latar abu muda (`#f3f6fa`), kartu putih, garis tipis (`#e2e8f0`), serta teks gelap (`#14284c`). Biru (`#2563eb`) dipakai untuk navigasi aktif, tautan, dan fokus. Warna tombol mengikuti maksud tindakan:

| Tindakan | Warna | Contoh |
| --- | --- | --- |
| Tambah, simpan, proses, selesai | Hijau `#087f64` | Tambah produk, simpan reservasi, input gaji |
| Lihat atau informasi | Biru lembut | Detail, riwayat, cetak ulang |
| Ubah | Amber `#d97706` | Edit produk, ubah jam, edit gaji |
| Hapus atau batalkan | Merah `#dc3545` | Hapus treatment, batalkan reservasi |
| Aksi sekunder | Netral | Batal form, impor, ekspor |

Token warna ada di `resources/css/salon-theme.css`. Komponen bersama dan aturan responsif ada di `resources/css/salon.css`. CSS modul lama tetap dimuat pada layer `legacy` selama migrasi; komponen bersama ada di layer `components`. Nama komponen lama `.table` dikecualikan dari utility Tailwind karena harus tetap menjadi wadah tabel yang bisa digulir pada layar sempit. Hindari aturan visual baru dengan `!important`.

Perbaikan kegunaan mencakup sidebar dengan menu yang dapat digulir sendiri, dashboard dalam satu card dengan garis pemisah antarbagian, tabel produk dan penggajian dengan kolom aksi yang cukup, tombol antrean dengan warna konsisten, dropdown treatment dan terapis yang muncul di area dialog, serta grafik garis treatment harian yang pas dengan lebar kartu. Tanggal, nominal, dan status selalu berasal dari data aplikasi.

Setelah pembayaran, dialog sukses tampil ringkas. Customer Survey otomatis menjadi draft; kasir dapat memilih **Isi survey sekarang** atau menutup dialog dan mengisinya kemudian dari halaman Customer Survey. Pada ponsel, daftar draft menampilkan status dan tombol **Isi survey** di kartu yang sama tanpa gulir horizontal.

Jalankan `npm run dev` saat mengembangkan UI atau `npm run build` sebelum distribusi. Dashboard, halaman internal, dan login memakai entry CSS Vite masing-masing.

## Validasi

- `npm run build`, `php artisan view:cache`, pemeriksaan sintaks JavaScript, dan `git diff --check` berhasil.
- Seluruh 93 tes aplikasi lulus dengan 871 assertion.
- Pemeriksaan Edge headless sebelumnya memakai data QA terpisah pada lebar 1536, 1280, 768, dan 390 piksel. Perubahan dashboard terakhir, termasuk banner dan grafik garis, belum diperiksa ulang secara visual di browser.
- Penilaian akhir dengan data dan browser produksi tetap diperlukan sebelum rilis.
