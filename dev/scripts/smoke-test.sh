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

# Assert $1 contains substring $2. Pure bash — piping page bodies into
# grep -q dies with SIGPIPE under pipefail when grep exits early.
require_contains() {
    local haystack="$1" needle="$2" label="$3"
    if [[ "$haystack" != *"$needle"* ]]; then
        echo "ERROR: $label: expected to find '$needle' in the response." >&2
        exit 1
    fi
}

echo ">> [1/4] Storefront homepage renders ..."
# First render in developer mode is slow; retry while PHP warms up.
curl -ksS --retry 5 --retry-delay 10 --retry-all-errors --max-time 300 \
    -o /dev/null -f "$BASE_URL/"

echo ">> [2/4] Luma sample-data category page shows a product grid ..."
category_page="$("${CURL[@]}" -f "$BASE_URL/women.html")"
require_contains "$category_page" 'products-grid' 'category page'

echo ">> [3/4] Admin login works (2FA disabled) ..."
login_page="$("${CURL[@]}" -f -L "$BASE_URL/admin/")"
form_key=""
re_js="FORM_KEY = '([^']+)'"
re_input='name="form_key"[^>]*value="([^"]+)"'
if [[ "$login_page" =~ $re_js ]]; then
    form_key="${BASH_REMATCH[1]}"
elif [[ "$login_page" =~ $re_input ]]; then
    form_key="${BASH_REMATCH[1]}"
else
    echo "ERROR: could not extract form_key from the admin login page." >&2
    exit 1
fi
dashboard="$("${CURL[@]}" -f -L \
    --data-urlencode "login[username]=$ADMIN_USER" \
    --data-urlencode "login[password]=$ADMIN_PASSWORD" \
    --data-urlencode "form_key=$form_key" \
    "$BASE_URL/admin/admin/index/index/")"
require_contains "$dashboard" 'Dashboard' 'admin login'

echo ">> [4/4] Tradeaze admin configuration section renders ..."
# Renders the module's system.xml section: source models, the encrypted
# token field, and the Create Webhooks button block all instantiate here.
config_page="$("${CURL[@]}" -f -L \
    "$BASE_URL/admin/admin/system_config/edit/section/tradeaze_api/")"
require_contains "$config_page" 'API Token' 'Tradeaze config section'
require_contains "$config_page" 'Create Webhooks' 'Tradeaze config section'

echo ">> Smoke test passed: storefront and admin (incl. Tradeaze config) OK."
