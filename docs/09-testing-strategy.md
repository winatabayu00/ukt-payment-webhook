# Testing Strategy

## Prinsip
Test otomatis wajib mencakup logika bisnis inti, terutama isolasi antar-institusi. Gunakan data seed/factory yang deterministik dan secret palsu khusus test.

## Unit tests
- `WebhookSignatureVerifierTest`
  - Signature benar diterima.
  - Signature salah ditolak.
  - Secret salah ditolak.
  - Perbedaan byte raw body mengubah hasil signature.
  - Format signature yang tidak sesuai ditolak.
- `InvoiceStatusTransitionTest` (jika logika transition dipisah)
  - Event sukses dan expired.
  - Event duplikat.
  - Event tidak berurutan.
  - Event yang tidak dikenal.

## Feature tests
- Membuat invoice valid.
- Validasi field wajib, nominal, expiry, dan format semester sesuai keputusan final.
- Duplikasi invoice number dalam satu institusi ditolak.
- Invoice number yang sama antar institusi diperbolehkan.
- Melihat invoice dalam tenant sendiri berhasil.
- Melihat invoice tenant lain ditolak/tidak terlihat.
- Riwayat transaksi hanya menampilkan transaksi dalam tenant sendiri.
- Webhook sukses dengan signature valid memperbarui status dan membuat transaction history.
- Webhook expired memperbarui status sesuai aturan.
- Signature invalid tidak mengubah status.
- Kode institusi tidak dikenal ditolak dan diaudit.
- Event duplikat tidak membuat transaksi kedua.
- Event conflict/out-of-order mengikuti state machine.
- Audit receipt dibuat untuk notifikasi valid dan invalid.
- Rollback jika salah satu operasi database gagal.

## Seed data
Minimal tiga institusi sesuai brief. Gunakan secret palsu hanya untuk lingkungan lokal/test. Jangan gunakan secret yang dikirim oleh recruiter dalam fixture yang di-commit.

## Quality gate sebelum submit
- Jalankan seluruh test suite.
- Jalankan formatter/linter jika dipasang.
- Uji setup dari awal dengan instruksi README.
- Periksa `git diff`, status git, file rahasia, dan migration.
- Catat hasil aktual; jangan tulis “semua test lulus” tanpa menjalankannya.
