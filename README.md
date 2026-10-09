# SEVIMA — Project M2: Integrasi Pembayaran UKT via Webhook

> Knowledge base dan rancangan awal proyek tes teknis. Dokumen ini bukan implementasi Laravel yang sudah dapat dijalankan.

## Tujuan
Menyediakan konteks proyek yang konsisten untuk implementasi backend pengelolaan tagihan UKT, penerimaan webhook payment gateway, riwayat transaksi, dan isolasi data multi-institusi.

## Sumber kebutuhan
Dokumen utama: **Tes Teknis Software Engineer SEVIMA — Project M2: Integrasi Pembayaran UKT via Webhook**. Kebutuhan yang secara eksplisit tercantum di brief dirangkum dalam `docs/01-requirements-traceability.md`.

## Cara menggunakan knowledge base
1. Baca `CLAUDE.md` sebelum meminta AI mengubah kode.
2. Baca `docs/01-requirements-traceability.md` dan `docs/02-domain-and-business-rules.md` sebagai konteks domain.
3. Gunakan `docs/03-architecture.md`, `docs/04-data-model.md`, dan kontrak API/webhook sebagai rancangan kerja.
4. Catat keputusan final dan alternatif yang dipertimbangkan di `DECISIONS.md`.
5. Isi `AI_NOTES.md` berdasarkan pengalaman pairing yang benar-benar terjadi. Jangan mengarang refleksi.
6. Saat implementasi berjalan, perbarui dokumen jika keputusan berubah.

## Status rancangan
- [x] Kebutuhan dari brief dirangkum.
- [x] Usulan arsitektur, model data, API, webhook, keamanan, dan test plan didokumentasikan.
- [ ] Versi PHP/Laravel dan database lokal dikonfirmasi.
- [ ] Keputusan final disetujui dan dicatat.
- [ ] Aplikasi Laravel dibuat dan dijalankan.
- [ ] Kontrak endpoint diverifikasi melalui implementasi dan test.
- [ ] README dilengkapi hasil uji aktual dan instruksi yang sudah diverifikasi.

## Batasan penting
- Integrasi ke payment gateway sungguhan dan UI pembayaran berada di luar cakupan brief.
- Endpoint dan bentuk payload pada dokumen ini adalah **proposal implementasi**, kecuali jika disebut eksplisit sebagai ketentuan brief.
- Secret asli tidak boleh dimasukkan ke repository, contoh payload, test fixture, atau dokumentasi.
