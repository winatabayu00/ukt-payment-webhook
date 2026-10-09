# Project: SEVIMA — UKT Payment Webhook

## Deskripsi singkat
Backend API untuk membuat dan melihat tagihan UKT per mahasiswa/semester, menerima notifikasi payment gateway, memperbarui status tagihan, dan menyimpan riwayat transaksi. Sistem melayani beberapa institusi dalam satu database.

## Stack dan cara menjalankan
- Stack yang diwajibkan: PHP/Laravel.
- Database relasional: MySQL atau PostgreSQL.
- Docker Compose dianjurkan oleh brief, tetapi bukan kewajiban eksplisit.
- Instruksi setup final harus ditulis dan diuji di `README.md` setelah versi runtime dipilih.
- Jangan mengklaim aplikasi berjalan sebelum perintah setup dan test benar-benar dijalankan.

## Arsitektur singkat
Rancangan awal adalah modular monolith Laravel dengan controller tipis, validasi request, service untuk aturan domain, model/migration, dan test otomatis. Detail berada di `docs/03-architecture.md`.

## Aturan bisnis penting
- Semua data tenant harus terkait dengan `institution_id`/`institusi_id` dan hanya dapat diakses dalam konteks institusi yang benar.
- Nomor tagihan unik **di dalam satu institusi**, bukan secara global.
- Setiap institusi memiliki HMAC secret sendiri.
- Webhook membawa kode institusi dan signature HMAC-SHA256 pada header `X-Signature`.
- Event yang disebut di brief: `payment.success` dan `payment.expired`.
- Semua notifikasi yang diterima perlu dicatat untuk audit, termasuk notifikasi yang ditolak; batasi penyimpanan data sensitif.
- Proses update status dan pencatatan transaksi harus atomik.
- Pengiriman ulang event tidak boleh membuat transaksi pembayaran ganda.
- Jangan membiarkan event terlambat atau berurutan terbalik merusak status pembayaran yang sudah terkonfirmasi; kebijakan transisi harus eksplisit dan diuji.
- Minimal tiga institusi harus tersedia pada seed data.
- Secret HMAC yang sesungguhnya dikirim terpisah dan tidak boleh ditulis ke repository.

## Konvensi kode
- Ikuti konvensi Laravel yang sesuai dengan versi proyek.
- Gunakan request validation dan respons API yang konsisten.
- Gunakan decimal/integer rupiah yang aman, bukan floating point untuk nominal uang.
- Gunakan nama enum/status yang konsisten dan dokumentasikan pemetaan label Indonesia.
- Gunakan transaksi database untuk operasi yang harus konsisten.
- Gunakan constraint/index database sebagai pertahanan tambahan, bukan hanya validasi aplikasi.
- Test harus memeriksa perilaku yang dapat diamati, bukan implementasi internal secara berlebihan.

## Hal yang tidak boleh dilakukan AI
- Jangan mengarang detail yang tidak ada di brief seolah-olah itu persyaratan resmi.
- Jangan menaruh secret, token, kredensial, atau data pribadi nyata di kode, test, log, atau dokumen.
- Jangan menghapus batas tenant atau mengandalkan `institution_id` dari body request sebagai otorisasi.
- Jangan memproses webhook sebelum signature diverifikasi.
- Jangan menyimpan perubahan status tanpa audit event yang sesuai.
- Jangan membuat endpoint, format payload, atau aturan transisi baru tanpa memperbarui dokumentasi dan test.
- Jangan mengklaim test/lint/setup berhasil sebelum benar-benar menjalankannya.
- Jangan menulis refleksi pribadi palsu di `AI_NOTES.md`; minta pemilik proyek mengisinya.
- Jangan melakukan refactor besar yang tidak terkait tanpa menjelaskan manfaat dan risikonya.
- Jangan mengubah file `.env` atau membocorkan secret.

## Urutan kerja yang disarankan
1. Cocokkan rancangan dengan brief dan tandai asumsi.
2. Buat migration, model, dan constraint.
3. Implementasikan aturan domain dan test unit.
4. Implementasikan API dan tenant context.
5. Implementasikan webhook signature verification, audit, idempotensi, dan atomic update.
6. Tambahkan test integrasi, dokumentasi, dan seed data.
7. Jalankan seluruh test dan tulis hasil aktual di README.
