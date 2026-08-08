# Development-only tooling

**Nothing in this directory (or in `.ddev/`) is part of the plugin.**

The plugin itself is `src/` plus the root `composer.json` — that is all a
merchant's `composer require` delivers (enforced by the root
`.gitattributes` `export-ignore` rules; verify with `git archive HEAD | tar -t`).

This directory exists so plugin developers can run a real Magento 2.4.8
store to develop and test against:

| Path | Purpose |
|---|---|
| `setup.sh` | One-command entrypoint (ddev preferred, docker compose fallback) |
| `scripts/install-magento.sh` | In-container install: Magento + sample data + this module |
| `scripts/configure-tradeaze.sh` | Re-apply plugin config from `dev/.env` |
| `scripts/smoke-test.sh` | Storefront + admin HTTP checks (used by CI, runnable locally) |
| `e2e/` | Playwright browser tests: guest checkout + admin config (used by CI, runnable locally) |
| `Dockerfile`, `docker-compose.yml` | The docker compose fallback stack |
| `.env.example` | Template for git-ignored `dev/.env` (staging API token etc.) |
| `magento/` | The installed dev store — git-ignored, never committed |

See [docs/development.md](../docs/development.md) for the full workflow.
