# Demo aggregate — Symfony 7 and 8 (Translation YAML Tools Bundle).

DEMOS := symfony7 symfony8

PAUSE ?= 3

.PHONY: help clean test-all test-coverage-all verify-all release-check release-verify
.PHONY: $(foreach d,$(DEMOS),up-$d down-$d restart-$d build-$d install-$d update-bundle-$d shell-$d logs-$d test-$d test-coverage-$d verify-$d validate-translations-$d translation-yaml-demos-$d translation-yaml-inline-preview-$d translation-yaml-walkthrough-$d)
.PHONY: up down restart build install update-bundle test shell logs test-coverage verify validate-translations
.PHONY: translation-yaml-demos-all translation-yaml-inline-preview-all translation-yaml-walkthrough-all

help:
	@echo "Translation YAML Tools — Demos (Symfony 7 & 8)"
	@echo ""
	@echo "Per demo: make up-symfony7 | up-symfony8, down-symfony7, test-symfony7, verify-symfony7, validate-translations-symfony7, …"
	@echo "Bundle CLI: make translation-yaml-walkthrough-symfony7 (all commands + pauses; PAUSE=$(PAUSE)s), translation-yaml-demos-all, …"
	@echo "Aggregate: make test-all | verify-all | release-check"
	@echo "Generic: make up DEMO=symfony7|symfony8"
	@echo "Ports: see each demo .env.example (8037 / 8038)."

up-symfony7:
	@$(MAKE) -C symfony7 up
up-symfony8:
	@$(MAKE) -C symfony8 up

down-symfony7:
	@$(MAKE) -C symfony7 down
down-symfony8:
	@$(MAKE) -C symfony8 down

restart-symfony7:
	@$(MAKE) -C symfony7 restart
restart-symfony8:
	@$(MAKE) -C symfony8 restart

build-symfony7:
	@$(MAKE) -C symfony7 build
build-symfony8:
	@$(MAKE) -C symfony8 build

install-symfony7:
	@$(MAKE) -C symfony7 install
install-symfony8:
	@$(MAKE) -C symfony8 install

update-bundle-symfony7:
	@$(MAKE) -C symfony7 update-bundle
update-bundle-symfony8:
	@$(MAKE) -C symfony8 update-bundle

shell-symfony7:
	@$(MAKE) -C symfony7 shell
shell-symfony8:
	@$(MAKE) -C symfony8 shell

logs-symfony7:
	@$(MAKE) -C symfony7 logs
logs-symfony8:
	@$(MAKE) -C symfony8 logs

test-symfony7:
	@$(MAKE) -C symfony7 test
test-symfony8:
	@$(MAKE) -C symfony8 test

test-coverage-symfony7:
	@$(MAKE) -C symfony7 test-coverage
test-coverage-symfony8:
	@$(MAKE) -C symfony8 test-coverage

verify-symfony7:
	@$(MAKE) -C symfony7 verify
verify-symfony8:
	@$(MAKE) -C symfony8 verify

validate-translations-symfony7:
	@$(MAKE) -C symfony7 validate-translations
validate-translations-symfony8:
	@$(MAKE) -C symfony8 validate-translations

translation-yaml-demos-symfony7:
	@$(MAKE) -C symfony7 translation-yaml-demos
translation-yaml-demos-symfony8:
	@$(MAKE) -C symfony8 translation-yaml-demos

translation-yaml-demos-all: translation-yaml-demos-symfony7 translation-yaml-demos-symfony8

translation-yaml-inline-preview-symfony7:
	@$(MAKE) -C symfony7 translation-yaml-inline-preview
translation-yaml-inline-preview-symfony8:
	@$(MAKE) -C symfony8 translation-yaml-inline-preview

translation-yaml-inline-preview-all: translation-yaml-inline-preview-symfony7 translation-yaml-inline-preview-symfony8

translation-yaml-walkthrough-symfony7:
	@$(MAKE) -C symfony7 translation-yaml-walkthrough PAUSE=$(PAUSE)
translation-yaml-walkthrough-symfony8:
	@$(MAKE) -C symfony8 translation-yaml-walkthrough PAUSE=$(PAUSE)

translation-yaml-walkthrough-all: translation-yaml-walkthrough-symfony7 translation-yaml-walkthrough-symfony8

up:
	@[ -n "$(DEMO)" ] || { echo "Usage: make up DEMO=symfony7|symfony8"; exit 1; }
	@$(MAKE) up-$(DEMO)

down:
	@[ -n "$(DEMO)" ] || { echo "Usage: make down DEMO=symfony7|symfony8"; exit 1; }
	@$(MAKE) down-$(DEMO)

test-all:
	@$(MAKE) test-symfony7
	@$(MAKE) test-symfony8

test-coverage-all:
	@$(MAKE) test-coverage-symfony7
	@$(MAKE) test-coverage-symfony8

verify-all:
	@$(MAKE) verify-symfony7
	@$(MAKE) verify-symfony8

validate-translations:
	@$(MAKE) validate-translations-symfony7
	@$(MAKE) validate-translations-symfony8

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
