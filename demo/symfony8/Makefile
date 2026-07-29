# Symfony 8.0 demo — Translation YAML Tools Bundle

SHELL := /bin/bash
PAUSE ?= 3

# Prefer Compose V2 plugin (GitHub Actions / modern Docker Desktop); fall back to docker-compose V1 (REQ-MAKE-010).
COMPOSE := $(shell docker compose version >/dev/null 2>&1 && echo "docker compose" || echo "docker-compose")
SERVICE_PHP := php

.PHONY: help up down build install shell logs test test-coverage cache-clear update-bundle ensure-up verify validate-translations translation-yaml-demos translation-yaml-inline-preview translation-yaml-walkthrough missing-log-schema reset-db

help:
	@echo "Translation YAML Tools — Symfony 8.0 demo"
	@echo "  Tip: use the make prefix (e.g. make translation-yaml-inline-preview), not the target name alone."
	@echo "  make up / down / build / install / shell / logs"
	@echo "  make test / test-coverage / verify / validate-translations"
	@echo "  make translation-yaml-demos / translation-yaml-inline-preview (bundle CLI examples)"
	@echo "  make translation-yaml-walkthrough — all bundle commands + pauses (PAUSE=$(PAUSE)s); restores files after"
	@echo "  make update-bundle"
	@echo "  make missing-log-schema — doctrine:schema:update (dev missing-log table)"
	@echo "  make reset-db — delete demo SQLite + recreate schema"

up:
	@if [ ! -f .env ]; then cp .env.example .env 2>/dev/null || true; fi
	@rm -f .env.dev
	$(COMPOSE) up -d
	@echo "Waiting for container to be ready..."
	@sleep 5
	@echo "Installing dependencies..."
	@$(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 php composer install --no-interaction
	@rm -f .env.dev
	@echo "Resetting demo DB (fresh start)..."
	@$(COMPOSE) exec -T php sh -c 'rm -f /app/var/demo_missing_log.sqlite'
	@echo "Doctrine schema (missing_translation_log table in dev)..."
	@$(COMPOSE) exec -T php php bin/console doctrine:schema:update --force --no-interaction || true
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	echo "Demo started at: http://localhost:$$PORT"

down:
	$(COMPOSE) down

restart: ensure-up
	$(COMPOSE) restart

build:
	$(COMPOSE) build --no-cache

ensure-up:
	@if [ ! -f .env ]; then cp .env.example .env 2>/dev/null || true; fi
	@rm -f .env.dev
	@if ! $(COMPOSE) ps --status running --services 2>/dev/null | grep -qx php; then \
		echo "Starting demo container..."; \
		$(COMPOSE) up -d --build; \
		sleep 8; \
		$(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 php composer install --no-interaction; \
		rm -f .env.dev; \
	fi

install: ensure-up
	$(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 php composer install --no-interaction

shell: ensure-up
	$(COMPOSE) exec php sh

logs:
	$(COMPOSE) logs -f

test: ensure-up
	$(COMPOSE) exec -T php rm -rf var/cache/test
	$(COMPOSE) exec -e APP_ENV=test php composer test

test-coverage: ensure-up
	$(COMPOSE) exec php composer test-coverage

cache-clear: ensure-up
	$(COMPOSE) exec php php bin/console cache:clear

missing-log-schema: ensure-up
	$(COMPOSE) exec -T php php bin/console doctrine:schema:update --force --no-interaction

reset-db: ensure-up
	$(COMPOSE) exec -T php sh -c 'rm -f /app/var/demo_missing_log.sqlite'
	$(COMPOSE) exec -T php php bin/console doctrine:schema:update --force --no-interaction

update-bundle: ensure-up
	$(COMPOSE) exec php composer update nowo-tech/translation-yaml-tools-bundle --no-interaction
	$(COMPOSE) exec php php bin/console cache:clear --env=dev --no-warmup || true
	@echo "Bundle updated."

validate-translations: ensure-up
	$(COMPOSE) exec -T php php bin/console lint:yaml translations
	$(COMPOSE) exec -T php php bin/console lint:yaml translations_extra

translation-yaml-demos: ensure-up
	@echo "=== nowo:translation-yaml:tree (messages, dry-run) ==="
	$(COMPOSE) exec -T php php bin/console nowo:translation-yaml:tree --domain=messages --locale=en --dry-run
	@echo "=== nowo:translation-yaml:tree (validators, dry-run) ==="
	$(COMPOSE) exec -T php php bin/console nowo:translation-yaml:tree --domain=validators --locale=en --dry-run
	@echo "=== nowo:translation-yaml:sort (messages, dry-run) ==="
	$(COMPOSE) exec -T php php bin/console nowo:translation-yaml:sort --domain=messages --locale=en --dry-run
	@echo "=== nowo:translation-yaml:flatten (messages, dry-run) ==="
	$(COMPOSE) exec -T php php bin/console nowo:translation-yaml:flatten --domain=messages --locale=en --dry-run
	@echo "=== nowo:translation-yaml:fill-missing (messages → es, dry-run) ==="
	$(COMPOSE) exec -T php php bin/console nowo:translation-yaml:fill-missing --domain=messages --target-locale=es --dry-run

translation-yaml-inline-preview: ensure-up
	@echo "Sort messages.en.yaml with --inline (flow YAML), show file, then restore from backup."
	$(COMPOSE) exec -T php sh -c 'set -e; cd /app; cp translations/messages.en.yaml /tmp/messages.en.demo.bak; php bin/console nowo:translation-yaml:sort --domain=messages --locale=en --inline; echo "----- messages.en.yaml (preview) -----"; cat translations/messages.en.yaml; mv /tmp/messages.en.demo.bak translations/messages.en.yaml; echo "----- restored original messages.en.yaml -----"'

translation-yaml-walkthrough: ensure-up
	@DEMO_ROOT=$$(pwd) PAUSE=$(PAUSE) sh ../scripts/translation-yaml-walkthrough.sh

verify:
	@if [ ! -f .env ]; then cp .env.example .env 2>/dev/null || true; fi
	@port=$$(grep '^PORT=' .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); [ -z "$$port" ] && port=8038; \
	$(COMPOSE) up -d 2>/dev/null; sleep 8; \
	code=$$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:$$port 2>/dev/null || echo "000"); \
	if [ "$$code" = "200" ]; then echo "OK (HTTP $$code)"; else echo "Failed (HTTP $$code)"; exit 1; fi


# REQ-MAKE-008: update-deps (REQ-MAKE-008)
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/../..)
# Optional: monorepo helper absent on standalone GitHub Actions checkout (REQ-MAKE-009).
-include $(BUNDLE_ROOT)/../.scripts/Makefile.demo-update-deps.mk
