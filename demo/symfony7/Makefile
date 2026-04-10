# Symfony 7.0 demo — Translation YAML Tools Bundle

PAUSE ?= 3

.PHONY: help up down build install shell logs test test-coverage cache-clear update-bundle ensure-up verify validate-translations translation-yaml-demos translation-yaml-inline-preview translation-yaml-walkthrough missing-log-schema

help:
	@echo "Translation YAML Tools — Symfony 7.0 demo"
	@echo "  Tip: use the make prefix (e.g. make translation-yaml-inline-preview), not the target name alone."
	@echo "  make up / down / build / install / shell / logs"
	@echo "  make test / test-coverage / verify / validate-translations"
	@echo "  make translation-yaml-demos / translation-yaml-inline-preview (bundle CLI examples)"
	@echo "  make translation-yaml-walkthrough — all bundle commands + pauses (PAUSE=$(PAUSE)s); restores files after"
	@echo "  make update-bundle"
	@echo "  make missing-log-schema — doctrine:schema:update (dev missing-log table)"

up:
	@if [ ! -f .env ]; then cp .env.example .env 2>/dev/null || true; fi
	docker-compose up -d
	@echo "Waiting for container to be ready..."
	@sleep 5
	@echo "Installing dependencies..."
	@docker-compose exec -T -e COMPOSER_MEMORY_LIMIT=-1 php composer install --no-interaction
	@echo "Doctrine schema (missing_translation_log table in dev)..."
	@docker-compose exec -T php php bin/console doctrine:schema:update --force --no-interaction || true
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	echo "Demo started at: http://localhost:$$PORT"

down:
	docker-compose down

restart: ensure-up
	docker-compose restart

build:
	docker-compose build --no-cache

ensure-up:
	@if ! docker-compose exec -T php true 2>/dev/null; then \
		docker-compose up -d; \
		sleep 3; \
	fi

install: ensure-up
	docker-compose exec -T -e COMPOSER_MEMORY_LIMIT=-1 php composer install --no-interaction

shell: ensure-up
	docker-compose exec php sh

logs:
	docker-compose logs -f

test: ensure-up
	docker-compose exec -e APP_ENV=test php composer test

test-coverage: ensure-up
	docker-compose exec php composer test-coverage

cache-clear: ensure-up
	docker-compose exec php php bin/console cache:clear

missing-log-schema: ensure-up
	docker-compose exec -T php php bin/console doctrine:schema:update --force --no-interaction

update-bundle: ensure-up
	docker-compose exec php composer update nowo-tech/translation-yaml-tools-bundle --no-interaction
	docker-compose exec php php bin/console cache:clear --env=dev --no-warmup || true
	@echo "Bundle updated."

validate-translations: ensure-up
	docker-compose exec -T php php bin/console lint:yaml translations
	docker-compose exec -T php php bin/console lint:yaml translations_extra

translation-yaml-demos: ensure-up
	@echo "=== nowo:translation-yaml:tree (messages, dry-run) ==="
	docker-compose exec -T php php bin/console nowo:translation-yaml:tree --domain=messages --locale=en --dry-run
	@echo "=== nowo:translation-yaml:tree (validators, dry-run) ==="
	docker-compose exec -T php php bin/console nowo:translation-yaml:tree --domain=validators --locale=en --dry-run
	@echo "=== nowo:translation-yaml:sort (messages, dry-run) ==="
	docker-compose exec -T php php bin/console nowo:translation-yaml:sort --domain=messages --locale=en --dry-run
	@echo "=== nowo:translation-yaml:flatten (messages, dry-run) ==="
	docker-compose exec -T php php bin/console nowo:translation-yaml:flatten --domain=messages --locale=en --dry-run
	@echo "=== nowo:translation-yaml:fill-missing (messages → es, dry-run) ==="
	docker-compose exec -T php php bin/console nowo:translation-yaml:fill-missing --domain=messages --target-locale=es --dry-run

translation-yaml-inline-preview: ensure-up
	@echo "Sort messages.en.yaml with --inline (flow YAML), show file, then restore from backup."
	docker-compose exec -T php sh -c 'set -e; cd /app; cp translations/messages.en.yaml /tmp/messages.en.demo.bak; php bin/console nowo:translation-yaml:sort --domain=messages --locale=en --inline; echo "----- messages.en.yaml (preview) -----"; cat translations/messages.en.yaml; mv /tmp/messages.en.demo.bak translations/messages.en.yaml; echo "----- restored original messages.en.yaml -----"'

translation-yaml-walkthrough: ensure-up
	@DEMO_ROOT=$$(pwd) PAUSE=$(PAUSE) sh ../scripts/translation-yaml-walkthrough.sh

verify:
	@if [ ! -f .env ]; then cp .env.example .env 2>/dev/null || true; fi
	@port=$$(grep '^PORT=' .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); [ -z "$$port" ] && port=8037; \
	docker-compose up -d 2>/dev/null; sleep 8; \
	code=$$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:$$port 2>/dev/null || echo "000"); \
	if [ "$$code" = "200" ]; then echo "OK (HTTP $$code)"; else echo "Failed (HTTP $$code)"; exit 1; fi
