# Data Model Proposal

> Skema berikut adalah rancangan awal. Tipe kolom final mengikuti database dan versi Laravel yang dipilih.

## `institutions`
| Kolom | Makna | Catatan |
|---|---|---|
| `id` | Primary key | bigint/UUID, pilih konsisten |
| `name` | Nama institusi | required |
| `code` | Kode institusi untuk routing webhook | unique |
| `webhook_secret` | Secret untuk HMAC | simpan terenkripsi/di secret manager; tidak boleh plaintext di repo |
| `api_token_hash` | SHA-256 hash token API per institusi | unique, nullable untuk baris lama; plaintext tidak pernah disimpan; issue/rotasi via `php artisan institution:token <CODE>` |
| `created_at`, `updated_at` | Audit waktu | standar Laravel |

Secret harus dapat dipulihkan untuk menghitung HMAC; hash satu arah saja tidak cukup. Pilih enkripsi at-rest dan kunci enkripsi dari environment/secret manager, atau strategi secret management yang sesuai.

## `invoices`
| Kolom | Makna | Catatan |
|---|---|---|
| `id` | Primary key | |
| `institution_id` | Pemilik tenant | FK ke institutions |
| `student_number` | NIM/nomor mahasiswa | scope-nya adalah institusi |
| `semester` | Semester tagihan | format belum ditentukan oleh brief |
| `invoice_number` | Nomor tagihan | unique gabungan dengan institution_id |
| `amount` | Nominal | decimal dengan presisi sesuai kebutuhan; hindari float |
| `expires_at` | Kedaluwarsa | timezone/format API didokumentasikan |
| `status` | unpaid/paid/expired | enum/string dengan constraint jika sesuai |
| `created_at`, `updated_at` | Timestamp | |

**Constraint wajib yang disarankan:** `UNIQUE (institution_id, invoice_number)`.

Indeks kandidat: `(institution_id, student_number)`, `(institution_id, semester)`, `(institution_id, status)`. Hindari indeks yang tidak dibutuhkan.

## `payment_transactions`
| Kolom | Makna | Catatan |
|---|---|---|
| `id` | Primary key | |
| `institution_id` | Tenant pemilik | FK/validasi konsistensi |
| `invoice_id` | Invoice terkait | FK |
| `gateway_transaction_id` | ID transaksi gateway | unique scope perlu dipastikan |
| `event_type` | Jenis event sah yang diproses | misalnya `payment.success` |
| `amount` | Nominal event bila tersedia | cocokkan terhadap invoice |
| `occurred_at` | Waktu event dari gateway bila tersedia | opsional (null bila absen); nilai yang ada tapi tak parseable di-reject 422 |
| `created_at` | Waktu diterima/disimpan | |

Keputusan deduplikasi tergantung apakah payload gateway menyediakan `event_id` atau `gateway_transaction_id`. Jangan menambahkan unique global tanpa memeriksa aturan scope gateway.

## `webhook_receipts`
| Kolom | Makna | Catatan |
|---|---|---|
| `id` | Primary key | |
| `institution_id` | Institusi yang teridentifikasi | nullable untuk kode tidak dikenal |
| `event_id` | ID event jika tersedia | nullable jika tidak disediakan |
| `event_type` | Event yang diterima jika dapat dibaca | nullable |
| `signature_valid` | Hasil verifikasi | nullable saat tidak dapat dievaluasi |
| `processing_status` | received/processed/rejected/failed | nilai final didefinisikan di kode |
| `payload_redacted` | Payload audit yang aman | redaksi data sensitif |
| `failure_reason` | Kategori alasan gagal | jangan simpan secret/header rahasia |
| `received_at` | Waktu penerimaan | |
| `processed_at` | Waktu selesai diproses | nullable |

## Integritas relasi
- Foreign key wajib untuk relasi institusi dan invoice.
- Jaga konsistensi bahwa `payment_transactions.institution_id` sama dengan `invoices.institution_id`, baik melalui desain skema/constraint yang sesuai maupun validasi transaksi.
- Seluruh query tenant harus difilter tenant context.
- Unique constraint harus sesuai scope tenant.
- `institution_id` pada model invoice/transaction tidak boleh diambil dari input bebas saat create jika tenant sudah diketahui.

## Hal yang perlu diputuskan
- ID bigint atau UUID.
- Format semester.
- Presisi nominal.
- Apakah satu invoice dapat memiliki beberapa pembayaran.
- Key deduplikasi event yang benar berdasarkan mock payload.
- Retensi payload audit dan redaksi field.
