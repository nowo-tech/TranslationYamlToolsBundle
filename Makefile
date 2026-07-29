# Translation YAML Tools Bundle — root Makefile (Docker PHP service).
SHELL := /bin/bash

COMPOSE_FILE := docker-compose.yml
# Prefer Compose V2 plugin (GitHub Actions / modern Docker Desktop); fall back to docker-compose V1 (REQ-MAKE-010).
COMPOSE_BIN := $(shell docker compose version >/dev/null 2>&1 && echo "docker compose" || echo "docker-compose")
COMPOSE     := $(COMPOSE_BIN) -f $(COMPOSE_FILE)
SERVICE_PHP := php

.PHONY: help up down down-dev build shell install test test-coverage test-coverage-100 coverage-check cs-check cs-fix qa clean release-check release-check-demos demo-smoke composer-sync rector rector-dry phpstan update validate validate-translations assets check-no-cursor-coauthor check-open-prs strip-cursor-coauthor-from-history setup-hooks

help:
	@echo "Translation YAML Tools Bundle"
	@echo ""
	@echo "Usage: make <target>"
	@echo ""
	@echo "  up             Start Docker"
	@echo "  down           Stop Docker"
	@echo "  down-dev       Stop Docker (dev alias)"
	@echo "  build          Rebuild image (no cache)"
	@echo "  shell          Shell in PHP container"
	@echo "  install        composer install (ensure-up)"
	@echo "  test           PHPUnit"
	@echo "  test-coverage  PHPUnit + coverage summary"
	@echo "  coverage-check Enforce 100% coverage (REQ-TEST-006)"
	@echo "  cs-check / cs-fix / rector / rector-dry / phpstan"
	@echo "  qa             cs-check + test"
	@echo "  check-open-prs Fail if unresolved GitHub PRs (REQ-REL-003)"
	@echo "  demo-smoke     Demo release-check"
	@echo "  release-check  Full pre-release chain (REQ-MAKE-002)"
	@echo "  release-check-demos  demo verify (Symfony 8 Docker)"
	@echo "  validate-translations  lint YAML in demo translation dirs (requires demo containers)"
	@echo "  composer-sync  validate + composer update --no-install"
	@echo "  clean          remove vendor, caches, coverage"
	@echo ""
	@echo "Demos: make -C demo help"

build:
	$(COMPOSE) build --no-cache

up:
	$(COMPOSE) build
	$(COMPOSE) up -d
	@echo "Installing dependencies..."
	$(COMPOSE) exec $(SERVICE_PHP) composer install --no-interaction
	@echo "Container ready."

down:
	$(COMPOSE) down

down-dev: down

shell:
	$(COMPOSE) exec $(SERVICE_PHP) sh

install: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install

ensure-up:
	@if ! $(COMPOSE) exec -T $(SERVICE_PHP) true 2>/dev/null; then \
		echo "Starting container..."; \
		$(COMPOSE) up -d; \
		sleep 3; \
		$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction; \
	fi

test: ensure-up
	$(COMPOSE) exec $(SERVICE_PHP) composer test

test-coverage: ensure-up
	$(COMPOSE) exec $(SERVICE_PHP) composer test-coverage | tee coverage-php.txt
	./.scripts/php-coverage-percent.sh coverage-php.txt

test-coverage-100: test-coverage
	$(COMPOSE) exec -T $(SERVICE_PHP) php scripts/check-coverage.php coverage.xml --min-percent=100

coverage-check: test-coverage-100

cs-check: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer cs-check

cs-fix: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer cs-fix

rector: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer rector

rector-dry: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer rector-dry

phpstan: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer phpstan

qa: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer qa

update: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer update --no-interaction

validate: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict

release-check: check-no-cursor-coauthor check-open-prs ensure-up composer-sync cs-check rector-dry phpstan validate-translations coverage-check release-check-demos

release-check-demos:
	@$(MAKE) -C demo release-check

validate-translations:
	@$(MAKE) -C demo validate-translations

composer-sync: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict
	$(COMPOSE) exec -T $(SERVICE_PHP) composer update --no-install

clean: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) sh -c "rm -rf vendor .phpunit.cache coverage coverage.xml .php-cs-fixer.cache"

assets:
	@echo "No frontend assets in this bundle."



setup-hooks:
	@chmod +x .githooks/pre-commit 2>/dev/null || true
	@chmod +x .githooks/commit-msg 2>/dev/null || true
	@git config core.hooksPath .githooks
	@echo "✅ Git hooks installed (.githooks — includes commit-msg for REQ-GIT-001)."

# REQ-MAKE-008: update-deps (REQ-MAKE-008)
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST))))
# Optional: monorepo helper absent on standalone GitHub Actions checkout (REQ-MAKE-009).
-include $(BUNDLE_ROOT)/../.scripts/Makefile.update-deps.mk
check-no-cursor-coauthor:
	@chmod +x .scripts/check-no-cursor-coauthor.sh
	@./.scripts/check-no-cursor-coauthor.sh HEAD

check-open-prs:
	@chmod +x .scripts/check-open-prs.sh
	@GH_REPO=nowo-tech/TranslationYamlToolsBundle ./.scripts/check-open-prs.sh

demo-smoke:
	@if [ -f demo/Makefile ]; then $(MAKE) -C demo release-check; else echo "No demo/Makefile — skip demo-smoke"; fi

strip-cursor-coauthor-from-history:
	@chmod +x .scripts/strip-cursor-coauthor-from-history.sh
	@./.scripts/strip-cursor-coauthor-from-history.sh main
