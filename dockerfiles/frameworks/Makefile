ROOT_DIR:=$(shell dirname $(realpath $(lastword $(MAKEFILE_LIST))))
THIS_FILE := $(lastword $(MAKEFILE_LIST))
VERSION_TO_INSTALL:=0.30.1

JOBS:=laravel laravel_no_ddtrace symfony symfony_no_ddtrace
.PHONY: all
all: $(JOBS)

DDTRACE_DEB=$(ROOT_DIR)/nginx_file_server/ddtrace.deb
$(DDTRACE_DEB):
ifdef CI
	cp build/packages/*.deb "$@"
else
	curl -L -o "$@" "https://github.com/DataDog/dd-trace-php/releases/download/$(VERSION_TO_INSTALL)/datadog-php-tracer_$(VERSION_TO_INSTALL)_amd64.deb"
endif

.PHONY: nginx_file_server
nginx_file_server: $(DDTRACE_DEB)
	@docker-compose -f $(ROOT_DIR)/nginx_file_server.yml build --no-cache nginx_file_server

.PHONY: symfony
symfony: nginx_file_server
	@echo Running $@
	@docker-compose -f $(ROOT_DIR)/nginx_file_server.yml -f $(ROOT_DIR)/symfony.yml pull
	@docker-compose -f $(ROOT_DIR)/nginx_file_server.yml -f $(ROOT_DIR)/symfony.yml run symfony

.PHONY: symfony_no_ddtrace
symfony_no_ddtrace: nginx_file_server
	@echo Running $@
	@docker-compose -f $(ROOT_DIR)/nginx_file_server.yml -f $(ROOT_DIR)/symfony.yml pull
	@docker-compose -f $(ROOT_DIR)/nginx_file_server.yml -f $(ROOT_DIR)/symfony.yml run -e NO_DDTRACE=true symfony

.PHONY: laravel
laravel: nginx_file_server
	@echo Running $@
	@docker-compose -f $(ROOT_DIR)/nginx_file_server.yml -f $(ROOT_DIR)/laravel.yml pull
	@docker-compose -f $(ROOT_DIR)/nginx_file_server.yml -f $(ROOT_DIR)/laravel.yml run laravel php ./vendor/bin/phpunit -v

.PHONY: laravel_no_ddtrace
laravel_no_ddtrace: nginx_file_server
	@echo Running $@
	@docker-compose -f $(ROOT_DIR)/nginx_file_server.yml -f $(ROOT_DIR)/laravel.yml pull
	@docker-compose -f $(ROOT_DIR)/nginx_file_server.yml -f $(ROOT_DIR)/laravel.yml run -e NO_DDTRACE=true laravel php ./vendor/bin/phpunit -v

.PHONY: clean
clean:
	test -e $(DDTRACE_DEB) && rm $(DDTRACE_DEB) || true
