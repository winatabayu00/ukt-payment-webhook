#!/usr/bin/env bash
#
# Demo: invoice create + payment webhook (success / expired) end-to-end.
#
# Spins up the app against a throwaway sqlite DB, serves it, creates two
# invoices via the API, then drives the success + expired webhook paths with
# the mock gateway sender (incl. an idempotent replay). Asserts every step.
#
# Usage:
#   bash scripts/demo.sh                 # full demo, defaults below
#   DEMO_PORT=18099 DEMO_KEEP=1 bash scripts/demo.sh   # keep server+DB for poking
#
# Env overrides:
#   DEMO_PORT   HTTP port for `php artisan serve`   (default 18099)
#   DEMO_DB     sqlite file to use                   (default: mktemp throwaway)
#   DEMO_FRESH  1 = migrate:fresh --seed first       (default 1)
#   DEMO_KEEP   1 = leave server running + print DB  (default 0)
#
# Deps: php, composer-installed vendor/, curl. No jq needed.
set -euo pipefail

PORT="${DEMO_PORT:-18099}"
FRESH="${DEMO_FRESH:-1}"
KEEP="${DEMO_KEEP:-0}"
BASE="http://127.0.0.1:${PORT}"

if [[ -z "${DEMO_DB:-}" ]]; then
  # Portable template: trailing Xs so mktemp(1) substitutes on both GNU and BSD/macOS.
  DEMO_DB="$(mktemp /tmp/ukt-demo-XXXXXXXXXX.sqlite)"
  DEMO_DB_CREATED=1
else
  DEMO_DB_CREATED=0
fi
# Hermetic sqlite demo: force the sqlite driver regardless of host-exported
# DB_CONNECTION/DB_* (host shells may export pgsql credentials for daily use).
export DB_CONNECTION=sqlite
export DB_DATABASE="$DEMO_DB"

pass() { printf '  ✅ %s\n' "$1"; }
fail() { printf '  ❌ %s\n' "$1" >&2; exit 1; }

need() { command -v "$1" >/dev/null 2>&1 || fail "missing required command: $1"; }
need php; need curl

[[ -d vendor ]] || fail "vendor/ missing — run: composer install"
[[ -f .env ]]   || fail ".env missing — run: cp .env.example .env && php artisan key:generate"

echo "== UKT payment webhook demo =="
echo "   DB:   $DB_DATABASE"
echo "   URL:  $BASE"

if [[ "$FRESH" == "1" ]]; then
  touch "$DB_DATABASE"
  echo "-- migrate:fresh --seed"
  php artisan migrate:fresh --force --seed >/tmp/ukt-demo-migrate.log 2>&1 \
    || { tail -n 20 /tmp/ukt-demo-migrate.log >&2; fail "migrate:fresh --seed failed"; }
  pass "database seeded (CAMPUS-ALPHA/BETA/GAMMA)"
fi

echo "-- serve on :$PORT"
php artisan serve --port="$PORT" >/tmp/ukt-demo-serve.log 2>&1 &
SERVE_PID=$!
cleanup() {
  if [[ "$KEEP" == "1" ]]; then
    echo "-- DEMO_KEEP=1: server still running (pid $SERVE_PID), DB at $DB_DATABASE"
    echo "   stop it with: kill $SERVE_PID"
  else
    kill "$SERVE_PID" 2>/dev/null || true
    [[ "${DEMO_DB_CREATED:-0}" == "1" ]] && rm -f "$DB_DATABASE"
  fi
}
trap cleanup EXIT

echo "-- waiting for /up"
for _ in $(seq 1 60); do
  sleep 1
  if [[ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/up" || true)" == "200" ]]; then
    break
  fi
  if ! kill -0 "$SERVE_PID" 2>/dev/null; then
    tail -n 20 /tmp/ukt-demo-serve.log >&2; fail "server died on startup"
  fi
  if [[ "${_}" == "60" ]]; then
    tail -n 20 /tmp/ukt-demo-serve.log >&2; fail "server never became ready"
  fi
done
pass "GET /up -> 200"

EXPIRES_AT="$(php -r 'echo date("c", time() + 86400 * 30);')"
# Seeded demo plaintext token for CAMPUS-ALPHA (only its SHA-256 is stored).
DEMO_TOKEN="${DEMO_TOKEN:-demo-token-alpha-please-rotate}"
auth_header() { printf 'Authorization: Bearer %s' "$DEMO_TOKEN"; }
create_invoice() { # student semester number amount
  curl -s -w '\n%{http_code}' -X POST "$BASE/api/invoices" \
    -H 'Content-Type: application/json' -H "$(auth_header)" \
    -d "{\"student_number\":\"$1\",\"semester\":\"$2\",\"invoice_number\":\"$3\",\"amount\":\"$4\",\"expires_at\":\"$EXPIRES_AT\"}"
}

echo "-- create INV-DEMO-001 (will be paid)"
OUT="$(create_invoice MHS-DEMO-001 2026-1 INV-DEMO-001 1500000.00)"
echo "$OUT" | grep -q '"status":"unpaid"' || fail "invoice create failed: $OUT"
[[ "$(echo "$OUT" | tail -n 1)" == "201" ]] || fail "expected 201, got: $OUT"
pass "POST /api/invoices -> 201 unpaid"

echo "-- create INV-DEMO-002 (will expire)"
OUT="$(create_invoice MHS-DEMO-002 2026-1 INV-DEMO-002 1500000.00)"
[[ "$(echo "$OUT" | tail -n 1)" == "201" ]] || fail "expected 201, got: $OUT"
pass "POST /api/invoices -> 201 unpaid"

echo "-- payment.success webhook for INV-DEMO-001"
OUT="$(php artisan mock:gateway-event --url="$BASE/api/webhooks/payments" \
  --invoice=INV-DEMO-001 --event-id=evt-demo-1 --gateway-id=gw-demo-1 2>&1 | tail -n 2)"
echo "   $OUT" | tr '\n' ' '; echo
echo "$OUT" | grep -q '"processing_status":"processed"' || fail "success webhook failed: $OUT"
pass "success -> 200 processed"

echo "-- replay evt-demo-1 (idempotency)"
OUT="$(php artisan mock:gateway-event --url="$BASE/api/webhooks/payments" \
  --invoice=INV-DEMO-001 --event-id=evt-demo-1 --gateway-id=gw-demo-1-other 2>&1 | tail -n 1)"
echo "$OUT" | grep -q '"processing_status":"duplicate"' || fail "replay not deduped: $OUT"
echo "$OUT" | grep -q 'event_duplicate' || fail "wrong failure_reason: $OUT"
pass "replay -> 200 duplicate/event_duplicate"

echo "-- payment.expired webhook for INV-DEMO-002"
OUT="$(php artisan mock:gateway-event --url="$BASE/api/webhooks/payments" --event=expired \
  --invoice=INV-DEMO-002 --event-id=evt-demo-2 --gateway-id=gw-demo-2 2>&1 | tail -n 1)"
echo "$OUT" | grep -q '"processing_status":"processed"' || fail "expired webhook failed: $OUT"
pass "expired -> 200 processed"

echo "-- verify final invoice statuses"
OUT="$(curl -s "$BASE/api/invoices/1" -H "$(auth_header)")"
echo "$OUT" | grep -q '"status":"paid"' || fail "INV-DEMO-001 not paid: $OUT"
pass "INV-DEMO-001 status=paid"
OUT="$(curl -s "$BASE/api/invoices/2" -H "$(auth_header)")"
echo "$OUT" | grep -q '"status":"expired"' || fail "INV-DEMO-002 not expired: $OUT"
pass "INV-DEMO-002 status=expired"

echo
echo "🎉 demo green: invoice create + success/expired webhooks + idempotent replay"
