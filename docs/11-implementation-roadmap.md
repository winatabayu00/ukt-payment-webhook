# Implementation Roadmap (Granular)

Urutan di bawah menjaga perubahan kecil, dapat diuji, dan mudah direview.

## Phase 0 — Verifikasi lingkungan
1. Catat versi PHP, Composer, Laravel, database, Git.
2. Periksa repository dan jangan menimpa pekerjaan yang sudah ada.
3. Putuskan MySQL atau PostgreSQL.
4. Catat versi dan keputusan di README/DECISIONS.

## Phase 1 — Bootstrap
5. Buat project Laravel atau verifikasi skeleton yang tersedia.
6. Tambahkan `.gitignore` dan `.env.example`.
7. Tambahkan struktur dokumentasi dan test runner.
8. Pastikan aplikasi dasar berjalan.

## Phase 2 — Domain dan database
9. Definisikan status/event dan aturan transisi.
10. Buat migration institutions.
11. Buat migration invoices dan unique constraint tenant-aware.
12. Buat migration payment transactions.
13. Buat migration webhook receipts/audit.
14. Buat model dan relasi.
15. Buat factory/seeder minimal tiga institusi dengan secret palsu.

## Phase 3 — Invoice API
16. Tentukan tenant context/auth untuk API.
17. Buat request validation untuk pembuatan invoice.
18. Buat InvoiceService.
19. Buat endpoint create.
20. Buat endpoint status/detail.
21. Buat endpoint invoice list per mahasiswa.
22. Buat endpoint transaction history.
23. Tambahkan feature tests termasuk cross-tenant isolation.

## Phase 4 — Webhook
24. Periksa mock gateway dan konfirmasi payload/format signature.
25. Buat verifier HMAC dari raw body.
26. Buat test signature valid/invalid.
27. Buat audit receipt untuk semua notifikasi.
28. Buat processor untuk validasi event dan invoice tenant.
29. Implementasikan idempotensi dengan constraint database.
30. Implementasikan transaksi atomik untuk status + transaction history.
31. Definisikan event out-of-order dan konflik.
32. Tambahkan feature tests untuk sukses, expired, invalid, duplicate, dan conflict.

## Phase 5 — Dokumentasi dan verifikasi
33. Tulis API contract final dan contoh request.
34. Lengkapi README berdasarkan command yang benar-benar diuji.
35. Isi DECISIONS dengan keputusan final dan konsekuensi.
36. Isi AI_NOTES secara jujur.
37. Jalankan seluruh test suite.
38. Lakukan review keamanan tenant dan secret.
39. Uji clone/setup dari awal.
40. Buat commit kecil bermakna dan pastikan working tree bersih.

## Prioritas jika waktu terbatas
Prioritaskan kebenaran status, HMAC, isolasi tenant, idempotensi, audit, dan test. Hindari UI atau integrasi gateway sungguhan karena di luar cakupan.
