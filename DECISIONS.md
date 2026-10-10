# Technical Decisions (Final — Phase 1 verified + prod hardening)

Runtime: PHP 8.3.30, Laravel 13.35.0, PHPUnit 12.5.38. Suite `OK (29 tests, 111 assertions)` on sqlite `:memory:`. pgsql is user-managed via `.env.example` placeholders; prod template in `.env.production.example`.

## 1. Modular monolith Laravel — FINAL
**Options:** (A) modular monolith, (B) microservices, (C) unstructured controllers.
**Chosen:** A. One Laravel app; thin controllers (`Api/InvoiceController`, `Api/StudentInvoiceController`, `Api/PaymentWebhookController`), `StoreInvoiceRequest` validation, services (`InvoiceService`, `PaymentWebhookProcessor`, `InvoiceStateMachine`, `WebhookSignatureVerifier`), Eloquent models, `institution.resolve` middleware.
**Why:** Matches 10–14h scope; single deploy/test; no ops need for microservices.
**Verified:** `route:list` 9 routes (4 tenant invoice + 1 webhook + `/up`); invoice group carries `throttle:api-invoices` (per token/minute, `config/api.php` → `API_INVOICE_RATE_LIMIT=60`), webhook carries `throttle:api-webhooks` (per IP/minute, `API_WEBHOOK_RATE_LIMIT=300`); full suite green.

## 2. Explicit tenant isolation — FINAL
**Options:** (A) trusted tenant context + `institution_id` filters, (B) client-supplied `institution_id`, (C) DB per tenant.
**Chosen:** A. `ResolveInstitution` resolves the tenant from a per-institution API token in the `Authorization: Bearer <token>` header (missing → `401 INSTITUTION_UNRESOLVED`, unknown/revoked → `401 INSTITUTION_UNKNOWN`); only the SHA-256 hash is stored (`institutions.api_token_hash`, unique, nullable for pre-existing rows), compared with `hash_equals`. Tokens are issued/rotated via `php artisan institution:token <CODE> [--rotate]` (plaintext printed once, distributed out of band). `Invoice::scopeForInstitution` / `forInstitution`; `show/transactions` hide cross-tenant as `404 NOT_FOUND`; webhook resolves invoice strictly by payload `institution_code` (`forInstitution`); `unique(institution_id, invoice_number)` in migration + `Rule::unique->where(institution_id)` + 409-style `422` on race.
**Why:** Single-DB brief requirement; prevents arbitrary tenant selection.
**Verified:** `InvoiceTenantIsolationTest` (8: missing/unknown Bearer `401`, plaintext never stored, scoped create, dup rules, cross-tenant `404`s) + webhook tenant test (`INV-SHARED` ALPHA paid, BETA stays unpaid).

## 3. Verified atomic webhook — FINAL
**Options:** (A) verify → audit → transact, (B) mutate before verify, (C) split writes.
**Chosen:** A. `PaymentWebhookController` rejects malformed JSON `400 MALFORMED_PAYLOAD`; `PaymentWebhookProcessor` always writes a `webhook_receipts` row first (`received`), verifies `WebhookSignatureVerifier` (`hash_hmac sha256`, `hash_equals`), then `DB::transaction` with `lockForUpdate`, exact amount check on success, state move + optional `payment_transactions` insert. Rejections keep `processed_at` + stable `failure_reason`; responses never leak secrets/signatures.
**Verified:** bad/missing signature `401 signature_invalid` audited, invoice untouched, zero rows; `amount_mismatch 422`.

## 4. Idempotency + late/out-of-order policy — FINAL
**Options:** (A) unique identity + DB constraints + idempotent handling, (B) app check only, (C) every delivery new.
**Chosen:** A. `event_id` required (`event_id_missing 422`); `(institution_id, event_id)` lookup over `processed|duplicate|ignored` → `duplicate 200 event_duplicate`; `unique(institution_id, gateway_transaction_id)` + race catch → `duplicate 200`. `payment.expired` creates no financial row (audit only). Every delivery (incl. duplicates) gets its own receipt row; receipts have plain `index(institution_id, event_id)` (not unique) — comment in `000004` migration. State rules: `unpaid+success→paid`, `unpaid+expired→expired`, `expired+success→ignored success_after_expiry`, `paid+expired→ignored already_final`, repeats → `ignored already_final`.
**Verified:** 13 webhook tests incl. replay, reused gateway id, late success, expired-after-paid, redaction (`webhook_secret/card_number/signature → [REDACTED]`).

## 5. Relational DB with domain constraints, sqlite-verified — FINAL
**Options:** (A) PostgreSQL, (B) MySQL, (C) non-relational.
**Chosen:** Relational with sqlite as verified local/test driver (`DB_CONNECTION=sqlite`, file ignored); pgsql placeholders in `.env.example` (`DB_HOST=127.0.0.1:5432`, `DB_DATABASE=ukt_payment`, user-managed creds). C rejected (needs FK/unique/tx).
**Migrations:** `000001 institutions(code unique, webhook_secret encrypted cast)`; `000002 invoices(unique institution_id+invoice_number, indexes student/semester/status)`; `000003 payment_transactions(unique institution_id+gateway_transaction_id)`; `000004 webhook_receipts(index institution_id+event_id, redacted JSON, received/processed_at)`; `000005 institutions.api_token_hash (nullable unique sha256, backfilled for seeded demo tokens)`. Seed 3 demo institutions (`CAMPUS-ALPHA/BETA/GAMMA`) with demo Bearer tokens `demo-token-{alpha,beta,gamma}-please-rotate` (hash only stored; rotate in real envs via `institution:token`).
**Verified:** `migrate:fresh --force --seed` 7 DONE + 3 institutions; suite green on `:memory:`.

## 6. Production hardening (auth, throttle, prod config) — FINAL
**Options:** (A) per-institution Bearer API token + throttle + prod env template, (B) keep demo header + external gateway auth, (C) full OAuth/IdP now.
**Chosen:** A. Real per-institution token auth on all invoice endpoints; HMAC webhook verification + replay protection untouched; per-token invoice throttle + per-IP webhook throttle; `.env.production.example` (`APP_ENV=production`, `APP_DEBUG=false`, pgsql placeholders only, no secrets) + rotation command + docs/contract updates.
**Why:** Closes the demo-credential gap with minimal scope (no business-logic change, no new infra); OAuth/IdP deferred as out-of-scope per task.
**Verified:** suite `OK (29 tests, 111 assertions)`; manual `401` without credential confirmed; `429` limiters registered in `route:list`; prod template contains no dev defaults and no secrets.
