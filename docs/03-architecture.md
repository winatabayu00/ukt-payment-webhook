# Architecture Proposal

## Prinsip
- Backend-only; tidak perlu UI pembayaran.
- Modular monolith Laravel.
- Controller menangani HTTP; validasi melalui Form Request; aturan bisnis di service/domain layer; integritas dijaga database.
- Test unit untuk algoritme/aturan murni dan feature test untuk API + database.
- Observability melalui audit log yang tidak membocorkan secret.

## Modul usulan

```text
app/
  Enums/
    InvoiceStatus.php
    PaymentEventType.php
  Http/
    Controllers/Api/
      InvoiceController.php
      StudentInvoiceController.php
      PaymentWebhookController.php
    Middleware/
      ResolveInstitution.php
    Requests/
      StoreInvoiceRequest.php
  Models/
    Institution.php
    Invoice.php
    PaymentTransaction.php
    WebhookReceipt.php
  Services/
    InvoiceService.php
    WebhookSignatureVerifier.php
    PaymentWebhookProcessor.php
  Policies/ (jika otorisasi model digunakan)
database/
  migrations/
  seeders/
tests/
  Feature/
  Unit/
docs/
```

Nama class dan folder di atas adalah usulan, bukan ketentuan brief.

## Alur membuat invoice
1. Autentikasi/identifikasi tenant dilakukan dengan mekanisme tepercaya.
2. Validasi field mahasiswa, semester, nominal, dan expiry.
3. Service menetapkan `institution_id` dari tenant context, bukan dari nilai bebas yang dipercaya dari body.
4. Buat invoice dengan nomor invoice.
5. Constraint database mencegah duplikasi nomor dalam institusi.
6. Kembalikan respons API yang terdokumentasi.

## Alur menerima webhook
1. Terima request dan ambil kode institusi dari field payload sesuai kontrak final.
2. Temukan institusi berdasarkan kode.
3. Catat receipt awal untuk audit, dengan payload yang dibatasi/dirapikan sesuai kebijakan privasi.
4. Verifikasi signature HMAC-SHA256 dari representasi body yang tepat dan secret institusi; gunakan perbandingan constant-time.
5. Jika institusi tidak ditemukan atau signature invalid, tandai receipt sebagai ditolak dan jangan mengubah invoice.
6. Validasi tipe event, invoice, identitas gateway, dan nominal.
7. Jalankan operasi idempotensi, transaksi history, dan perubahan status secara atomik.
8. Catat hasil pemrosesan dan kembalikan respons yang sesuai kebijakan retry.

## Saran pemisahan audit dan transaksi
`WebhookReceipt` merepresentasikan setiap permintaan yang diterima untuk audit. `PaymentTransaction` merepresentasikan catatan finansial/hasil event yang terkait dengan invoice. Pemisahan ini menghindari pencampuran notifikasi invalid dengan transaksi pembayaran yang sah.

## Konsistensi
- Gunakan database transaction untuk status + transaksi.
- Tambahkan unique constraints untuk key deduplikasi yang sudah dipastikan tersedia.
- Untuk request paralel, gunakan constraint database dan/atau locking sesuai kebutuhan.
- Hindari side effect eksternal dalam transaksi database.

## Bukan bagian rancangan
Tidak mencakup gateway produksi, UI, refund, settlement, rekonsiliasi bank, atau alur KRS selain latar belakang bisnis.
