#!/usr/bin/env bash
#
# Applies Tradeaze module configuration to the local dev store from
# dev/.env (git-ignored). Safe to re-run after editing dev/.env:
#
#   ddev exec bash dev/scripts/configure-tradeaze.sh
#   # or (docker compose fallback):
#   docker compose -f dev/docker-compose.yml exec -T -u www-data web \
#       bash /var/www/html/dev/scripts/configure-tradeaze.sh

set -euo pipefail

REPO_ROOT="${REPO_ROOT:-/var/www/html}"
MAGENTO_DIR="$REPO_ROOT/dev/magento"

if [ -f "$REPO_ROOT/dev/.env" ]; then
    set -a
    # shellcheck disable=SC1091
    . "$REPO_ROOT/dev/.env"
    set +a
fi

cd "$MAGENTO_DIR"

echo ">> Configuring Tradeaze module ..."

# Shipping carrier
bin/magento config:set carriers/tradeaze/active 1
bin/magento config:set carriers/tradeaze/title "Tradeaze Delivery"
bin/magento config:set carriers/tradeaze/sallowspecific 1
bin/magento config:set carriers/tradeaze/specificcountry GB

# API mode: 'test' targets the Tradeaze staging API
# (https://stage-api.tradeaze.app), 'live' targets production.
bin/magento config:set tradeaze_api/general/api_mode "${TRADEAZE_API_MODE:-test}"

if [ -n "${TRADEAZE_API_TOKEN:-}" ]; then
    # Encrypted via the field's backend model.
    bin/magento config:set tradeaze_api/general/api_token "$TRADEAZE_API_TOKEN"
    echo ">> API token applied from dev/.env."
else
    echo ">> NOTE: TRADEAZE_API_TOKEN is empty in dev/.env — the module treats"
    echo ">>       the integration as disabled until a token is configured."
fi

# The ValidateGeoNames backend model runs here and requires GB GeoNames
# data (imported by install-magento.sh) before it allows enabling.
bin/magento config:set tradeaze_api/general/enabled 1

bin/magento cache:flush >/dev/null
echo ">> Tradeaze configuration applied."
