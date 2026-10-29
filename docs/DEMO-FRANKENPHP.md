# FrankenPHP demos (Symfony 8)

**REQ-DEMO-001:** FrankenPHP demos must install **Nowo Twig Inspector** and **Nowo Hot Reload** together (`nowo-tech/twig-inspector-bundle` + `nowo-tech/hot-reload-bundle` in `require-dev`). Caddyfile: Mercure + `hot_reload` (and `worker { file …; watch }` in worker mode). Do not enable Hot Reload in production.

The `demo/symfony8` app runs on **FrankenPHP** (Caddy + PHP).

## Table of contents

- [Development](#development)
- [Production-style (worker)](#production-style-worker)
- [Start / stop](#start--stop)
- [Switching classic vs worker (`FRANKENPHP_MODE`)](#switching-classic-vs-worker-frankenphp_mode)
- [Timeout hierarchy (REQ-RUNTIME-001)](#timeout-hierarchy-req-runtime-001)
- [Demo smoke (REQ-TEST-011)](#demo-smoke-req-test-011)
- [Troubleshooting](#troubleshooting)
- [FrankenPHP worker mode](#frankenphp-worker-mode)

## Development

- `APP_ENV=dev` (default in Docker Compose) copies `docker/frankenphp/Caddyfile.dev` over the active Caddyfile (entrypoint): **classic `php_server`** (no worker) so template and YAML changes show up reliably.
- `docker/php-dev.ini` sets `opcache.revalidate_freq=0`.
- `config/packages/dev/twig.yaml` disables Twig cache.

## Production-style (worker)

- The committed `docker/frankenphp/Caddyfile` uses `php_server { worker /app/public/index.php 2 }` (worker mode).
- With `APP_ENV=prod`, the entrypoint keeps that Caddyfile (no `Caddyfile.dev` copy).

## Start / stop

From each demo folder:

```bash
make up
make down
```

The Makefile prints `Demo started at: http://localhost:<PORT>` using `PORT` from `.env` / `.env.example`.

## Switching classic vs worker (`FRANKENPHP_MODE`)

Demos select the FrankenPHP runtime via **`FRANKENPHP_MODE`** in `.env` / `.env.example` (not a Dockerfile `ENV`):

| Value | Behaviour |
| --- | --- |
| **`worker`** (default) | Keep the worker Caddyfile (`php_server { worker ... }`) |
| **`classic`** | Entrypoint copies `Caddyfile.dev` (plain `php_server`, hot-reload friendly) |

Compose passes `FRANKENPHP_MODE=${FRANKENPHP_MODE:-worker}` into the PHP service. After changing `.env`, run `docker compose up -d` (or `make up`) so the container is **recreated** — a plain `restart` does not reload env. No image rebuild is required.

## Timeout hierarchy (REQ-RUNTIME-001)

The only blocking third-party I/O is machine translation (`fill-missing` → Google / DeepL / LibreTranslate over Symfony HttpClient). Both demo Caddyfiles (`docker/frankenphp/Caddyfile` for worker mode, `Caddyfile.dev` for classic mode) declare the deadlines in their global block, innermost first:

| Layer | Demo value | Where |
|-------|------------|-------|
| Bundle HTTP deadline (`machine_translation_http_timeout`, idle `timeout` + total `max_duration`) | 30 s (bundle default) | `config/packages/nowo_translation_yaml_tools.yaml` |
| PHP `max_execution_time` / `max_input_time` | 60 s | `frankenphp { php_ini … }` |
| Caddy `servers.timeouts.write` | 90 s | `servers { timeouts { write 90s } }` |
| FrankenPHP `max_wait_time` | 30 s | `frankenphp { max_wait_time 30s }` — a request waiting longer for a free PHP thread gets a 504 instead of queueing forever |

Each layer is strictly greater than the one inside it, so the HTTP request is aborted (and `fill-missing` fails with `Translation failed for key …`) before PHP or Caddy cut the response. When you raise the bundle timeout, raise PHP and Caddy in the same change. The worker Caddyfile is copied into the image, so rebuild (`make build`) after editing it; `Caddyfile.dev` is mounted.

Check a forced short timeout: set `machine_translation_http_timeout: 1` with `libretranslate_base_url` pointing to an allowed host that never answers, run `php bin/console nowo:translation-yaml:fill-missing --domain=messages --target-locale=fr` in the container (`make shell`; it fails before writing anything), and confirm it fails with `Translation failed for key …` after about one second and `ps` inside the container shows no leftover PHP process.

## Demo smoke (REQ-TEST-011)

```bash
make demo-smoke          # from the bundle root (or: make -C demo demo-smoke)
```

Starts `demo/symfony8` (FrankenPHP + MySQL), polls **`http://127.0.0.1:<PORT>/`** (`PORT` from `.env` / `.env.example`, default **8038**) with `curl -sL` until the final response is **HTTP 200** (up to 90 s), prints the container logs on failure, and always tears the stack down. CI runs it on every push / pull request (`ci.yml` job `demo-smoke`), nightly and on release tags (`.github/workflows/demo-smoke.yml`); root `make release-check` includes it. Only `.env.example` values are used.

## Troubleshooting

- If Composer fails on the path repository, ensure the compose volume mounts the bundle at `/var/translation-yaml-tools-bundle` (see each demo `docker-compose.yml`).
- Clear cache after bundle changes: `make update-bundle` inside the demo directory.

## FrankenPHP worker mode

FrankenPHP worker mode is **supported**, including when the Symfony kernel is **not** reset between requests. The missing-translation log empties its request buffer on every flush, logs persistence errors instead of ending the worker loop, refreshes Web UI rows behind the Doctrine identity map, and detaches managed log entities after clear actions. Full review: [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md). Production Caddyfile uses worker; `Caddyfile.dev` is classic mode.
