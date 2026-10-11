# Payment Webhook Contract Proposal

## Yang diwajibkan brief
- Webhook menerima notifikasi payment gateway.
- Payload membawa kode institusi.
- Header `X-Signature` berisi HMAC-SHA256 yang diverifikasi dengan secret milik institusi tersebut.
- Event yang disebut: `payment.success` dan `payment.expired`.
- Semua notifikasi yang diterima dicatat untuk audit.

## Payload contoh — ilustratif, perlu disesuaikan dengan mock gateway
```json
{
  "institution_code": "CAMPUS-ALPHA",
  "event_id": "evt_example_0001",
  "event_type": "payment.success",
  "invoice_number": "UKT-2026-0001",
  "gateway_transaction_id": "gw_txn_example_0001",
  "amount": "7500000.00",
  "occurred_at": "2026-10-09T12:00:00Z"
}
```

Field `event_id`, `gateway_transaction_id`, `amount`, dan `occurred_at` adalah contoh rancangan. Jangan mengasumsikan semuanya tersedia sebelum mock gateway diperiksa.

## Signature
Konsep umum: `HMAC-SHA256(raw_request_body, institution_secret)` kemudian encoding signature sesuai kontrak gateway (misalnya hex). Header persis harus dibandingkan menurut format yang disepakati.

Final (terverifikasi): header `X-Signature` menerima hex polos maupun bentuk umum `sha256=<hex>` (case-insensitive), diverifikasi constant-time (`hash_equals`) atas raw body. Secret disimpan encrypted-at-rest (cast `encrypted`, kunci `APP_KEY`); rotasi via `php artisan institution:webhook-secret <CODE> [--rotate] [--secret=...]` — plaintext dicetak sekali dan didistribusikan ke gateway out-of-band; signature lama berhenti valid setelah rotasi.

Penting:
- Gunakan **raw request body** jika itu yang ditandatangani oleh gateway; jangan serialize ulang JSON lalu menganggap byte-nya identik.
- Verifikasi signature sebelum melakukan perubahan status atau transaksi.
- Gunakan fungsi perbandingan constant-time yang tersedia di runtime.
- Ambil secret berdasarkan kode institusi yang telah divalidasi.
- Secret harus dapat dipulihkan untuk HMAC; simpan aman dan jangan log.
- Jangan percaya `institution_id` yang dikirim dalam payload sebagai bukti otorisasi.

## Alur pemrosesan
1. Ambil raw body, signature header, dan kode institusi.
2. Buat audit receipt sedini mungkin dengan payload yang sudah dipertimbangkan untuk redaksi.
3. Resolve institusi berdasarkan kode.
4. Verifikasi HMAC.
5. Validasi event type dan required fields.
6. Temukan invoice dalam scope institusi yang sama.
7. Validasi amount dan identitas transaksi jika field tersebut tersedia/diwajibkan oleh kontrak final.
8. Terapkan deduplikasi dan aturan state transition.
9. Update invoice + simpan transaksi yang sah dalam satu transaksi database.
10. Simpan hasil receipt dan respons sesuai kebijakan retry.

## Idempotensi
- Pengiriman ulang event yang sama tidak boleh menghasilkan transaksi ganda.
- Utamakan event ID stabil dari gateway jika tersedia.
- Tambahkan constraint database sesuai scope yang benar.
- Jika tidak ada event ID, pilih key deduplikasi dari field stabil yang tersedia; dokumentasikan keterbatasan dan uji.
- Respons duplikat yang valid biasanya sebaiknya aman bagi retry, tetapi status HTTP final bergantung kontrak mock gateway.

## Event tidak berurutan
Kebijakan draft:
- Event `payment.expired` tidak boleh mengubah invoice yang sudah `paid` kembali menjadi `expired`.
- Event `payment.success` yang datang setelah invoice `expired` membutuhkan kebijakan eksplisit; jangan mengasumsikan hasilnya tanpa konteks gateway.
- Final (terverifikasi): `payment.success` untuk invoice `unpaid` yang sudah lewat `expires_at` secara waktu di-expire di tempat dan di-`ignored 200 success_after_expiry` tanpa baris `payment_transactions` — uang tidak bergerak untuk tagihan kedaluwarsa-waktu.
- `occurred_at` gateway di luar jendela staleness (default max 30 hari lalu / 15 mnt depan via `WEBHOOK_OCCURRED_AT_MAX_PAST_DAYS` / `WEBHOOK_OCCURRED_AT_MAX_FUTURE_MINUTES`, `config/api.php`) di-reject `422 occurred_at_too_old|occurred_at_in_future` tanpa menggerakkan uang.
- Event konflik tetap dicatat di audit.
- Perubahan status dan pencatatan transaksi harus atomik.

## Respons envelope
Final (terverifikasi, openapi v1.4.0): webhook memakai `WebhookEnvelope`
`{success:bool, message, data:{processing_status, failure_reason}}` via
`ApiResponse::webhook` — `processed|duplicate|ignored` → `success:true` 200
(aman untuk retry gateway); `rejected|failed` → `success:false` 4xx/500
(malformed JSON → `ErrorEnvelope` `MALFORMED_PAYLOAD` 400). Kunci
`data.*` stabil; `success`+`message` aditif; tanpa secret/signature/trace.

## Kegagalan dan audit
Audit minimal sebaiknya dapat menjawab:
- Kapan notifikasi diterima?
- Institusi mana yang dicari/teridentifikasi?
- Signature valid atau tidak?
- Event apa yang diterima?
- Apakah diproses, ditolak, atau gagal?
- Apa kategori alasan kegagalannya?

Jangan simpan secret, signature sensitif jika kebijakan melarangnya, authorization headers, atau data pribadi berlebihan.

Final (terverifikasi): redaksi `payload_redacted` rekursif di semua kedalaman (`webhook_secret/secret/card_number/card_cvv/signature` → `[REDACTED]`); retensi via `php artisan webhook:prune [--days=N] [--include-failed] [--dry-run]` (default N dari `WEBHOOK_RECEIPT_RETENTION_DAYS=90`; `failed` dipertahankan default untuk investigasi; invoices/transactions tidak pernah tersentuh).

## Pertanyaan yang wajib dijawab setelah mock tersedia
- Apakah signature hex lowercase/uppercase, prefix tertentu, atau format lain?
- Apakah kode institusi ada di payload atau header?
- Field mana yang wajib?
- Apakah ada `event_id` dan/atau ID transaksi yang stabil?
- Apakah timestamp ikut ditandatangani sebagai bagian raw body?
- Apa respons yang memicu retry?
