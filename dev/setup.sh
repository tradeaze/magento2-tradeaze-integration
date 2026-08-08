#!/usr/bin/env bash
#
# One-command local dev store for the Tradeaze Magento 2 module.
#
#   ./dev/setup.sh            # auto-detect: ddev (preferred) or docker compose
#   ./dev/setup.sh --ddev     # force ddev
#   ./dev/setup.sh --compose  # force docker compose fallback
#
# Installs Magento 2.4.8 Open Source (Mage-OS composer mirror, no auth keys)
# with Luma sample data into dev/magento/ (git-ignored), symlinks this module
# in via a Composer path repository, imports GB GeoNames data, and enables the
# integration. See docs/development.md for the full workflow.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

MODE="${1:-auto}"
MODE="${MODE#--}"

if [ ! -f dev/.env ]; then
    cp dev/.env.example dev/.env
    echo ">> Created dev/.env from dev/.env.example (git-ignored)."
    echo ">> Add your Tradeaze staging API token to dev/.env, then re-run"
    echo ">> ./dev/setup.sh (or dev/scripts/configure-tradeaze.sh) to apply it."
fi

# Pre-create directories that must exist before the containers start:
# the web docroot, and the app/code mount target for this module. If
# Docker creates the mount path itself it does so as root, leaving app/
# unwritable for the in-container web user.
mkdir -p dev/magento/pub dev/magento/app/code/Tradeaze/ApiIntegration

have() { command -v "$1" >/dev/null 2>&1; }

use_ddev() {
    echo ">> Using ddev."
    ddev start -y

    echo ">> Running Magento install inside the web container..."
    ddev exec bash dev/scripts/install-magento.sh

    echo ""
    echo "=============================================================="
    echo " Done. Storefront:  $(ddev describe -j 2>/dev/null | grep -o '"primary_url": *"[^"]*"' | head -1 | cut -d'"' -f4 || echo 'https://tradeaze-magento2.ddev.site')"
    echo " Admin:             /admin  (user: admin, password: Admin123!)"
    echo " Magento CLI:       ddev exec --dir /var/www/html/dev/magento bin/magento <cmd>"
    echo "=============================================================="
}

use_compose() {
    echo ">> Using docker compose fallback."
    if ! docker info >/dev/null 2>&1; then
        echo "ERROR: Docker daemon not reachable. Start Docker and re-run." >&2
        exit 1
    fi

    # Build the web image with www-data matching the host UID so the
    # bind-mounted repo stays writable on both sides.
    HOST_UID="$(id -u)"
    export HOST_UID

    docker compose -f dev/docker-compose.yml up -d --build --wait

    echo ">> Running Magento install inside the web container..."
    docker compose -f dev/docker-compose.yml exec -T -u www-data web \
        bash /var/www/html/dev/scripts/install-magento.sh

    echo ""
    echo "=============================================================="
    echo " Done. Storefront:  http://localhost:8080/"
    echo " Admin:             http://localhost:8080/admin  (admin / Admin123!)"
    echo " Magento CLI:       docker compose -f dev/docker-compose.yml exec -u www-data web \\"
    echo "                      php /var/www/html/dev/magento/bin/magento <cmd>"
    echo "=============================================================="
}

case "$MODE" in
    ddev)
        have ddev || { echo "ERROR: ddev not found. Install it: https://ddev.readthedocs.io/en/stable/users/install/" >&2; exit 1; }
        use_ddev
        ;;
    compose)
        docker compose version >/dev/null 2>&1 || { echo "ERROR: docker compose not found." >&2; exit 1; }
        use_compose
        ;;
    auto)
        if have ddev; then
            use_ddev
        elif docker compose version >/dev/null 2>&1; then
            use_compose
        else
            echo "ERROR: neither ddev nor docker compose found." >&2
            echo "Install ddev (preferred): https://ddev.readthedocs.io/en/stable/users/install/" >&2
            exit 1
        fi
        ;;
    *)
        echo "Usage: ./dev/setup.sh [--ddev|--compose]" >&2
        exit 1
        ;;
esac
