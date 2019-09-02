ROOT_DIR:=$(shell dirname $(realpath $(lastword $(MAKEFILE_LIST))))
THIS_FILE := $(lastword $(MAKEFILE_LIST))
VERSION_TO_INSTALL:=0.30.1

YAMLS = $(filter-out nginx_file_server.yml docker-compose.yml, $(notdir $(wildcard $(ROOT_DIR)/*.yml)))
DDTRACE_TARGETS = ${YAMLS:.yml=}
NO_DDTRACE_TARGETS := ${YAMLS:.yml=_no_ddtrace}
TARGETS := $(DDTRACE_TARGETS) $(NO_DDTRACE_TARGETS)

.PHONY: all
all: help

DDTRACE_DEB=$(ROOT_DIR)/nginx_file_server/ddtrace.deb
$(DDTRACE_DEB):
ifdef CI
	cp build/packages/*.deb "$@"
else
	curl -L -o "$@" "https://github.com/DataDog/dd-trace-php/releases/download/$(VERSION_TO_INSTALL)/datadog-php-tracer_$(VERSION_TO_INSTALL)_amd64.deb"
endif

.PHONY: build_file_server
build_file_server: $(DDTRACE_DEB)
	@docker-compose -f $(ROOT_DIR)/nginx_file_server.yml build --no-cache nginx_file_server

.PHONY: clean
clean:
	test -e $(DDTRACE_DEB) && rm $(DDTRACE_DEB) || true

base:
	docker build ./src --target $@

.PHONY: $(TARGETS)
$(DDTRACE_TARGETS): build_file_server
	@echo Running $@
	@docker-compose -f $(ROOT_DIR)/nginx_file_server.yml -f $(ROOT_DIR)/$@.yml pull
	@docker-compose -f $(ROOT_DIR)/nginx_file_server.yml -f $(ROOT_DIR)/$@.yml run $@

$(NO_DDTRACE_TARGETS): build_file_server
	@echo Running without ddtrace enabled $(@:_no_ddtrace=)
	@docker-compose -f $(ROOT_DIR)/nginx_file_server.yml -f $(ROOT_DIR)/$(@:_no_ddtrace=).yml pull
	@docker-compose -f $(ROOT_DIR)/nginx_file_server.yml -f $(ROOT_DIR)/$(@:_no_ddtrace=).yml run -e NO_DDTRACE=true $(@:_no_ddtrace=)

.PHONY: help
help:
	@echo Available targets
	@echo $(TARGETS)
