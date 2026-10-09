# Assumptions and Open Questions

Dokumen ini mencegah usulan rancangan disalahartikan sebagai ketentuan brief.

## Belum ditentukan oleh brief
1. Mekanisme autentikasi dan provisioning tenant untuk API non-webhook.
2. Bentuk pasti payload mock gateway.
3. Lokasi kode institusi (body/header) dan encoding signature.
4. Apakah gateway mengirim `event_id`, `gateway_transaction_id`, amount, dan timestamp.
5. Aturan signature terhadap raw body dan format header.
6. Format nomor semester.
7. Apakah satu mahasiswa boleh punya lebih dari satu invoice per semester.
8. Aturan nominal/partial payment/overpayment.
9. Kebijakan event sukses setelah expired.
10. Apakah `expired` hanya dipicu event atau juga otomatis setelah `expires_at`.
11. HTTP response yang diharapkan mock gateway untuk invalid/duplicate events.
12. Retensi payload audit dan data yang harus direduksi.
13. Pilihan final PostgreSQL atau MySQL dan versi runtime.

## Asumsi kerja sementara
- Status teknis: `unpaid`, `paid`, `expired`.
- Satu invoice dimiliki tepat satu institusi.
- `invoice_number` unik dengan scope institusi.
- Event harus melewati verifikasi signature sebelum mengubah domain state.
- Pemrosesan yang memengaruhi status dan riwayat transaksi dilakukan atomik.
- Duplicate event harus idempotent.
- Semua receipt webhook dicatat, termasuk invalid.
- Seed data berisi minimal tiga institusi.
- UI dan gateway produksi tidak dibuat.

## Cara menyelesaikan pertanyaan
Untuk setiap pertanyaan:
1. Periksa brief/mock yang diberikan.
2. Jika masih tidak ditentukan, pilih aturan sederhana yang aman.
3. Catat alasan dan konsekuensi di `DECISIONS.md`.
4. Tambahkan acceptance criteria dan test.
5. Perbarui kontrak API/webhook.
