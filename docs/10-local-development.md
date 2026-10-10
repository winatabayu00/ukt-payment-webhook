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
