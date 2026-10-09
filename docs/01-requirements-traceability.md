# Requirements Traceability

Dokumen ini memisahkan ketentuan eksplisit dari brief dengan usulan implementasi.

## Kebutuhan wajib dari brief

| ID | Kebutuhan | Bukti implementasi yang direncanakan |
|---|---|---|
| R-01 | Membuat tagihan UKT per mahasiswa per semester, berisi nomor, nominal, tanggal kedaluwarsa, untuk tiap institusi | Endpoint pembuatan invoice, validasi, model/migration, feature test |
| R-02 | Endpoint webhook untuk notifikasi payment gateway | Endpoint webhook dan kontrak payload |
| R-03 | Status: belum bayar, lunas, kedaluwarsa, sesuai notifikasi | State transition service dan test |
| R-04 | Riwayat transaksi setiap tagihan | Tabel transaksi, endpoint history, test |
| R-05 | Endpoint melihat status tagihan dan riwayat transaksi mahasiswa | Endpoint status invoice dan riwayat mahasiswa |
| R-06 | API terdokumentasi | README + kontrak API/OpenAPI |
| R-07 | Banyak kampus dalam satu database; semua data milik satu institusi; akses hanya untuk institusi sendiri | Tenant context, query scope, FK, composite constraints, isolation tests |
| R-08 | Secret HMAC per institusi; kode institusi di notifikasi; HMAC-SHA256 di `X-Signature` | Signature verifier, pengelolaan secret, test valid/invalid |
| R-09 | Nomor invoice unik per institusi, boleh sama antar-institusi | Unique constraint `(institution_id, invoice_number)` |
| R-10 | Status mencerminkan kondisi pembayaran sebenarnya | Kebijakan event terlambat/duplikat/konflik dan test |
| R-11 | Semua notifikasi yang diterima dicatat untuk audit | Webhook receipt/audit table dan test |
| R-12 | PHP/Laravel + MySQL atau PostgreSQL | Composer/Laravel project dan relational DB |
| R-13 | Project dapat dijalankan dengan instruksi; Docker Compose dianjurkan | README, `.env.example`, opsional `compose.yaml` |
| R-14 | Mock gateway dapat disediakan; event `payment.success`, `payment.expired`; minimal tiga institusi seed | Dokumen kontrak mock, script/test helper bila sesuai, seed data |
| R-15 | Integrasi gateway sungguhan dan UI pembayaran di luar cakupan | Tidak dibuat kecuali ada alasan eksplisit |

## Artefak wajib

- Kode proyek dapat dijalankan.
- `README.md`: setup, menjalankan, asumsi.
- `CLAUDE.md` atau konteks AI: domain, konvensi, batasan, isolasi institusi.
- `AI_NOTES.md`: refleksi pairing yang jujur.
- `DECISIONS.md`: 3–5 keputusan, opsi, alasan/konsekuensi.
- Test otomatis untuk logika inti, termasuk tenant isolation.
- Riwayat commit granular dan bermakna.
- Tidak ada secret di repository.

## Konteks tahap wawancara
Brief menyebut wawancara teknis 75–90 menit: penjelasan desain/alur bisnis, analisis insiden dari log, pengembangan lanjutan bersama AI, code review, dan diskusi desain sistem. Karena itu, rancangan harus dapat dijelaskan, log aman, dan keputusan dicatat.

## Catatan
Deadline yang tertera pada brief: Minggu, 11 Oktober pukul 15.00 WIB. Pastikan tanggal dan zona waktu mengikuti brief yang diberikan.
