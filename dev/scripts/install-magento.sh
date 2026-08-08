#!/usr/bin/env bash
#
# Installs and configures the Magento 2.4.8 dev store inside the web
# container (ddev or docker compose — invoked by dev/setup.sh, but safe to
# re-run directly at any time; every step is idempotent).

set -euo pipefail

REPO_ROOT="${REPO_ROOT:-/var/www/html}"
MAGENTO_DIR="$REPO_ROOT/dev/magento"
MAGENTO_VERSION="${MAGENTO_VERSION:-2.4.8}"
# mirror.mage-os.org serves verbatim magento/* Open Source packages
# without auth keys (repo.mage-os.org is the separate Mage-OS
# distribution and does not provide magento/project-community-edition).
MAGE_OS_REPO="${MAGE_OS_REPO:-https://mirror.mage-os.org}"

# Load git-ignored local settings (Tradeaze API token etc.).
if [ -f "$REPO_ROOT/dev/.env" ]; then
    set -a
    # shellcheck disable=SC1091
    . "$REPO_ROOT/dev/.env"
    set +a
fi

# Service defaults match both ddev (db/db/db) and dev/docker-compose.yml.
DB_HOST="${DB_HOST:-db}"
DB_NAME="${DB_NAME:-db}"
DB_USER="${DB_USER:-db}"
DB_PASSWORD="${DB_PASSWORD:-db}"
OPENSEARCH_HOST="${OPENSEARCH_HOST:-opensearch}"
OPENSEARCH_PORT="${OPENSEARCH_PORT:-9200}"
BASE_URL="${BASE_URL:-${DDEV_PRIMARY_URL:-http://localhost:8080}}"
BASE_URL="${BASE_URL%/}/"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-Admin123!}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@example.com}"

echo ">> Magento root: $MAGENTO_DIR (version $MAGENTO_VERSION, base URL $BASE_URL)"

# --- 1. Create the Magento project skeleton (Mage-OS mirror, no auth keys) ---
if [ ! -f "$MAGENTO_DIR/composer.json" ]; then
    echo ">> Creating Magento project skeleton from $MAGE_OS_REPO ..."
    # create-project needs an empty target; dev/magento/pub may already exist
    # (web server docroot), so build the skeleton in a temp dir and copy over.
    SKELETON="$(mktemp -d)"
    rmdir "$SKELETON"
    composer create-project --repository-url="$MAGE_OS_REPO" --no-install \
        "magento/project-community-edition:$MAGENTO_VERSION" "$SKELETON"
    mkdir -p "$MAGENTO_DIR"
    cp -a "$SKELETON/." "$MAGENTO_DIR/"
    rm -rf "$SKELETON"
fi

cd "$MAGENTO_DIR"

# --- 2. Point composer at the Mage-OS mirror + this repo (symlinked) --------
echo ">> Configuring composer repositories (Mage-OS mirror + path repo) ..."
php -r '
    $file = "composer.json";
    $json = json_decode(file_get_contents($file), true);
    $json["repositories"] = [
        "mage-os-mirror" => ["type" => "composer", "url" => getenv("MAGE_OS_REPO") ?: "https://mirror.mage-os.org"],
        "tradeaze" => ["type" => "path", "url" => "../../", "options" => ["symlink" => true]],
    ];
    $json["minimum-stability"] = "dev";
    $json["prefer-stable"] = true;
    file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
'

if [ ! -f vendor/autoload.php ]; then
    echo ">> Installing Magento dependencies (this takes a while) ..."
    composer install --no-interaction --no-progress
fi

# --- 3. Install this module from the repo root via the path repository ------
if ! grep -q '"tradeaze/magento2-tradeaze-integration"' composer.json; then
    echo ">> Requiring tradeaze/magento2-tradeaze-integration (symlinked) ..."
    composer require --no-interaction "tradeaze/magento2-tradeaze-integration:@dev"
fi

# --- 4. Install Magento -----------------------------------------------------
if [ ! -f app/etc/env.php ]; then
    echo ">> Running setup:install ..."
    bin/magento setup:install \
        --base-url="$BASE_URL" \
        --db-host="$DB_HOST" \
        --db-name="$DB_NAME" \
        --db-user="$DB_USER" \
        --db-password="$DB_PASSWORD" \
        --search-engine=opensearch \
        --opensearch-host="$OPENSEARCH_HOST" \
        --opensearch-port="$OPENSEARCH_PORT" \
        --opensearch-index-prefix=magento2 \
        --admin-firstname=Admin \
        --admin-lastname=Local \
        --admin-email="$ADMIN_EMAIL" \
        --admin-user="$ADMIN_USER" \
        --admin-password="$ADMIN_PASSWORD" \
        --language=en_GB \
        --currency=GBP \
        --timezone=Europe/London \
        --use-rewrites=1 \
        --backend-frontname=admin
fi

# --- 5. Luma sample data (category pages with product grids) ----------------
if ! grep -q '"magento/module-catalog-sample-data"' composer.json; then
    echo ">> Deploying Luma sample data (via Mage-OS mirror) ..."
    bin/magento sampledata:deploy
fi

# setup:install above already enables all modules present at install time;
# this covers re-runs where the module was required afterwards.
bin/magento module:enable Tradeaze_ApiIntegration || true

echo ">> Running setup:upgrade ..."
bin/magento setup:upgrade

# --- 6. Dev-mode defaults ---------------------------------------------------
if ! bin/magento deploy:mode:show | grep -q developer; then
    echo ">> Switching to developer mode ..."
    bin/magento deploy:mode:set developer
fi
# 2FA gets in the way of local admin logins.
bin/magento module:disable Magento_AdminAdobeImsTwoFactorAuth || true
bin/magento module:disable Magento_TwoFactorAuth || true

# --- 7. GB GeoNames data ----------------------------------------------------
# The module's ValidateGeoNames backend model refuses to enable the
# integration until GB GeoNames data has been imported.
echo ">> Importing GB GeoNames data ..."
bin/magento inventory-geonames:import GB

# --- 8. Configure the Tradeaze integration ----------------------------------
"$REPO_ROOT/dev/scripts/configure-tradeaze.sh"

# --- 9. Finish up -----------------------------------------------------------
echo ">> Reindexing and flushing caches ..."
bin/magento indexer:reindex
bin/magento cache:flush

echo ">> Magento install complete."
