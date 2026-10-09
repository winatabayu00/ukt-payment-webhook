# Domain dan Aturan Bisnis

## Istilah
- **Institusi:** Kampus/tenant yang memiliki data dan secret webhook sendiri.
- **Mahasiswa:** Pemilik tagihan, diidentifikasi oleh nomor mahasiswa/NIM dalam konteks institusi.
- **Invoice/tagihan:** Kewajiban pembayaran UKT mahasiswa untuk semester tertentu.
- **Webhook notification:** Pesan dari payment gateway yang menyatakan event pembayaran.
- **Transaction history:** Catatan event transaksi terkait invoice.
- **Webhook audit log:** Catatan penerimaan dan hasil pemrosesan notifikasi, termasuk notifikasi yang ditolak.

## Aturan eksplisit dari brief
1. Tagihan dibuat per mahasiswa per semester.
2. Tagihan memiliki nomor, nominal, dan tanggal kedaluwarsa.
3. Status tagihan yang digunakan: belum bayar, lunas, atau kedaluwarsa.
4. Banyak institusi berbagi satu database.
5. Setiap data harus terikat pada institusi.
6. Pengguna hanya boleh mengakses data institusinya sendiri.
7. Secret HMAC berbeda per institusi.
8. Signature menggunakan HMAC-SHA256 dan diterima pada header `X-Signature`.
9. Nomor invoice unik per institusi, bukan global.
10. Semua notifikasi yang diterima dicatat untuk audit.

## Aturan operasional yang perlu difinalisasi
- Apakah mahasiswa dapat memiliki lebih dari satu invoice untuk semester yang sama? Brief tidak menjelaskan uniqueness mahasiswa+semester. Jangan menambah constraint tersebut tanpa keputusan.
- Apakah nominal dibayar harus persis sama dengan nominal invoice? Disarankan ya untuk event `payment.success`, tetapi verifikasi berdasarkan payload mock yang tersedia.
- Apakah `payment.expired` boleh diterima setelah `payment.success`? Rekomendasi: status `paid` tidak boleh mundur ke `expired`.
- Apakah `payment.success` boleh diterima setelah status expired? Ini adalah keputusan domain penting: verifikasi timestamp/aturan gateway bila tersedia; jika tidak, jangan otomatis menganggapnya valid atau menolaknya tanpa kebijakan tertulis.
- Bagaimana jika dua transaksi sukses berbeda mengacu ke invoice yang sama? Jangan membuat invoice seolah-olah dibayar dua kali; simpan event audit dan definisikan penanganan konflik/refund sebagai out of scope bila tidak diminta.
- Apakah transaksi history menyimpan event expired sebagai transaksi? Bedakan audit notifikasi dari riwayat transaksi finansial; putuskan berdasarkan definisi endpoint yang dipilih.

## Prinsip uang dan waktu
- Hindari floating point untuk nilai uang.
- Simpan waktu dalam format timezone yang konsisten (umumnya UTC di database) dan dokumentasikan zona waktu API.
- Definisikan apakah invoice expired dihitung saat `expires_at` lewat secara waktu, atau hanya setelah event `payment.expired`. Brief menyebut status mengikuti notifikasi, tetapi semantik pastinya perlu dijelaskan.
