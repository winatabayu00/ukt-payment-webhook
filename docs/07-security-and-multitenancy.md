# Security dan Multi-tenancy

## Model ancaman ringkas
Risiko utama pada brief:
- Pengguna satu kampus membaca/mengubah data kampus lain.
- Nomor invoice sama antar kampus menyebabkan invoice salah terpilih.
- Webhook palsu atau diubah sebelum diterima.
- Secret bocor melalui repository, log, atau contoh.
- Event duplikat atau tidak berurutan menyebabkan status keliru.

## Tenant context
- Invoice API autentikasi dengan token per institusi: header
  `Authorization: Bearer <api-token>`, di-resolve oleh `ResolveInstitution`.
  Hanya hash SHA-256 yang disimpan (`institutions.api_token_hash`, unique);
  plaintext tidak pernah disimpan, didistribusikan out-of-band, dirotasi via
  `php artisan institution:token <CODE> [--rotate]`. Hilang → `401
  INSTITUTION_UNRESOLVED`, tidak dikenal/dicabut → `401 INSTITUTION_UNKNOWN`.
- Brief tidak mendefinisikan mekanisme auth; mekanisme token di atas adalah
  pilihan proporsional yang terdokumentasi untuk tes (OAuth/IdP future work).
- Jangan mengandalkan `institution_id` dari request body/query sebagai otorisasi.
- Setiap query invoice/transaction harus dibatasi pada institusi aktif.
- Semua resource yang diturunkan dari ID harus tetap memeriksa kepemilikan tenant.
- Gunakan unique constraint `(institution_id, invoice_number)`.
- Pastikan relasi transaksi dan invoice tidak menyeberang tenant.

## Webhook security
- Resolve institusi dengan `institution_code`.
- Verifikasi HMAC-SHA256 atas representasi payload yang tepat.
- Gunakan constant-time comparison.
- Tolak signature invalid sebelum domain side effects.
- Validasi tipe event dan field.
- Pertimbangkan replay/idempotency; timestamp saja tidak cukup kecuali ada kebijakan freshness dan clock skew.
- Rate limiting aktif: invoice `throttle:api-invoices` per token per menit
  (`API_INVOICE_RATE_LIMIT`, default 60, `config/api.php` + `AppServiceProvider`),
  webhook `throttle:api-webhooks` per IP per menit (`API_WEBHOOK_RATE_LIMIT`,
  default 300) → `429` bila lewat.
- Hindari membocorkan apakah invoice/tenant ada melalui error detail yang tidak diperlukan.

## Secret handling
- Tidak boleh commit secret asli.
- `.env.example` hanya berisi placeholder.
- Jangan menaruh secret dalam test fixtures, dokumentasi, screenshot, atau output CI.
- Karena HMAC memerlukan secret asli, hash satu arah tidak cukup; gunakan enkripsi/secret manager dengan kunci terpisah.
- Rotasi secret dan versi key berada di luar detail brief, tetapi dapat menjadi catatan future work.

## Audit log
- Catat setiap notifikasi yang diterima, termasuk yang tidak valid.
- Jangan log secret atau kredensial.
- Batasi payload yang disimpan; redaksi field pribadi yang tidak diperlukan.
- Gunakan kategori error stabil, bukan stack trace mentah ke client.
- Batasi akses ke audit log.

## Test keamanan minimum
- Institusi A tidak dapat melihat invoice Institusi B.
- Institusi A tidak dapat membaca transaction history Institusi B.
- Nomor invoice yang sama boleh ada pada dua institusi dan webhook resolve invoice dalam tenant yang benar.
- Signature invalid tidak mengubah status invoice.
- Secret institusi A tidak dapat dipakai untuk menandatangani payload bagi institusi B.
