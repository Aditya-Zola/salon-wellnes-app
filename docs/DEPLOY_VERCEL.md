# Deploy demo ke Vercel

Project ini memakai runtime komunitas `vercel-php` karena PHP bukan runtime
bawaan Vercel. Database lokal tidak dapat digunakan oleh deployment; gunakan
database MySQL-compatible yang dapat diakses dari internet, seperti TiDB Cloud.

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

DB_CONNECTION=mysql
DB_HOST=...
DB_PORT=4000
DB_DATABASE=salon_wellness
DB_USERNAME=...
DB_PASSWORD=...
MYSQL_ATTR_SSL_CA=/etc/pki/tls/certs/ca-bundle.crt

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local
APP_MAINTENANCE_DRIVER=cache
```

Gunakan koneksi yang ditampilkan oleh tombol **Connect** di TiDB Cloud. Nama
pengguna TiDB Starter biasanya memiliki prefix khusus; salin nilainya secara
utuh. Path CA di atas dipakai oleh runtime Linux Vercel. Saat menjalankan
migration dari Ubuntu lokal, gunakan `/etc/ssl/certs/ca-certificates.crt`.
Setelah URL Vercel pertama tersedia, perbarui `APP_URL` lalu redeploy.

## Menyiapkan database

Salin `.env.example` menjadi `.env.tidb`, lalu isi kredensial cloud pada file
tersebut. File ini diabaikan Git. Jalankan migration menggunakan environment
khusus itu agar konfigurasi database lokal pada `.env` tidak berubah:

```bash
php artisan migrate --force --env=tidb
php artisan db:seed --force --env=tidb
```

Pastikan target benar-benar database demo sebelum menjalankan seeder. Jangan
menjalankan migration melalui route web dan jangan menyimpan password database
di source control.

## Alur Git

- Push branch selain `main` untuk memperoleh Preview Deployment.
- Uji login, transaksi, retur, saldo, stok, nota, dan struk retur.
- Merge pull request ke `main` untuk membuat Production Deployment.
