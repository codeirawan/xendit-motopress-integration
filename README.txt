Description
Plugin ini menambahkan metode pembayaran Xendit ke dalam MotoPress Hotel Booking, memungkinkan tamu hotel melakukan pembayaran melalui Transfer Bank, E-Wallet, QRIS, dan metode pembayaran lain yang tersedia di Xendit.

Installation
1. Unduh file ZIP plugin ini.
2. Masuk ke Plugins > Add New > Upload Plugin di admin WordPress.
3. Upload file ZIP dan klik Install Now.
4. Aktifkan plugin.
5. Pastikan plugin MotoPress Hotel Booking sudah aktif.

Configuration
1. Ambil API Key Xendit
   - Login ke Xendit Dashboard (https://dashboard.xendit.co/).
   - Masuk ke menu Settings > API Keys.
   - Salin Secret API Key.

2. Ganti API Key di Plugin
   - Buka file: /wp-content/plugins/xendit-motopress-integration/includes/class-xendit-gateway.php
   - Lalu inisialisasi API:
     private $xendit_secret_key = 'xnd_development_xxxxxxx'; // Ganti dengan API Key Anda
   - Gunakan Development Key untuk testing, dan Live Key saat produksi.

3. Daftarkan Webhook di Dashboard Xendit
   - Masuk ke menu Settings > Callbacks > Invoice Paid Callback URL.
   - Masukkan URL: https://domain-anda.com/wp-json/xendit/v1/webhook
   - Simpan perubahan.
