# FrankenPHP demos (Symfony 8)

**REQ-DEMO-001:** FrankenPHP demos must install **Nowo Twig Inspector** and **Nowo Hot Reload** together (`nowo-tech/twig-inspector-bundle` + `nowo-tech/hot-reload-bundle` in `require-dev`). Caddyfile: Mercure + `hot_reload` (and `worker { file …; watch }` in worker mode). Do not enable Hot Reload in production.

The `demo/symfony8` app runs on **FrankenPHP** (Caddy + PHP).

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

## Troubleshooting

- If Composer fails on the path repository, ensure the compose volume mounts the bundle at `/var/translation-yaml-tools-bundle` (see each demo `docker-compose.yml`).
- Clear cache after bundle changes: `make update-bundle` inside the demo directory.

## FrankenPHP worker mode

FrankenPHP worker mode: Supported (see production Caddyfile in each demo; dev uses non-worker `Caddyfile.dev`).
