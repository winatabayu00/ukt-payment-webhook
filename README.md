# SEVIMA — Project M2: Integrasi Pembayaran UKT via Webhook

![CI](https://github.com/winatabayu00/ukt-payment-webhook/actions/workflows/ci.yml/badge.svg)

Laravel modular-monolith backend for UKT invoices, tenant-isolated reads, and HMAC-verified payment webhooks with audit + idempotency.

## Verified runtime (Phase 1)

- PHP 8.3.30, Composer 2.6.5, Laravel 13.35.0, PHPUnit 12.5.38
- `DB_CONNECTION=sqlite`, `DB_DATABASE=database/database.sqlite` (local/test verified)
- PostgreSQL is user-managed: `.env.example` keeps commented `DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD` placeholders only
- Suite: `OK (44 tests, 176 assertions)` on sqlite `:memory:` via `phpunit.xml`
- Health: `GET /up` → `200`
- Git: `main`, no secrets committed (`.env` + `*.sqlite` ignored)

## Quickstart (verified)

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate:fresh --force --seed
php artisan serve --port=8000
curl -i http://127.0.0.1:8000/up   # 200
vendor/bin/phpunit                  # OK (44 tests, 176 assertions)
```

Mock gateway sender (verified E2E, see `docs/10-local-development.md`):

```bash
php artisan mock:gateway-event --invoice=INV-MOCK-001 --event-id=evt-demo-1 --gateway-id=gw-demo-1
```

Full end-to-end demo (throwaway sqlite DB, asserts every step — invoice create,
success + expired webhooks, idempotent replay, final statuses):

```bash
bash scripts/demo.sh   # 🎉 demo green: invoice create + success/expired webhooks + idempotent replay
```

Seed (`InstitutionSeeder`, demo-only secrets — rotate in real envs):

- `CAMPUS-ALPHA / Kampus Alpha`
- `CAMPUS-BETA / Kampus Beta`
- `CAMPUS-GAMMA / Kampus Gamma`

## Routes (verified via `route:list`)

| Method | URI | Auth/scope | Notes |
|---|---|---|---|
| `POST` | `/api/invoices` | `Authorization: Bearer <api-token>` via `institution.resolve` + `throttle:api-invoices` | validate `student_number`, `semester YYYY-S`, `invoice_number` unique per `institution_id`, `amount ≥ 0.01`, `expires_at > now`; `201`, `401`, `422`, `429` |
| `GET` | `/api/invoices/{invoice}` | same | tenant-scoped show; cross-tenant hidden as `404 NOT_FOUND` |
| `GET` | `/api/invoices/{invoice}/transactions` | same | ordered ledger; cross-tenant `404` |
| `GET` | `/api/students/{studentNumber}/invoices` | same | paginated 15/page with `meta.current_page/per_page/total` |
| `POST` | `/api/webhooks/payments` | `X-Signature` HMAC + `institution_code` in body + `throttle:api-webhooks` (per IP) | audited + idempotent; `429` on rate limit; see below |
| `GET` | `/up` | — | health `200` |

Invoice tenant rule: `invoice_number` unique per `(institution_id, invoice_number)` (migration + `StoreInvoiceRequest` + `409`-style `422` on race). Any `institution_id` in body is ignored.

## Webhook contract (verified)

- Header `X-Signature`: lowercase hex `HMAC-SHA256(raw_body, institution.webhook_secret)` (the common `sha256=<hex>` prefix form is also accepted, case-insensitive), `hash_equals` constant-time (`WebhookSignatureVerifier`). Rotate with `php artisan institution:webhook-secret <CODE> --rotate`.
- Body JSON requires `institution_code, event_type (payment.success|payment.expired), event_id, invoice_number, gateway_transaction_id, amount`; `occurred_at` optional (absent/empty → `null`; unparseable → `422 occurred_at_invalid`) with a staleness window (default: max 30 days past / 15 min future via `WEBHOOK_OCCURRED_AT_MAX_PAST_DAYS` / `WEBHOOK_OCCURRED_AT_MAX_FUTURE_MINUTES`) → `422 occurred_at_too_old|occurred_at_in_future`. A `payment.success` for an unpaid invoice already past `expires_at` is moved to `expired` and `ignored 200 success_after_expiry` with no transaction row.
- Every delivery inserts one `webhook_receipts` row (`received` → `processed|duplicate|ignored|rejected`) with recursively redacted `payload_redacted` (`webhook_secret/secret/card_number/card_cvv/signature` → `[REDACTED]` at any nesting depth). No secrets/signatures in responses. Retention: `php artisan webhook:prune [--days=N] [--include-failed] [--dry-run]` deletes old terminal receipts (failed kept by default; never touches invoices/transactions).
- Idempotency: `(institution_id, event_id)` lookup → `duplicate 200 event_duplicate`; `(institution_id, gateway_transaction_id)` unique on `payment_transactions` (+ race-safe catch) → `duplicate 200`. Retries are safe.
- Atomic: `lockForUpdate` invoice + state move + optional transaction insert in one `DB::transaction`.
- State machine (`InvoiceStateMachine`): `unpaid + success → paid (+ transaction row)`; `unpaid + expired → expired (no row)`; `paid + expired → ignored already_final`; `expired + success → ignored success_after_expiry`; `paid + success / expired + expired → ignored already_final`. `amount` mismatch on success → `422 amount_mismatch`.
- HTTP mapping: `processed|duplicate|ignored → 200`; malformed JSON → `400 MALFORMED_PAYLOAD`; unknown institution / bad signature → `401`; domain failures → `422` with stable `failure_reason` (`institution_code_missing, institution_unknown, event_type_unknown, event_id_missing, invoice_number_missing, gateway_transaction_missing, amount_invalid, invoice_not_found, amount_mismatch, signature_invalid, occurred_at_invalid, occurred_at_too_old, occurred_at_in_future`).
- Tenant isolation: webhook resolves invoice strictly via `forInstitution(institution)`; `INV-SHARED` in ALPHA does not move BETA.

## Testing

- `tests/Feature/InvoiceTenantIsolationTest.php` (8): missing/unknown Bearer token `401`, plaintext never stored, scoped create `201`, dup-per-tenant `422` vs cross-tenant `201`, show `404` hides cross-tenant, student list `meta.total` scoped, transactions `404` cross-tenant.
- `tests/Feature/PaymentWebhookTest.php` (13): malformed `400` no leak, bad/missing signature `401` audited, success → `paid` + row, replay `event_id` → `duplicate`, reused `gateway_transaction_id` → `duplicate`, expiry → `expired` no row, late success after expiry → `ignored success_after_expiry`, expired-after-paid → `ignored already_final`, amount mismatch `422`, unknown institution `401`, redaction, tenant scoping.
- `tests/Feature/WebhookAuditGapsTest.php` (7): nested redact recursive, `sha256=` prefix accepted, stale/future `occurred_at` `422`, time-expired success ignored with zero money movement, `webhook:prune` keeps/prunes failed correctly, `institution:webhook-secret --rotate` rotates and invalidates the old secret.
- Plus stock `ExampleTest` unit/feature (2), `MockGatewayEventBuilderTest` (3), `MockGatewayCommandTest` (3) + `WebhookQueueMonitoringTest` (6) + `WebhookAuditGapsTest` (7) = 44 total.
- Webhook side effects + ops: `payment.success` commit dispatches `NotifyPaymentSuccessJob` (database queue, tries 3, after-commit) — run `php artisan queue:work`; unexpected processor exceptions mark receipts `failed/internal_error` (`500` for gateway retry); `php artisan webhook:monitor [--institution=CODE] [--failures=N]` shows status counts, recent failures, queue depth, `failed_jobs`; `php artisan webhook:prune [--days=N] [--include-failed] [--dry-run]` handles retention (see `docs/10-local-development.md`).

## Security / tenancy notes

- Invoice auth is per-institution Bearer token (`Authorization: Bearer <api-token>`) via `institution.resolve`; only the SHA-256 hash is stored (`institutions.api_token_hash`). Missing → `401 INSTITUTION_UNRESOLVED`, unknown/revoked → `401 INSTITUTION_UNKNOWN`. Issue/rotate with `php artisan institution:token <CODE> [--rotate]`; gateway webhook HMAC secret (encrypted at rest) via `php artisan institution:webhook-secret <CODE> [--rotate]`; distribute plaintexts out of band. Never trust `institution_id` from client.
- Throttle: invoice routes `throttle:api-invoices` (per token per minute, `API_INVOICE_RATE_LIMIT=60`), webhook `throttle:api-webhooks` (per IP per minute, `API_WEBHOOK_RATE_LIMIT=300`) → `429` on exceed.
- Prod template: `.env.production.example` (`APP_ENV=production`, `APP_DEBUG=false`, pgsql, no secrets). Run `migrate --force` on prod, never `migrate:fresh`.
- Secrets: `.env` ignored, `database/*.sqlite` ignored, `vendor/` + caches ignored. Only demo `demo-secret-*-please-rotate` + `demo-token-*-please-rotate` in seeder (hash only) + test-only `APP_KEY` in `phpunit.xml`; real secrets stay in user-managed `.env`/PG, never committed.
- Contract source of truth: `contracts/openapi.yaml` v1.3.0 (Bearer auth + 429 + queued side effect + `500 failed/internal_error` + `sha256=` tolerance + `occurred_at` window + time-expiry ignore + prune/rotation ops). Proposals in `docs/05/06` remain planning background.

## Docs

- `docs/01-15`, `DECISIONS.md` (final), `AI_NOTES.md`, `contracts/openapi.yaml`, `INDEX.md`, `CLAUDE.md`, `AGENTS.md`.
