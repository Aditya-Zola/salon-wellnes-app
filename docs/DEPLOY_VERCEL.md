# Deploy demo ke Vercel

Project ini memakai runtime komunitas `vercel-php` karena PHP bukan runtime
bawaan Vercel. Database lokal tidak dapat digunakan oleh deployment; production
menggunakan PostgreSQL Supabase yang dapat diakses dari internet.

## Environment variables

Tambahkan nilai berikut melalui **Vercel > Project > Settings > Environment
Variables** untuk environment Preview dan Production. Jangan commit nilainya ke
repository.

```dotenv
APP_NAME="Selesa Salon"
APP_ENV=production
APP_KEY=base64:...
APP_DEBUG=false
APP_URL=https://alamat-project.vercel.app
APP_TIMEZONE=Asia/Jakarta

LOG_CHANNEL=stderr
LOG_LEVEL=warning

DB_CONNECTION=pgsql
DB_URL=postgresql://...
DB_SSLMODE=require

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local
APP_MAINTENANCE_DRIVER=cache
```

Gunakan connection string yang ditampilkan oleh tombol **Connect** di Supabase
dan salin nilainya secara utuh.
Setelah URL Vercel pertama tersedia, perbarui `APP_URL` lalu redeploy.

## Menyiapkan database

Salin `.env.example` menjadi `.env.production`, lalu isi kredensial cloud pada file
tersebut. File ini diabaikan Git. Jalankan migration menggunakan environment
khusus itu agar konfigurasi database lokal pada `.env` tidak berubah:

```bash
php artisan migrate --force --env=production
php artisan db:seed --force --env=production
```

Pastikan target benar-benar database demo sebelum menjalankan seeder. Jangan
menjalankan migration melalui route web dan jangan menyimpan password database
di source control.

## Alur Git

- Push branch selain `main` untuk memperoleh Preview Deployment.
- Uji login, transaksi, retur, saldo, stok, nota, dan struk retur.
- Merge pull request ke `main` untuk membuat Production Deployment.

## Migrasi otomatis production

Workflow `.github/workflows/laravel.yml` menjalankan `php artisan migrate --force`
setelah seluruh tes lulus, hanya untuk push ke `main` pada repo
`RezaAmru/salon-wellnes-app`. Repo `origin` tidak menjalankan migrasi ini agar
dua repo tidak berebut mengubah database yang sama. Job memakai GitHub Actions
environment `production`, bukan `.env` lokal, dan tidak menjalankan seeder.

Sebelum mengaktifkannya, buat environment `production` pada repo Vercel di
GitHub dan isi environment secrets `APP_KEY` dan `DB_URL` dengan nilai production
yang sama seperti di Vercel. Tambahkan environment variables
`DB_CONNECTION=pgsql` dan `DB_SSLMODE=require`. Batasi environment ini ke branch `main`; pertimbangkan
persetujuan manual untuk migrasi yang berisiko. Jika secret belum tersedia,
job akan gagal sebelum menyentuh database.

GitHub Actions dan deploy otomatis Vercel dipicu secara terpisah oleh push
`main`. Karena itu workflow ini **tidak menjamin** migrasi selesai sebelum
versi aplikasi baru tayang. Migration baru harus kompatibel dengan kode lama
dan baru selama jeda deploy. Untuk urutan ketat, pindahkan pemicu deployment
Vercel ke tahap setelah job migrasi berhasil.
