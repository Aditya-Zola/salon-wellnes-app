# Tampilan operasional Selesa

Seluruh halaman web memakai Laravel Blade, Tailwind CSS v4, daisyUI v5, dan Vite. Satu tema daisyUI `selesa` dipakai untuk login, navigasi, halaman operasional, hak akses, dan pengaturan. Warna dikonfigurasi di `salon-theme.css`, tanpa stylesheet desain terpisah per halaman.

## Komponen

| Kebutuhan | Komponen |
| --- | --- |
| Tambah, simpan, proses | `btn btn-primary` |
| Edit dan ubah jadwal | `btn btn-accent` |
| Aksi sekunder, kembali, cetak, ekspor | `btn btn-secondary` |
| Detail dan aksi ringan | `btn btn-info btn-sm` |
| Selesaikan | `btn btn-success` |
| Hapus, batalkan, tutup dialog | `btn btn-error btn-sm` |
| Isian | `input`, `select`, `textarea` |
| Pilihan | `checkbox`, `radio` |
| Kartu | `card bg-base-100 border border-base-300 p-5` |
| Tabel HTML | `table table-zebra` di dalam wadah `overflow-x-auto`, header `bg-primary/10 text-primary` |
| Dialog | `modal`, `modal-box` |
| Status | `badge` dengan varian warna sesuai kondisi |

Gunakan komponen daisyUI dan utility Tailwind. Tombol aksi memakai varian warna solid dengan teks putih; teks pendamping di dalam tombol mengikuti warna teks tombol. Pertahankan satu ukuran serta varian untuk fungsi yang sama. Pilihan aktif pada tab dan metode pembayaran tetap berwarna dengan teks putih. Tombol nonaktif mengikuti keadaan disabled daisyUI. Label singkat; petunjuk hanya untuk aturan yang dibutuhkan pengguna. Jangan menambahkan stylesheet per halaman atau menimpa desain bawaan komponen.

Letak komponen mengikuti template sebelum migrasi daisyUI. Treatment dan bundle berada dalam satu kartu; filter Survey dan Log Aktivitas tetap di dalam panel; pencarian member berada di header daftar; aksi halaman hak akses berada di header halaman. daisyUI merapikan komponen tanpa memindahkan kelompok kontrol menjadi panel baru.

Gunakan `<x-page-toolbar>` dengan isi biasa untuk judul, slot `filters` untuk deret filter, dan `actions` untuk pencarian atau aksi di kanan. Pakai `:panel="false"` ketika toolbar sudah berada di dalam kartu. Kontrol menumpuk pada layar kecil. Angka nominal pada tabel rata kanan dengan `tabular-nums`; tombol aksi juga rata kanan. Tabel dinamis memakai helper `dataTable()` agar header dan isi memakai kolom HTML yang sama.

Biru untuk navigasi dan aksi utama; teal untuk membership dan informasi pendamping; hijau untuk keberhasilan atau pemasukan; merah untuk pengeluaran, pembatalan, dan stok habis; kuning untuk peringatan; ungu untuk layanan yang sedang berlangsung. Kartu ringkasan memakai aksen tepi dan latar lembut. Status tetap memiliki label, sehingga maknanya tidak bergantung pada warna.

## Struktur

- `resources/css/salon.css`: Tailwind, plugin daisyUI, geometri kalender/grafik/tabel, responsivitas, dan penghubung status JavaScript lama (`open`, `active`, `hidden`, `inert`).
- `resources/css/login.css`: Tailwind dan daisyUI untuk login.
- `resources/css/salon-theme.css`: konfigurasi tema daisyUI, font bersama, dan alias warna kalender/grafik.
- `resources/views/components/page-toolbar.blade.php`: susunan filter dan aksi bersama.
- `resources/views/`: markup Blade menggunakan komponen standar dan utility Tailwind.
- `public/js/salon.js`: markup dinamis memakai komponen yang sama; perhitungan dan alur permintaan data dipertahankan.

Stylesheet desain lama di `public/css` tidak lagi diimpor oleh entry tampilan. Class lama yang masih ada di HTML menjadi penanda perilaku JavaScript dan hak akses. Format cetak PDF/thermal tetap menggunakan stylesheet cetaknya sendiri.

Nota A4 pembayaran biasa, DP, dan pelunasan memakai header dua kolom: logo, kontak salon, kasir, dan terapis di kiri; nomor nota, nama pelanggan, tanggal bayar, dan metode di kanan. Tabel bergaris memuat No, Rincian, Jenis, Qty, Harga, dan Total; ringkasan nominal menyambung di bawah tabel. Tidak ada alamat pelanggan, footer ucapan, atau blok tanda tangan tambahan. Kolom dan total mengikuti data salon. Header tabel berulang jika item melampaui satu halaman; struk thermal tetap memakai format ringkasnya.

Kasir mempertahankan dua panel pada desktop dan menumpuknya pada HP. Panel pembayaran tetap terlihat dan `inert` sebelum transaksi dipilih. Tabel lebar serta kalender digulir di dalam wadahnya. Sidebar HP dibuka melalui tombol menu.

Memilih **Pembayaran DP** saat reservasi hanya menyimpan rencana nominal pada `reservation_deposit_plans`; belum ada uang diterima atau jam pembayaran. Di kasir tersedia kartu **Pembayaran DP** (menunggu) dan **Pembayaran LUNAS** (tunggu DP). Kasir memilih metode lalu menekan **Proses pembayaran DP** untuk mencatat pembayaran pada `reservation_deposits`, beserta petugas dan waktu konfirmasi kasir. Setelah itu kartu DP menampilkan nominal, tanggal, metode, dan referensi sebagai rincian baca saja; kartu LUNAS menagih sisanya. Pelunasan ditahan sampai DP dikonfirmasi. Pengulangan konfirmasi mempertahankan catatan serta waktu pembayaran pertama. Catatan DP lama yang sudah dikonfirmasi tetap dipertahankan. Pelunasan memakai `transaction_payments`.

Part 2 menyediakan **Cetak struk / nota DP** pada rincian DP yang sudah dibayar, serta tab **Riwayat DP** di Penjualan. Kedua jalur membuka pilihan **Cetak struk** (thermal) dan **Cetak nota** (PDF A4). Data struk berasal dari snapshot pembayaran yang sama dengan nota, termasuk total, DP dibayar, sisa, dan tanggal pembayaran asli. Riwayat dapat dicari berdasarkan nomor DP, reservasi, pelanggan, metode, dan rentang tanggal pembayaran; tidak dibatasi tanggal reservasi atau status pelunasan. Tombol **Cetak DP sebelumnya** juga tersedia pada dialog cetak pelunasan dan membuka pilihan format yang sama.

Nomor nota DP mengikuti pola invoice dengan awalan `DP`: `DPYYYYMMDDNNN`, misalnya `DP20261107021`. Tanggal berasal dari pembayaran, sedangkan bagian akhir memakai ID DP minimal tiga digit agar nomor tetap unik dan stabil saat cetak ulang. Format ini sama pada nota PDF, struk, riwayat, dan referensi DP di nota pelunasan. Nota memuat tanggal konfirmasi kasir, total pesanan saat DP diterima, DP dibayar, dan sisa pembayaran. Rincian pesanan, pelanggan, kasir, serta nama metode disimpan pada `reservation_deposits.receipt_snapshot` ketika pembayaran dikonfirmasi. Cetak ulang tidak mengubah catatan atau menyesuaikan rincian dengan perubahan pesanan berikutnya. DP lama tanpa snapshot tetap memakai tanggal serta nominal pembayaran asli; rincian pesanan menggunakan data reservasi yang tersedia dan diberi keterangan pada nota. Tidak ada pengisian ulang otomatis terhadap riwayat lama.

Nota pelunasan menampilkan total akhir termasuk diskon/charge, DP sebelumnya beserta nomor dan tanggalnya, serta nominal yang dibayar saat pelunasan. Struk ringkas juga memisahkan DP dan pelunasan. Nota transaksi tanpa DP tetap menggunakan judul pembayaran biasa. PDF dibuat dari catatan pembayaran, bukan jam cetak atau tanggal reservasi.

Part 3 menyediakan pilihan struk thermal dan nota A4 dari hasil retur serta **Penjualan → Riwayat retur → Cetak retur**. Nomor retur dibuat otomatis saat retur disimpan. Kedua format membaca catatan retur yang sama: invoice asal, pelanggan, petugas, tanggal retur asli, metode/referensi refund, alasan, item, dan total refund. Nota A4 mengikuti susunan nota salon tanpa footer tambahan atau alamat pelanggan; gaya cetak A4 dibagi melalui `pdf/partials/note-style.blade.php`.

Nomor retur ditampilkan tanpa pemisah, misalnya `RTN20260822001`, pada riwayat, hasil proses, nota, dan struk. Retur baru menyimpan format tersebut; nomor historis yang mengandung tanda hubung hanya dinormalisasi saat ditampilkan. Pencarian menerima kedua bentuk. Kolom pelanggan menampilkan tautan **Invoice asal** untuk membuka nota transaksi terkait; dialog cetak juga menyebut nomor invoice asal agar pencocokan mudah.

Retur produk menampilkan kuantitas, harga, dan status kembali ke stok. Refund treatment menampilkan nominal aktual serta penanda sebagian/penuh; kolom kuantitas dan harga satuan diberi tanda `-` karena bukan pengembalian produk per unit. Nama dan nominal item berasal dari catatan retur, sehingga perubahan harga produk berikutnya tidak mengubah bukti cetak. Formulir mempertahankan kunci permintaan yang sama ketika dicoba ulang setelah respons terputus; satu retur tidak menambah refund atau stok dua kali. Cetak ulang tidak mengubah tanggal, refund, ataupun stok.

Semua struk pembayaran, DP, dan retur memakai ukuran kertas thermal, dengan default 58 mm dan opsi 80 mm melalui `config/printing.php`. Lebar isi mengikuti kertas dikurangi margin 1 mm di tiap sisi. Tinggi kertas mengikuti isi: struk browser mengukur isi setelah logo termuat sebelum mencetak, sedangkan PDF retur mengukur hasil layout sebelum menetapkan tinggi akhir. Nota tetap A4. Verifikasi memeriksa ukuran halaman PDF yang sebenarnya, bukan hanya lebar isi di halaman A4.

Struk retur mengikuti struk pembayaran/DP: font sans-serif 9 px, logo 36 mm, serta susunan metadata, item, dan nominal yang sama. Format khusus retur mempertahankan invoice asal, tanggal retur, alasan, dan status restock. Nilai konfigurasi selain 58/80 mm memakai fallback 58 mm pada jalur browser maupun PDF.

Dialog transaksi berhasil memakai ikon berukuran tetap dan susunan terpusat. Tombol cetak serta survey membungkus pada HP; membuka survey memperlebar dialog menjadi dua kolom pada desktop. Tombol tutup tetap berada di sudut kanan atas.

Nama terapis pada nota dan struk DP berasal dari penugasan treatment reservasi. Nama disimpan bersama snapshot DP baru agar cetak ulang tetap sama setelah perubahan data terapis. Snapshot DP sebelumnya yang belum memiliki daftar terapis membaca penugasan reservasi tanpa mengubah catatan pembayaran; nama panjang membungkus di struk.

Isian kasir dan reservasi memakai font 13 px, jarak label 4 px, serta isian setinggi 36 px. Metode tunggal tidak mengulang selector metode pada baris pembayaran; isian pembayaran sejajar pada desktop dan menumpuk di HP. Total transaksi, DP diterima, serta sisa pelunasan ditampilkan terpisah.

Sidebar memakai satu daftar tanpa judul kelompok. Urutannya berdasarkan perkiraan frekuensi kerja salon: Dashboard, Reservasi, Kasir, Penjualan, Membership, Kehadiran Terapis, Produk & Stok, Treatment, Customer Survey, Keuangan, Remunerasi, Log Aktivitas, Hak Akses, dan Pengaturan. Urutan ini tetap, tidak berubah otomatis mengikuti klik. Ikon menu memakai Material Symbols Rounded. Submenu aktif terbuka ketika memilih halaman; akun dan tombol keluar berada di bawah dan tetap tersedia saat sidebar diperkecil. Nama menu menjadi tooltip dan label aksesibilitas ikon.

Palet sidebar: latar `#102B50`, header `#173B69`, aktif `#2563EB`, hover `#1D4678`, teks utama `#FFFFFF`, dan teks sekunder `#D6E7FF`. Latar konten `#F4F7FC`. Token `sidebar-*` dan `canvas` berada di `salon-theme.css`; warna sidebar dibatasi pada navigasi. Logo putih ditampilkan melalui filter CSS tanpa mengubah aset aslinya.

## Verifikasi lokal

Pemeriksaan browser memakai Edge headless dan SQLite QA terpisah dari database salon. Halaman diaktifkan melalui navigasi aplikasi; pemeriksaan mencakup 1536, 1280, 768, 390, dan 320 px, dialog, pemilih treatment/therapist, menu produk, DP pada reservasi, metode transfer/split, hak akses, dan pengaturan.

- Seluruh 99 tes aplikasi lulus pada pemeriksaan terbaru (1054 assertion). Tes mencakup rencana DP tanpa penerimaan uang, konfirmasi kasir, nota DP/pelunasan pada tanggal berbeda, perhitungan charge, rincian DP yang tetap setelah perubahan harga, cetak ulang tanpa mengubah pembayaran, filter riwayat, akses pengguna, dan nota DP lama.
- Audit warna tombol mencakup 62 tampilan/kondisi pada desktop dan HP: 20 halaman operasional, halaman hak akses dan pengaturan, form edit, login, serta dialog reservasi, membership, ubah jam, cetak, dan retur. Pemeriksaan computed style memastikan tombol aktif memiliki latar berwarna dan teks putih; screenshot diperiksa untuk antrean, kasir, cetak, form, dan hak akses. Tidak ditemukan tombol aktif yang polos, teks terpotong, overflow halaman, atau error JavaScript pada skenario tersebut.
- Verifikasi retur mencakup produk, treatment, campuran, restock/tanpa restock, nota A4 dan struk, metode refund yang diarsipkan setelah pembayaran, tanggal retur berbeda dari transaksi/cetak ulang, akses pengguna, dan penolakan bukti retur yang belum diposting. Uji browser mensimulasikan respons terputus setelah retur tersimpan: percobaan kedua memakai catatan yang sama, hanya satu refund dan satu perubahan stok. Pilihan cetak hasil/riwayat serta pencarian nomor retur diperiksa pada desktop, 390, dan 320 px; PDF kedua format juga dirender dan diperiksa isi nominalnya.
- Browser memeriksa cetak DP dari kasir dan riwayat, riwayat hari sebelumnya, akses nota DP dari dialog cetak pelunasan, ringkasan struk, serta tabel riwayat pada 390 dan 320 px. PDF DP sebelum/sesudah pelunasan diperiksa isi teksnya; total, DP, dan sisa cocok dan isi nota DP tetap sama.
- Pemeriksaan browser alur DP memastikan tidak ada jam pembayaran sebelum konfirmasi, waktu bayar mengikuti tindakan kasir, dan pelunasan terbuka setelah DP diterima. Dialog sukses diperiksa pada 1536, 390, dan 320 px, termasuk buka/tutup survey serta tombol tutup, tanpa error JavaScript.
- Build Vite dan pemeriksaan sintaks JavaScript lulus.
- Pemeriksaan browser tidak menemukan error JavaScript pada skenario yang diperiksa.
- Pemeriksaan 20 halaman operasional pada lima lebar layar, enam halaman daftar/pengaturan, serta formulir edit hak akses dan karyawan. Tabel HTML diperiksa kesesuaian jumlah sel dengan header.
- Review screenshot penuh mencakup bagian bawah dashboard, daftar member aktif/expired, tujuh terapis, nama panjang, arus kas terisi, rekap gaji, dan survey yang berhasil disimpan. Pemeriksaan lebar layar tidak menggantikan review visual.
- Sidebar memakai struktur `menu` daisyUI (`ul`, `li`, `details`); submenu tidak memakai tombol dengan tinggi tetap.
- Ringkasan membership berisi tiga kolom; rekap remunerasi berisi lima kolom pada desktop. Informasi nama, kontak, masa berlaku, treatment, dan aksi memiliki baris tersendiri.
- Review HP mencakup penumpukan nama rekening dan nominal arus kas, lebar isian stok masuk, filter, kartu member, dan tombol tutup dialog retur. Pemeriksaan otomatis dilakukan bersama review screenshot karena elemen dapat bertumpuk tanpa membuat halaman melebar.

Jalankan `npm run dev` saat mengembangkan UI atau `npm run build` untuk membangun aset. Fungsi nota DP/pelunasan terpisah dan nota retur telah ditangani pada Part 2–3. Pemeriksaan operasional akhir dan penyempurnaan tampilan dilanjutkan bertahap pada Part 4.
