# FrankenPHP demos (Symfony 7 & 8)

The `demo/symfony7` and `demo/symfony8` apps run on **FrankenPHP** (Caddy + PHP).

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

## Troubleshooting

- If Composer fails on the path repository, ensure the compose volume mounts the bundle at `/var/translation-yaml-tools-bundle` (see each demo `docker-compose.yml`).
- Clear cache after bundle changes: `make update-bundle` inside the demo directory.

## FrankenPHP worker mode

FrankenPHP worker mode: Supported (see production Caddyfile in each demo; dev uses non-worker `Caddyfile.dev`).
