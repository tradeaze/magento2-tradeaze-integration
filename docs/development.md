# Development

This repo ships a self-contained development environment for working on the
module against a real Magento 2.4.8 Open Source store, plus CI that runs the
unit tests and coding-standard checks on every push and pull request.

Everything installs from the [Mage-OS composer mirror](https://mage-os.org/distribution/#mirror)
(`https://mirror.mage-os.org`), so **no `repo.magento.com` auth keys are needed**.
(Note: `repo.mage-os.org` is the separate Mage-OS *distribution* repo — the
mirror at `mirror.mage-os.org` is the one that serves verbatim `magento/*`
Open Source packages.)

## Local dev store — one command

Prerequisites: [ddev](https://ddev.readthedocs.io/en/stable/users/install/)
(preferred) **or** Docker with the compose plugin. Give Docker at least
~6 GB RAM — OpenSearch plus Magento need it.

```bash
git clone git@github.com:tradeaze/magento2-tradeaze-integration.git
cd magento2-tradeaze-integration
./dev/setup.sh
```

That is the whole setup. The script auto-detects ddev (falling back to
`docker compose`; force one with `--ddev` / `--compose`) and:

1. Starts the stack: PHP 8.3 + nginx/Apache, MariaDB 10.6, OpenSearch 2.19.
2. Installs **Magento 2.4.8 Open Source** into `dev/magento/` (git-ignored)
   from the Mage-OS mirror.
3. Adds this repo as a **composer path repository** and requires
   `tradeaze/magento2-tradeaze-integration:@dev` — the module is *symlinked*
   into the store, so your edits in `src/` are live immediately.
4. Deploys the **Luma sample data** (category pages with product grids).
5. Runs `bin/magento inventory-geonames:import GB` — required: the module's
   `ValidateGeoNames` backend model refuses to enable the integration until
   GB GeoNames data exists.
6. Enables `Tradeaze_ApiIntegration`, runs `setup:upgrade`, switches to
   developer mode, disables admin 2FA, enables the carrier, and applies your
   API settings from `dev/.env`.

When it finishes it prints the storefront URL and admin credentials
(`/admin`, `admin` / `Admin123!`).

Every step is idempotent — re-running `./dev/setup.sh` on an existing store
is safe and fast.

## Configuring against the Tradeaze staging API

Plugin credentials live in `dev/.env`, which is **git-ignored** — never
commit real tokens. The first `./dev/setup.sh` run creates it from
[`dev/.env.example`](../dev/.env.example):

```bash
# dev/.env
TRADEAZE_API_MODE=test      # 'test' = staging API (stage-api.tradeaze.app)
TRADEAZE_API_TOKEN=<your staging token>
```

Apply changes to a running store with:

```bash
ddev exec bash dev/scripts/configure-tradeaze.sh
# docker compose fallback:
docker compose -f dev/docker-compose.yml exec -T -u www-data web \
    bash /var/www/html/dev/scripts/configure-tradeaze.sh
```

The token is stored through Magento's encrypted config backend, and the
`test` mode points all module API calls at `https://stage-api.tradeaze.app`.

## Day-to-day workflow

The module is symlinked, so PHP changes in `src/` apply immediately (the
store runs in developer mode). Useful commands:

| Task | ddev | compose fallback |
|---|---|---|
| Magento CLI | `ddev exec --dir /var/www/html/dev/magento bin/magento <cmd>` | `docker compose -f dev/docker-compose.yml exec -u www-data web php /var/www/html/dev/magento/bin/magento <cmd>` |
| Flush cache | ... `cache:flush` | ... `cache:flush` |
| Run crons (e.g. failed-order retry) | ... `cron:run` | ... `cron:run` |
| Logs | `ddev logs` / `var/log` in `dev/magento` | `docker compose -f dev/docker-compose.yml logs` |
| Stop / destroy | `ddev stop` / `ddev delete -O` | `docker compose -f dev/docker-compose.yml down [-v]` |

After changing anything under `src/etc/`, `src/Setup/`, or adding classes
with DI preferences, run `setup:upgrade` + `cache:flush` in the store.

To rebuild the store from scratch: `ddev delete -Oy` (or
`docker compose -f dev/docker-compose.yml down -v`), then
`rm -rf dev/magento` and re-run `./dev/setup.sh`.

## Unit tests and coding standards

The repo's own `composer.json` pulls the Magento framework packages needed
by the unit tests as `require-dev` dependencies from the Mage-OS mirror —
no store required:

```bash
composer update              # in the repo root
composer test:unit           # PHPUnit, src/Test/Unit
composer test:phpcs          # phpcs, Magento2 standard (phpcs.xml)
```

`config.platform.php` is pinned to `8.3.0` so dependency resolution matches
the Magento 2.4.8 / PHP 8.3 line regardless of your local PHP.

## CI

[`.github/workflows/ci.yml`](../.github/workflows/ci.yml) runs on every push
and pull request:

- **unit-tests** — PHP 8.3, `composer update` (Magento packages from the
  Mage-OS mirror, cached), `vendor/bin/phpunit -c src/Test/Unit/phpunit.xml`.
- **phpcs** — `composer validate --strict` plus `vendor/bin/phpcs` against
  the repo's `phpcs.xml` (Magento2 standard).

No full Magento installation happens in CI; the jobs only install composer
packages, which keeps them to a few minutes with a warm cache.

## Repo hygiene for merchants

Merchants install this repo via composer, so the whole repo would land in
their `vendor/` directory. [`.gitattributes`](../.gitattributes) marks the
dev environment (`.ddev/`, `dev/`), CI (`.github/`), docs, and tests as
`export-ignore`, so composer dist archives (and GitHub source archives)
contain only what the module needs at runtime. Note: installs that composer
performs from *source* (git clone) still contain everything; dist installs
(the default for tagged releases) are clean.

## Verification status

What has actually been run and verified, and where (last updated 2026-07-28):

- **Verified in GitHub Actions (real runs on this repo):** both CI jobs —
  the full PHPUnit suite (76 tests) and phpcs — including composer
  dependency resolution of the Magento 2.4.8-line packages from the
  Mage-OS mirror. With a warm composer cache the whole workflow completes
  in well under a minute. Note: the Mage-OS repo satisfies `magento/*`
  requirements via its replacing `mage-os/*` packages (identical
  namespaces and code), which is why `composer show` lists
  `mage-os/framework` rather than `magento/framework`.
- **Verified by the "Dev store smoke test" workflow:** the docker-compose
  path of the store setup. `.github/workflows/dev-store-smoke.yml` runs
  `./dev/setup.sh --compose` from a clean checkout on a GitHub runner,
  asserts the storefront and a Luma category product grid render, checks
  the module is enabled, and re-runs the setup to prove idempotence. It
  runs on demand (`workflow_dispatch`), weekly, and on PRs that touch
  `dev/**` or `.ddev/**`.
- **Scripted but not covered by automation:** the ddev wrapper
  (`.ddev/` config plus the ddev branch of `dev/setup.sh`). It drives the
  exact same in-container install script the smoke-tested compose path
  uses; only the ddev-specific glue (config.yaml, the OpenSearch service
  file) is untested in CI. If a first run trips on anything, every step in
  `dev/scripts/install-magento.sh` is idempotent — fix and re-run
  `./dev/setup.sh`.

Known environment requirements for the store: Docker with ~6 GB RAM
(OpenSearch alone wants its 512 MB heap plus overhead; Magento + sample
data install peaks ~2 GB PHP memory), ~10 GB disk for images, packages,
and the database. On Linux hosts OpenSearch may also need
`sudo sysctl -w vm.max_map_count=262144` (Docker Desktop on macOS/Windows
sets this inside its VM already).
