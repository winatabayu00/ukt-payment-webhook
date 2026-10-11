# API Contract Proposal

> Semua route dan bentuk JSON di bawah adalah proposal awal, bukan endpoint yang secara eksplisit ditentukan oleh brief. Sesuaikan setelah memeriksa skeleton/project dan kebutuhan autentikasi.

## Konvensi umum
- Prefix proposal: `/api`.
- Format: JSON.
- Waktu: ISO 8601 dengan timezone yang eksplisit.
- Error menggunakan HTTP status yang tepat dan format JSON konsisten.
- Envelope standar via `App\Support\ApiResponse`: sukses `{success:true, message, data, meta?}`; error `{success:false, message, error:{code, details?}}` (422 `VALIDATION_FAILED` memakai `details:{field:[messages]}` gaya Laravel).
- `institution_id` tidak boleh menjadi mekanisme otorisasi dari client.
- Autentikasi pengguna/tenant belum ditentukan oleh brief; jangan menganggap endpoint privat aman sebelum mekanisme itu dipilih.

## 1. Membuat invoice
`POST /api/invoices` — auth: `Authorization: Bearer <api-token>` per institusi
(`ResolveInstitution`, 401 tanpa/kredensial salah); throttle `api-invoices`
per token per menit (default 60 via `API_INVOICE_RATE_LIMIT`) → `429` bila lewat.

Request proposal:
```json
{
  "student_number": "STU-001",
  "semester": "2026-1",
  "invoice_number": "UKT-2026-0001",
  "amount": "7500000.00",
  "expires_at": "2026-10-31T23:59:59+07:00"
}
```

Tenant berasal dari konteks autentikasi tepercaya, bukan dari `institution_id` di body.

Final (terverifikasi, openapi v1.4.0): sukses memakai `SuccessEnvelope`.
Response `201 Created`:
```json
{
  "success": true,
  "message": "Invoice created.",
  "data": {
    "id": "1",
    "student_number": "STU-001",
    "semester": "2026-1",
    "invoice_number": "UKT-2026-0001",
    "amount": "7500000.00",
    "expires_at": "2026-10-31T16:59:59Z",
    "status": "unpaid"
  }
}
```

## 2. Melihat invoice
`GET /api/invoices/{invoiceId}`

Kembalikan invoice hanya jika invoice milik tenant yang terautentikasi. Respons 404 dapat dipilih untuk menghindari pembocoran keberadaan resource lintas tenant.

## 3. Riwayat invoice mahasiswa
`GET /api/students/{studentNumber}/invoices`

Kembalikan invoice mahasiswa dalam tenant yang aktif. Tambahkan pagination bila dibutuhkan dan dokumentasikan.

## 4. Riwayat transaksi invoice
`GET /api/invoices/{invoiceId}/transactions`

Hanya transaksi dari invoice milik tenant yang aktif. Definisikan apakah response mencakup hanya transaksi finansial sah atau juga event expired; rekomendasi: receipt audit terpisah dari transaction history.

## Format error
Final (terverifikasi, openapi v1.4.0): semua error `/api/*` memakai `ErrorEnvelope`
`{success:false, message, error:{code, details?}}` via `ApiResponse::error`
(middleware `ResolveInstitution` → `INSTITUTION_UNRESOLVED|INSTITUTION_UNKNOWN` 401;
`StoreInvoiceRequest::failedValidation` + exception handler → `VALIDATION_FAILED` 422;
cross-tenant/not-found → `NOT_FOUND` 404; throttle → `RATE_LIMITED` 429;
fallback tak terduga → `UNAUTHENTICATED|FORBIDDEN|METHOD_NOT_ALLOWED|CONFLICT|REQUEST_FAILED|INTERNAL_ERROR`).
```json
{
  "success": false,
  "message": "The request is invalid.",
  "error": {
    "code": "VALIDATION_FAILED",
    "details": { "amount": ["The amount field is required."] }
  }
}
```

`error.code` stabil; tidak ada secret/signature/trace di body.

## Status HTTP kandidat
- `201`: invoice berhasil dibuat.
- `200`: query berhasil.
- `401/403`: autentikasi/otorisasi gagal sesuai kebijakan.
- `404`: resource tidak ditemukan atau tidak terlihat di tenant ini.
- `409`: konflik nomor invoice atau state/idempotency conflict bila tidak diperlakukan sebagai sukses idempotent.
- `422`: validasi gagal.
- Webhook: respons final harus mempertimbangkan kebijakan retry gateway dan didokumentasikan.

## Dokumentasi yang wajib diselesaikan
- Route, metode, auth, request, response, contoh error.
- Definisi status invoice.
- Pagination dan filter jika ada.
- Kontrak signature webhook di `docs/06-webhook-contract.md`.
