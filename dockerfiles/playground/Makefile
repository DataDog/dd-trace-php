# Playground: spin up one of the framework fixtures in a container so you can
# curl it manually. Builds the tracer + appsec extensions from the local
# checkout and mounts them in. See README.md.
#
# Usage:
#   make Laravel8x
#   APPSEC=0 make Laravel8x
#   TELEMETRY=1 PORT=9000 make Laravel8x
#   make shell   # bash into the running container
#   make logs    # tail
#   make down    # stop

REPO_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/../..)

# Toggles (env-overridable). Target name maps to a specific fixture + image.
APPSEC    ?= 1
TELEMETRY ?= 0
PORT      ?= 8080
# Optional: point at a real datadog-agent reachable from inside the container.
DD_AGENT_HOST       ?=
DD_TRACE_AGENT_PORT ?= 8126

# Resolve PHP version per framework. Each framework target below sets
# FIXTURE_DIR, BASETAG, DEFAULT_PHP, and SERVICE, then jumps to _up.
export REPO_ROOT APPSEC TELEMETRY PORT DD_AGENT_HOST DD_TRACE_AGENT_PORT

COMPOSE      := docker compose -p dd-playground -f $(REPO_ROOT)/dockerfiles/playground/docker-compose.yml
# For lifecycle commands that don't need to re-parse the compose file; by
# project name only. Avoids "empty section between colons" when env vars
# like FIXTURE_DIR aren't set (true for `make down` without a prior `make
# <Framework>` in the same shell).
COMPOSE_LITE := docker compose -p dd-playground

.PHONY: help
help:
	@echo "Available frameworks (one target each):"
	@echo "  Laravel8x"
	@echo
	@echo "Shared targets:"
	@echo "  down              Stop the running playground container"
	@echo "  shell             Open bash inside the running container"
	@echo "  logs              Tail container logs"
	@echo "  clean-extensions  Wipe stale host-built .so files (Mac/Win hosts)"
	@echo
	@echo "Env toggles (default in parens):"
	@echo "  APPSEC=0|1 (1)              Toggle DD_APPSEC_ENABLED"
	@echo "  TELEMETRY=0|1 (0)           Toggle DD_INSTRUMENTATION_TELEMETRY_ENABLED"
	@echo "  PHP=<version>               Override the per-framework default PHP"
	@echo "  PORT=<port> (8080)          Host port forwarded to apache:80"
	@echo "  DD_AGENT_HOST=<host>        Send traces to a real agent (default: none)"

# -----------------------------------------------------------------------------
# Internal: build extensions in a Linux container (so Mac/Windows hosts get
# ELF .so files the playground container can load), fetch composer, bring up.
# -----------------------------------------------------------------------------

TRACER_SO     := $(REPO_ROOT)/tmp/build_extension/modules/ddtrace.so
APPSEC_SO     := $(REPO_ROOT)/tmp/build_extension/appsec/ddappsec.so
COMPOSER_PHAR := $(REPO_ROOT)/tmp/playground/composer.phar

# Build inside the same CI image appsec-tests use for cross-PHP builds. nts
# matches the apache2-mod-php-$VERSION-release playground image's PHP ABI.
# $(DEFAULT_PHP) comes from the framework target below (e.g. 8.1 for Laravel8x).
BUILD_IMAGE   = datadog/dd-trace-ci:php-$(DEFAULT_PHP)_bookworm-11
# Run as root so switch-php (needs to write /usr/local/bin) and the build
# don't trip over sudo on the missing passwd entry (we're not using dockerh's
# setpriv dance). Chown the outputs back to the invoking user at the end so
# the host checkout stays owned by them (no-op on macOS, matters on Linux).
# PATH explicitly adds cargo: `bash -lc` doesn't source /root/.cargo/env on
# this image, and the ddtrace configure step requires `cargo` on PATH.
HOST_UID     := $(shell id -u)
HOST_GID     := $(shell id -g)
DOCKER_BUILD  = docker run --rm --platform=linux/amd64 \
                  -v $(REPO_ROOT):/project -w /project \
                  -e HOME=/tmp \
                  -e PATH=/rust/cargo/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
                  $(BUILD_IMAGE) bash -c

$(TRACER_SO):
	@echo ">> Building tracer extension inside $(BUILD_IMAGE) (produces Linux ELF) …"
	$(DOCKER_BUILD) 'switch-php nts && make all && \
	    (chown -R $(HOST_UID):$(HOST_GID) /project/tmp 2>/dev/null || true)'

$(APPSEC_SO):
	@echo ">> Building appsec extension inside $(BUILD_IMAGE) …"
	$(DOCKER_BUILD) 'switch-php nts && \
	    cmake -S /project/appsec -B /project/tmp/build_extension/appsec \
	          -DCMAKE_BUILD_TYPE=RelWithDebInfo && \
	    make -C /project/tmp/build_extension/appsec -j$$(nproc) extension && \
	    (chown -R $(HOST_UID):$(HOST_GID) /project/tmp 2>/dev/null || true)'

$(COMPOSER_PHAR):
	@echo ">> Fetching composer (cached at $@) …"
	mkdir -p $(dir $@)
	curl -LsSf https://getcomposer.org/download/latest-stable/composer.phar -o $@
	chmod +x $@

.PHONY: _build-extensions
_build-extensions: $(TRACER_SO) $(APPSEC_SO) $(COMPOSER_PHAR)

# Needed once if you have stale host-built (e.g. macOS Mach-O) outputs under
# tmp/build_extension/ — the container can't load them and may fail to
# overwrite half-written artifacts. Nuke the whole dir.
.PHONY: clean-extensions
clean-extensions:
	rm -rf $(REPO_ROOT)/tmp/build_extension

# Framework targets set FIXTURE_DIR, BASETAG, PHP (if unset), SERVICE, then
# call _up. BASETAG picks which pre-built dd-appsec-php-ci variant to pull.
.PHONY: _up
_up: _build-extensions
	@FIXTURE_DIR="$(REPO_ROOT)/tests/Frameworks/$(FIXTURE)" && \
	 PHP_VERSION="$(or $(PHP),$(DEFAULT_PHP))" && \
	 IMAGE="datadog/dd-appsec-php-ci:$(BASETAG)-$$PHP_VERSION-release" && \
	 echo ">> Image:       $$IMAGE" && \
	 echo ">> Fixture:     $$FIXTURE_DIR" && \
	 echo ">> APPSEC=$(APPSEC) TELEMETRY=$(TELEMETRY) PORT=$(PORT)" && \
	 PLAYGROUND_IMAGE="$$IMAGE" \
	 PLAYGROUND_SERVICE="$(SERVICE)" \
	 FIXTURE_DIR="$$FIXTURE_DIR" \
	   $(COMPOSE) up -d --force-recreate --wait
	@echo
	@echo ">> Up on http://localhost:$(PORT)/"
	@echo ">> make shell | make logs | make down"

# -----------------------------------------------------------------------------
# Framework targets. Add new ones here as they come online.
# -----------------------------------------------------------------------------

.PHONY: Laravel8x
Laravel8x: FIXTURE     := Laravel/Version_8_x
Laravel8x: BASETAG     := apache2-mod-php
Laravel8x: DEFAULT_PHP := 8.1
Laravel8x: SERVICE     := playground-laravel-8x
Laravel8x: _up

# -----------------------------------------------------------------------------
# Lifecycle helpers (framework-agnostic — compose knows what's up).
# -----------------------------------------------------------------------------

.PHONY: down shell logs
down:
	$(COMPOSE_LITE) down -v

shell:
	$(COMPOSE_LITE) exec php bash

logs:
	$(COMPOSE_LITE) logs -f
