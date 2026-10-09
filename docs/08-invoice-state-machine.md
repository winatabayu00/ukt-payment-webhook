# Invoice Status and State Transitions

## Status yang disebut brief
- `unpaid` — belum bayar.
- `paid` — lunas.
- `expired` — kedaluwarsa.

Nama enum teknis di atas adalah proposal. API dapat memakai label Indonesia jika diinginkan, tetapi harus konsisten.

## Matriks transisi draft

| Status saat ini | Event | Status yang diusulkan | Catatan |
|---|---|---|---|
| unpaid | payment.success valid | paid | Hanya setelah signature, invoice, amount, dan idempotensi lolos |
| unpaid | payment.expired valid | expired | Receipt audit tetap dicatat |
| paid | payment.success duplikat | paid | Tidak membuat transaksi finansial duplikat |
| paid | payment.expired terlambat | paid | Jangan menurunkan status pembayaran |
| expired | payment.expired duplikat | expired | Idempotent |
| expired | payment.success terlambat | perlu keputusan | Kebijakan harus ditulis dan diuji |

## Aturan implementasi
- Jangan mengubah status berdasarkan event type saja.
- Validasi tenant dan invoice terlebih dahulu.
- Update status dan transaction record dalam transaksi database.
- Audit receipt tetap merekam event yang ditolak atau duplikat.
- Pertimbangkan konkurensi: dua webhook bisa diproses bersamaan.
- Tentukan apakah expiry time sendiri memengaruhi status atau status hanya berubah saat event gateway diterima. Brief belum sepenuhnya mendefinisikan hal ini.

## Acceptance criteria
1. Webhook valid `payment.success` untuk invoice `unpaid` menjadikan status `paid`.
2. Webhook valid `payment.expired` untuk invoice `unpaid` menjadikan status `expired`.
3. Signature invalid tidak mengubah status.
4. Event yang sama dikirim dua kali tidak menggandakan transaksi.
5. `payment.expired` terlambat tidak menurunkan status `paid`.
6. Event sukses yang datang setelah expired mengikuti keputusan domain terdokumentasi.
7. Event yang merujuk invoice di institusi lain tidak boleh memengaruhi invoice tersebut.
