# Translation YAML Tools Bundle — root Makefile (Docker PHP service).

COMPOSE_FILE := docker-compose.yml
COMPOSE := docker-compose -f $(COMPOSE_FILE)
SERVICE_PHP := php

.PHONY: help up down build shell install test test-coverage cs-check cs-fix qa clean release-check release-check-demos composer-sync rector rector-dry phpstan update validate validate-translations assets

help:
	@echo "Translation YAML Tools Bundle"
	@echo ""
	@echo "Usage: make <target>"
	@echo ""
	@echo "  up             Start Docker"
	@echo "  down           Stop Docker"
	@echo "  build          Rebuild image (no cache)"
	@echo "  shell          Shell in PHP container"
	@echo "  install        composer install (ensure-up)"
	@echo "  test           PHPUnit"
	@echo "  test-coverage  PHPUnit + coverage summary"
	@echo "  cs-check / cs-fix / rector / rector-dry / phpstan"
	@echo "  qa             cs-check + test"
	@echo "  release-check  ensure-up, composer-sync, cs-fix, cs-check, rector-dry, phpstan, test-coverage, release-check-demos"
	@echo "  release-check-demos  demo verify (Symfony 7 & 8 Docker)"
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

release-check: ensure-up composer-sync cs-fix cs-check rector-dry phpstan test-coverage release-check-demos

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
