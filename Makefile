# Translation YAML Tools Bundle — root Makefile (Docker PHP service).
SHELL := /bin/bash

COMPOSE_FILE := docker-compose.yml
# Prefer Compose V2 plugin (GitHub Actions / modern Docker Desktop); fall back to docker-compose V1 (REQ-MAKE-010).
COMPOSE_BIN := $(shell docker compose version >/dev/null 2>&1 && echo "docker compose" || echo "docker-compose")
COMPOSE     := $(COMPOSE_BIN) -f $(COMPOSE_FILE)
SERVICE_PHP := php

.PHONY: help up down down-dev build shell install test test-coverage test-coverage-100 coverage-check cs-check cs-fix qa clean release-check release-check-demos demo-smoke composer-sync rector rector-dry phpstan igor update validate validate-translations validate-translations-demos assets check-no-cursor-coauthor check-open-prs strip-cursor-coauthor-from-history setup-hooks check-twig-extra check-no-raw-html-form

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
	@echo "  demo-smoke     Boot demo/symfony8 and expect HTTP 200 (REQ-TEST-011)"
	@echo "  release-check  Full pre-release chain (REQ-MAKE-002)"
	@echo "  release-check-demos  demo verify (Symfony 8 Docker)"
	@echo "  check-no-raw-html-form  Fail on raw <form>/<input>/<textarea>/<select> in Twig (REQ-TWIG-005)"
	@echo "  validate-translations  YAML lint + 7-locale key parity of src/Resources/translations"
	@echo "  validate-translations-demos  lint YAML in demo translation dirs (requires demo containers)"
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


check-twig-extra:
	@chmod +x .scripts/check-twig-extra.sh
	@./.scripts/check-twig-extra.sh

# REQ-TWIG-005 — no raw <form>/<input>/<textarea>/<select> in bundle views or demo templates (form theme allowlisted).
check-no-raw-html-form:
	@chmod +x .scripts/check-no-raw-html-form.sh
	@./.scripts/check-no-raw-html-form.sh

# Run Igor worker-state audit (REQ-CS-008)
igor: ensure-up
	$(COMPOSE) exec -T php composer igor

# REQ-MAKE-002 order (coverage gate: coverage-check → test-coverage-100).
release-check: ensure-up check-no-cursor-coauthor check-open-prs check-twig-extra check-no-raw-html-form composer-sync cs-fix cs-check rector-dry phpstan igor validate-translations coverage-check demo-smoke release-check-demos

release-check-demos:
	@$(MAKE) -C demo release-check

# REQ-MAKE-004 / REQ-I18N-002: YAML syntax + key parity of the 7 required bundle locales (en reference).
validate-translations: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) php vendor/bin/yaml-lint src/Resources/translations
	$(COMPOSE) exec -T $(SERVICE_PHP) php .scripts/validate-translations.php

# Lint the demo app translation dirs (requires the demo containers).
validate-translations-demos:
	@$(MAKE) -C demo validate-translations

composer-sync: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction --prefer-dist

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

# REQ-TEST-011: boot demo/symfony8, expect a final HTTP 200 on / (redirects followed), tear down.
demo-smoke:
	@$(MAKE) -C demo demo-smoke

strip-cursor-coauthor-from-history:
	@chmod +x .scripts/strip-cursor-coauthor-from-history.sh
	@./.scripts/strip-cursor-coauthor-from-history.sh main

twig-lint: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer twig:lint || $(COMPOSE) exec -T $(SERVICE_PHP) ./vendor/bin/twig-cs-fixer lint --config=.twig-cs-fixer.php
