# Demo aggregate — Symfony 8 (Translation YAML Tools Bundle).

DEMOS := symfony8

PAUSE ?= 3

.PHONY: help clean test-all test-coverage-all verify-all release-check release-verify
.PHONY: $(foreach d,$(DEMOS),up-$d down-$d restart-$d build-$d install-$d update-bundle-$d shell-$d logs-$d test-$d test-coverage-$d verify-$d validate-translations-$d translation-yaml-demos-$d translation-yaml-inline-preview-$d translation-yaml-walkthrough-$d)
.PHONY: up down restart build install update-bundle test shell logs test-coverage verify validate-translations demo-smoke
.PHONY: translation-yaml-demos-all translation-yaml-inline-preview-all translation-yaml-walkthrough-all

help:
	@echo "Translation YAML Tools — Demos (Symfony 8)"
	@echo ""
	@echo "Per demo: make up-symfony8, down-symfony8, test-symfony8, verify-symfony8, validate-translations-symfony8, …"
	@echo "Bundle CLI: make translation-yaml-walkthrough-symfony8 (all commands + pauses; PAUSE=$(PAUSE)s), translation-yaml-demos-all, …"
	@echo "Aggregate: make test-all | verify-all | release-check | demo-smoke"
	@echo "Generic: make up DEMO=symfony8"
	@echo "Ports: see demo/symfony8/.env.example (8038)."

up-symfony8:
	@$(MAKE) -C symfony8 up

down-symfony8:
	@$(MAKE) -C symfony8 down

restart-symfony8:
	@$(MAKE) -C symfony8 restart

build-symfony8:
	@$(MAKE) -C symfony8 build

install-symfony8:
	@$(MAKE) -C symfony8 install

update-bundle-symfony8:
	@$(MAKE) -C symfony8 update-bundle

shell-symfony8:
	@$(MAKE) -C symfony8 shell

logs-symfony8:
	@$(MAKE) -C symfony8 logs

test-symfony8:
	@$(MAKE) -C symfony8 test

test-coverage-symfony8:
	@$(MAKE) -C symfony8 test-coverage

verify-symfony8:
	@$(MAKE) -C symfony8 verify

validate-translations-symfony8:
	@$(MAKE) -C symfony8 validate-translations

translation-yaml-demos-symfony8:
	@$(MAKE) -C symfony8 translation-yaml-demos

translation-yaml-demos-all: translation-yaml-demos-symfony8

translation-yaml-inline-preview-symfony8:
	@$(MAKE) -C symfony8 translation-yaml-inline-preview

translation-yaml-inline-preview-all: translation-yaml-inline-preview-symfony8

translation-yaml-walkthrough-symfony8:
	@$(MAKE) -C symfony8 translation-yaml-walkthrough PAUSE=$(PAUSE)

translation-yaml-walkthrough-all: translation-yaml-walkthrough-symfony8

up:
	@[ -n "$(DEMO)" ] || { echo "Usage: make up DEMO=symfony8"; exit 1; }
	@$(MAKE) up-$(DEMO)

down:
	@[ -n "$(DEMO)" ] || { echo "Usage: make down DEMO=symfony8"; exit 1; }
	@$(MAKE) down-$(DEMO)

test-all:
	@$(MAKE) test-symfony8

test-coverage-all:
	@$(MAKE) test-coverage-symfony8

verify-all:
	@$(MAKE) verify-symfony8

validate-translations:
	@$(MAKE) validate-translations-symfony8

# REQ-TEST-011: boot symfony8, wait for a final HTTP 200 on / (redirects followed), always tear down.
COMPOSE := $(shell docker compose version >/dev/null 2>&1 && echo "docker compose" || echo "docker-compose")
demo-smoke:
	@PORT=$$(grep "^PORT=" symfony8/.env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" symfony8/.env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=8038; \
	echo "Demo smoke: symfony8 (http://127.0.0.1:$$PORT/)"; \
	$(MAKE) -C symfony8 up || { $(MAKE) -C symfony8 down 2>/dev/null; exit 1; }; \
	code=000; \
	i=0; \
	while [ $$i -lt 45 ]; do \
		code=$$(curl -sL -o /dev/null -w "%{http_code}" "http://127.0.0.1:$$PORT/" || true); \
		[ -z "$$code" ] && code=000; \
		if [ "$$code" = "200" ]; then break; fi; \
		i=$$((i+1)); \
		sleep 2; \
	done; \
	if [ "$$code" != "200" ]; then (cd symfony8 && $(COMPOSE) ps -a && $(COMPOSE) logs --tail=80 php) || true; fi; \
	$(MAKE) -C symfony8 down 2>/dev/null || true; \
	if [ "$$code" != "200" ]; then echo "symfony8 failed with HTTP $$code"; exit 1; fi; \
	echo "symfony8 OK (HTTP $$code)"

release-check: test-all release-verify
	@echo "All demos passed release-check."

release-verify:
	@for demo in $(DEMOS); do \
		port=$$(grep '^PORT=' $$demo/.env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
		if [ -z "$$port" ]; then \
			port=$$(grep '^PORT=' $$demo/.env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
		fi; \
		echo "Release verify: $$demo (port $$port)..."; \
		$(MAKE) up-$$demo; \
		sleep 10; \
		code=$$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:$$port 2>/dev/null || echo "000"); \
		$(MAKE) down-$$demo 2>/dev/null || true; \
		if [ "$$code" != "200" ]; then echo "Demo $$demo healthcheck failed (HTTP $$code)"; exit 1; fi; \
		echo "OK $$demo (HTTP $$code)"; \
	done

clean:
	@rm -rf $(addsuffix /vendor,$(DEMOS)) $(addsuffix /var,$(DEMOS)) $(addsuffix /.phpunit.cache,$(DEMOS))
	@echo "Cleaned demos."


# REQ-MAKE-008: update-deps-all (REQ-MAKE-008)
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/..)
# Optional: monorepo helper absent on standalone GitHub Actions checkout (REQ-MAKE-009).
-include $(BUNDLE_ROOT)/../.scripts/Makefile.demo-aggregate-update-deps.mk
