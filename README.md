# ☕ KopiKita - E-Menu & POS Real-Time System

KopiKita adalah ekosistem aplikasi kasir (Point of Sale) dan menu digital berbasis QR Code untuk bisnis Coffee Shop. Sistem ini mengintegrasikan pemesanan mandiri oleh pelanggan (scan QR) dengan monitor dapur dan dasbor kasir secara instan tanpa perlu memuat ulang halaman (refresh).

---

## 🛠️ Tech Stack & Tools yang Digunakan

Proyek ini dibangun menggunakan kombinasi teknologi modern berstandar enterprise:

### 1. Backend Framework
- **Laravel 13** (PHP 8.4) sebagai core engine dan RESTful API provider.
- **Laravel Reverb** sebagai server WebSocket bawaan untuk meng-handle komunikasi dua arah secara instan.
- **Laravel Echo** sebagai JavaScript client untuk menangkap real-time event.
- **Laravel Horizon** untuk memonitor antrean background jobs (Queue Worker) berbasis Redis.

### 2. Frontend Framework
- **Vue 3 (Composition API)** dengan **Vite** sebagai front-end engine yang super cepat.
- **Inertia.js** sebagai jembatan arsitektur monolithic modern (menyambungkan routing Laravel langsung ke Vue tanpa terpisah repositori).
- **Tailwind CSS** untuk desain antarmuka (UI) dashboard yang modern, clean, dan mobile-first.

### 3. Database & Pendukung
- **MySQL / PostgreSQL** sebagai database relasional utama.
- **Redis** sebagai sistem cache handler dan queue driver.
- **Midtrans Snap API** sebagai gateway pembayaran otomatis untuk memunculkan QRIS Dinamis.

---

## 🚀 Panduan Instalasi & Cara Clone Proyek

Ikuti langkah-langkah di bawah ini secara berurutan untuk menjalankan proyek KopiKita di komputer lokal Anda:

### 1. Clone Repositori
Buka terminal/command prompt, pilih folder direktori Anda, lalu jalankan perintah clone:
```bash
git clone https://github.com
cd KopiKita
```

### 2. Instalasi Dependensi Backend (Laravel)
Instal seluruh package PHP yang dibutuhkan melalui Composer:
```bash
composer install
```

### 3. Instalasi Dependensi Frontend (Vue 3)
Instal seluruh package JavaScript yang dibutuhkan melalui NPM:
```bash
npm install
```

### 4. Konfigurasi Environment File
Salin file template `.env.example` menjadi file `.env` baru:
```bash
cp .env.example .env
```
Buka file `.env` yang baru dibuat menggunakan VS Code/Antigravity, lalu sesuaikan konfigurasi database Anda:
```env
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=kopikita_db
DB_USERNAME=root
DB_PASSWORD=

# Pastikan Reverb Keys sudah terisi otomatis setelah instalasi
```
Generate application key baru untuk Laravel:
```bash
php artisan key:generate
```

### 5. Migrasi Database & Seeders
Buat database baru di phpMyAdmin/DBeaver dengan nama `kopikita_db`, kemudian jalankan perintah untuk membuat struktur tabel dan mengisi data dummy menu kopi:
```bash
php artisan migrate:fresh --seed
```

---

## 🏃‍♂️ Cara Menjalankan Aplikasi (Starter Kit)

Untuk membuat sistem real-time dan sistem kasir berjalan sempurna, Anda wajib membuka **4 terminal terpisah** dan menjalankan perintah-perintah ini secara bersamaan:

- **Terminal 1: Web Server Utama**
  ```bash
  php artisan serve
  ```
- **Terminal 2: Frontend Vite Compiler**
  ```bash
  npm run dev
  ```
- **Terminal 3: WebSocket Server (Laravel Reverb)**
  ```bash
  php artisan reverb:start
  ```
- **Terminal 4: Background Queue Worker**
  ```bash
  php artisan queue:work
  ```

Setelah keempat mesin di atas menyala, buka browser Anda dan akses:
- **Dashboard Kasir:** `http://localhost:8000/admin/kasir`
- **Dashboard Dapur:** `http://localhost:8000/admin/dapur`
- **E-Menu Pelanggan (Simulasi Meja 1):** `http://localhost:8000/meja/1?token=[TOKEN_DARI_DATABASE]`
