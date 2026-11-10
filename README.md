# Midtrans Payment Gateway untuk Perfex CRM

Modul pembayaran Midtrans (Snap) untuk invoice Perfex CRM. Mendukung semua metode yang
diaktifkan di akun Midtrans Anda: Virtual Account, GoPay/ShopeePay, QRIS, kartu kredit,
convenience store, dll.

## Instalasi
1. Ekstrak folder `midtrans` ke `modules/` Perfex CRM (hasil: `modules/midtrans/midtrans.php`).
2. Buka **Setup > Modules**, lalu **Activate** modul *Midtrans Payment Gateway*.
3. Buka **Setup > Settings > Payment Gateways > Midtrans**, aktifkan, isi Server Key & Client Key
   (Sandbox dan/atau Production), lalu pilih mode Test/Live.
4. Di **Midtrans Dashboard > Settings > Configuration**, isi:
   - **Payment Notification URL**: `https://DOMAIN-ANDA/midtrans/webhook`
   - (opsional) Finish/Unfinish/Error Redirect URL dikosongkan saja; modul mengirim callback sendiri.

## Catatan
- Hanya mendukung invoice **IDR**. Invoice mata uang lain akan ditolak dengan pesan.
- Status pembayaran selalu diverifikasi ke API Midtrans (bukan dari parameter URL), dan webhook
  divalidasi dengan `signature_key` (SHA512).
- Pencatatan pembayaran idempotent: halaman finish dan webhook tidak akan membuat pembayaran ganda.
- Jika webhook mendapat respon 403 karena CSRF, tambahkan `'midtrans/webhook'` ke
  `$config['csrf_exclude_uris']` di `application/config/config.php`.

## Uji coba Sandbox
Gunakan kartu tes Midtrans (mis. `4811 1111 1111 1114`, CVV `123`, exp bulan/tahun mendatang, OTP `112233`).
