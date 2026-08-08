#!/usr/bin/env bash
#
# HTTP smoke test for the dev store: verifies the customer storefront AND
# the admin backend (login + the Tradeaze configuration section) work.
# Used by .github/workflows/dev-store-smoke.yml, and runnable locally
# against either dev environment:
#
#   ./dev/scripts/smoke-test.sh http://localhost:8080            # compose
#   ./dev/scripts/smoke-test.sh https://tradeaze-magento2.ddev.site   # ddev

set -euo pipefail

BASE_URL="${1:-http://localhost:8080}"
BASE_URL="${BASE_URL%/}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-Admin123!}"

COOKIES="$(mktemp)"
trap 'rm -f "$COOKIES"' EXIT
CURL=(curl -ksS --max-time 300 -b "$COOKIES" -c "$COOKIES")

echo ">> [1/4] Storefront homepage renders ..."
# First render in developer mode is slow; retry while PHP warms up.
curl -ksS --retry 5 --retry-delay 10 --retry-all-errors --max-time 300 \
    -o /dev/null -f "$BASE_URL/"

echo ">> [2/4] Luma sample-data category page shows a product grid ..."
"${CURL[@]}" -f "$BASE_URL/women.html" | grep -q 'products-grid'

echo ">> [3/4] Admin login works (2FA disabled) ..."
login_page="$("${CURL[@]}" -f -L "$BASE_URL/admin/")"
form_key="$(printf '%s' "$login_page" | grep -oE "FORM_KEY = '[^']+'" | head -1 | cut -d"'" -f2 || true)"
if [ -z "$form_key" ]; then
    form_key="$(printf '%s' "$login_page" \
        | grep -oE 'name="form_key"[^>]*value="[^"]+"' | head -1 \
        | grep -oE 'value="[^"]+"' | cut -d'"' -f2)"
fi
dashboard="$("${CURL[@]}" -f -L \
    --data-urlencode "login[username]=$ADMIN_USER" \
    --data-urlencode "login[password]=$ADMIN_PASSWORD" \
    --data-urlencode "form_key=$form_key" \
    "$BASE_URL/admin/admin/index/index/")"
if ! printf '%s' "$dashboard" | grep -qi 'dashboard'; then
    echo "ERROR: admin login failed (no dashboard after sign-in)." >&2
    printf '%s' "$dashboard" | grep -oiE 'message-error[^<]*<[^>]*>[^<]*' >&2 || true
    exit 1
fi

echo ">> [4/4] Tradeaze admin configuration section renders ..."
# Renders the module's system.xml section: source models, the encrypted
# token field, and the Create Webhooks button block all instantiate here.
config_page="$("${CURL[@]}" -f -L \
    "$BASE_URL/admin/admin/system_config/edit/section/tradeaze_api/")"
printf '%s' "$config_page" | grep -q 'API Token'
printf '%s' "$config_page" | grep -qi 'Create Webhooks'

echo ">> Smoke test passed: storefront and admin (incl. Tradeaze config) OK."
