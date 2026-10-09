# Local Development and Delivery

## Prasyarat
- PHP dan Composer sesuai versi Laravel yang dipilih.
- Database MySQL atau PostgreSQL.
- Git.
- Docker Compose opsional tetapi dianjurkan oleh brief.

## Setup yang harus didokumentasikan setelah implementasi
1. Clone repository.
2. Install dependency dengan Composer.
3. Salin `.env.example` ke `.env`.
4. Isi kredensial database lokal dan app key melalui instruksi Laravel.
5. Buat database.
6. Jalankan migration dan seeder.
7. Jalankan server/API.
8. Jalankan test suite.
9. Jalankan mock webhook sesuai contoh kontrak.
10. Verifikasi endpoint dengan request yang terdokumentasi.

Jangan mencantumkan command spesifik yang belum diuji terhadap versi Laravel yang dipilih.

## Konfigurasi
- `.env.example` boleh memuat nama variabel dan placeholder.
- Jangan commit `.env`.
- Secret webhook demo hanya untuk lokal dan tidak boleh dianggap secret produksi.
- Tambahkan file `.env` ke `.gitignore` dan periksa status Git sebelum commit.

## Docker Compose
Jika digunakan, sediakan service app dan database dengan volume/healthcheck yang jelas. Jangan menyimpan password produksi dalam compose file. Dokumentasikan port dan cara reset database.

## Delivery checklist
- Repository privat dan akses reviewer mengikuti brief.
- README setup diverifikasi dari environment bersih.
- Test core dan tenant isolation berjalan.
- Seed minimal tiga institusi.
- `CLAUDE.md`, `AI_NOTES.md`, `DECISIONS.md` lengkap.
- Commit granular.
- Tidak ada secret di repository.
- Project dapat didemonstrasikan bersama mock gateway.
