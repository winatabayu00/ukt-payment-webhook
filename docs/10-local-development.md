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

## Mock gateway sender (verified)

One-shot demo script (preferred for reviewers) — spins up a throwaway sqlite
DB, serves the app, creates two invoices, drives success + expired webhooks
with an idempotent replay, and asserts every step:

```bash
bash scripts/demo.sh
# DEMO_PORT=18099 DEMO_KEEP=1 bash scripts/demo.sh  # keep server+DB for poking
```

Command `php artisan mock:gateway-event` builds a contract-valid payload
(`contracts/openapi.yaml`), signs it with the institution `webhook_secret`
(DB lookup, demo-only secrets), and POSTs to `/api/webhooks/payments`.

```bash
php artisan mock:gateway-event --help
# create an invoice first, then send a signed success event:
php artisan mock:gateway-event --invoice=INV-MOCK-001 --event-id=evt-demo-1 --gateway-id=gw-demo-1
# expired path:
php artisan mock:gateway-event --event=expired --invoice=INV-MOCK-001 --event-id=evt-demo-2 --gateway-id=gw-demo-2
# replay demo: re-send the same --event-id → 200 duplicate/event_duplicate
# bad-signature demo: add --invalid-signature → 401 signature_invalid
# build only: add --no-send (prints payload + signature, does not POST)
# curl equivalent: add --print-curl
```

Verified outcomes against local sqlite app (`migrate:fresh --seed`, serve):

| Scenario | Result |
|---|---|
| `success` on unpaid invoice | `200 processed` |
| same `--event-id` replayed | `200 duplicate/event_duplicate` |
| `--invalid-signature` | `401 rejected/signature_invalid` |
| `expired` on unpaid invoice | `200 processed` |
| late event on final invoice | `200 ignored/already_final` |

The builder (`App\Support\MockGatewayEventBuilder`) is also reusable in
tests without HTTP: `::success([...])` / `::expired([...])` →
`payload()`, `rawBody()`, `signature($secret)`, `headers($secret)`, `asCurl($url, $secret)`.

## PostgreSQL via Docker (user-managed)

App default memakai sqlite lokal. Untuk memakai PostgreSQL di Docker milik
sendiri (tanpa commit secret):

```bash
docker exec postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "CREATE DATABASE ukt_payment;"'
```

Arahkan `.env` lokal (tetap ignored, jangan commit):

```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=ukt_payment
DB_USERNAME=<POSTGRES_USER kamu>
DB_PASSWORD=<POSTGRES_PASSWORD kamu>
```

Lalu migrate + seed dan verifikasi:

```bash
php artisan migrate:fresh --force --seed
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8000/up  # -> 200
vendor/bin/phpunit  # tetap hijau via sqlite :memory:
```

Catatan: bila shell mengekspor `DB_CONNECTION=sqlite` / `DB_DATABASE=...`,
Laravel akan memakai shell env itu (bukan `.env`). Jalankan artisan dengan
`env -u DB_CONNECTION -u DB_DATABASE` untuk memakai `.env`.

## Konfigurasi
- `.env.example` boleh memuat nama variabel dan placeholder.
- Jangan commit `.env`.
- Secret webhook demo hanya untuk lokal dan tidak boleh dianggap secret produksi.
- Tambahkan file `.env` ke `.gitignore` dan periksa status Git sebelum commit.

## Konfigurasi produksi (template)
- Template: `.env.production.example` (`APP_ENV=production`, `APP_DEBUG=false`,
  pgsql placeholders, tanpa secret). Isi kredensial asli via secret
  manager/env di host, jangan pernah commit.
- Checklist deploy ada di header file template: `APP_KEY` via
  `php artisan key:generate --show`, `migrate --force` (jangan pernah
  `migrate:fresh` di data prod), seed institusi TANPA secret demo lalu
  `php artisan institution:token <CODE>` per institusi dan distribusikan
  plaintext out-of-band, serve di belakang TLS + reverse proxy.
- Rate limit prod dapat dioverride via `API_INVOICE_RATE_LIMIT` /
  `API_WEBHOOK_RATE_LIMIT` tanpa ubah kode.

## Queue worker + monitoring webhook (verified)

Webhook tetap diproses sinkron idempotent di request; efek samping
`payment.success` (notifikasi out-of-band) jalan via queue driver `database`
(`QUEUE_CONNECTION=database`, tabel `jobs`/`failed_jobs` dari migrasi stock):

```bash
php artisan queue:work --queue=default        # proses NotifyPaymentSuccessJob
php artisan webhook:monitor                   # ringkasan receipts + antrian
php artisan webhook:monitor --institution=CAMPUS-ALPHA --failures=20
```

Perilaku yang terverifikasi:

- `payment.success` yang commit (invoice Paid + baris `payment_transactions`)
  mendispatch `NotifyPaymentSuccessJob` (tries 3, backoff 10/60/300s)
  SETELAH transaksi DB — rollback tidak pernah meninggalkan job stray,
  kegagalan dispatch tidak pernah me-rollback pembayaran. `expired`,
  `duplicate`, `ignored`, `rejected` tidak dispatch job.
- Job idempotent dan aman di-rerun: record hilang / invoice belum paid →
  skip dengan log `payment.notification.skipped`, bukan exception.
- Exception tak terduga di processor menandai receipt `failed/internal_error`
  (persisted `failure_reason` stabil, tanpa detail internals), log
  `webhook.failed` berisi konteks terstruktur (ids + exception class), dan
  HTTP `500` agar gateway retry. Respons tidak pernah membocorkan secret.
- Log terstruktur tanpa secret: `webhook.processed` (setiap outcome),
  `webhook.failed` (exception), `payment.notification.sent/skipped`.
- `webhook:monitor` read-only: tabel count per `processing_status`,
  failures terbaru (`rejected` + `failed` dengan `failure_reason`),
  `jobs pending` + `failed_jobs` + entri `failed_jobs` terbaru,
  warning bila ada failed receipt/job (atau `OK` bila bersih).

Verified live (throwaway sqlite, `scripts/demo.sh` + worker):

| Check | Result |
|---|---|
| `demo.sh` (create + success/expired + replay) | green |
| `webhook:monitor` setelah demo | `processed 2, duplicate 1, failed 0`, `jobs pending 1` |
| `queue:work --once` | `NotifyPaymentSuccessJob DONE`, `0 pending, 0 failed` |
| `webhook:monitor` setelah worker | `0 pending`, `OK: no failed receipts or jobs` |

`scripts/demo.sh` hermetic: memaksa `DB_CONNECTION=sqlite` + throwaway DB via
template `mktemp` portabel (trailing `X`), sehingga export `DB_*` di host-shell
(mis. kredensial pgsql harian) tidak pernah membelokkan demo ke database lain.

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
