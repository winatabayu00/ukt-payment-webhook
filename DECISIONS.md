# Technical Decisions

> Draft keputusan untuk ditinjau dan disahkan selama implementasi. Brief meminta 3–5 keputusan teknis penting, alternatif, dan alasan. Jangan menganggap draft ini sebagai keputusan final sebelum diverifikasi.

## 1. Modular monolith Laravel
**Konteks:** Ruang lingkup adalah backend API dengan domain yang relatif terfokus dan estimasi pengerjaan 10–14 jam.

**Opsi:**
- A. Modular monolith Laravel.
- B. Microservices terpisah untuk invoice, webhook, dan audit.
- C. Struktur controller/model tanpa pemisahan layanan domain.

**Keputusan draft:** A — gunakan satu aplikasi Laravel dengan batas tanggung jawab yang jelas.

**Alasan:** Lebih sederhana dijalankan, diuji, dan dijelaskan dalam waktu terbatas; belum ada kebutuhan operasional yang membenarkan microservices.

**Konsekuensi:** Batas modul tetap perlu dijaga melalui service, request validation, model, dan test.

## 2. Isolasi tenant secara eksplisit
**Konteks:** Satu database melayani banyak institusi; pengguna hanya boleh mengakses data institusinya.

**Opsi:**
- A. Filter `institution_id` pada semua query yang relevan, dengan tenant context tepercaya.
- B. Mengandalkan client mengirim `institution_id`.
- C. Database terpisah untuk tiap institusi.

**Keputusan draft:** A.

**Alasan:** Selaras dengan model satu database di brief dan mencegah pengguna memilih tenant arbitrer. Tambahkan foreign key, indeks, unique constraint gabungan, dan test isolasi.

**Konsekuensi:** Tenant context harus dibentuk dari identitas/credential yang tepercaya. Mekanisme autentikasi pengguna perlu diputuskan karena detailnya tidak ditentukan dalam brief.

## 3. Webhook harus diverifikasi dan diproses secara atomik
**Konteks:** Notifikasi eksternal dapat salah, dikirim ulang, atau datang tidak berurutan.

**Opsi:**
- A. Verifikasi signature, catat penerimaan, lalu lakukan update dalam transaksi database.
- B. Mengubah status langsung dari payload sebelum verifikasi.
- C. Menjalankan update status dan pencatatan transaksi dalam operasi terpisah.

**Keputusan draft:** A.

**Alasan:** Memastikan notifikasi terautentikasi dan mengurangi keadaan setengah berhasil. Gunakan perbandingan signature constant-time dan transaksi database.

**Konsekuensi:** Perlu kebijakan eksplisit tentang pencatatan notifikasi invalid, kode respons webhook, dan retry gateway.

## 4. Idempotensi dan penanganan event yang berulang
**Konteks:** Gateway dapat mengirim event yang sama lebih dari sekali.

**Opsi:**
- A. Identitas event atau transaksi gateway yang unik, constraint database, dan pemrosesan idempotent.
- B. Mengandalkan pengecekan aplikasi tanpa constraint.
- C. Menganggap setiap request sebagai transaksi baru.

**Keputusan draft:** A, dengan identitas deduplikasi dipastikan sesuai payload mock gateway yang tersedia.

**Alasan:** Mencegah pembayaran ganda di riwayat dan update berulang yang tidak konsisten.

**Konsekuensi:** Jika payload mock tidak memiliki `event_id`, tentukan key deduplikasi yang stabil dan dokumentasikan keterbatasannya; jangan diam-diam menganggap kombinasi field pasti unik.

## 5. Relational database dengan constraint domain
**Konteks:** Data invoice, transaksi, institusi, dan audit harus konsisten.

**Opsi:**
- A. PostgreSQL.
- B. MySQL.
- C. Penyimpanan non-relasional.

**Keputusan draft:** Pilih PostgreSQL jika lingkungan lokal mendukung; MySQL juga memenuhi brief.

**Alasan:** Keduanya sesuai kebutuhan relasional. Pilihan final ditentukan oleh runtime yang tersedia dan kompatibilitas deployment.

**Konsekuensi:** Migration, tipe JSON, constraint, dan instruksi setup harus sesuai database yang dipilih.
