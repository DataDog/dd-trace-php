.PHONY=all symfony clean nginx_file_server
ROOT_DIR:=$(shell dirname $(realpath $(lastword $(MAKEFILE_LIST))))
VERSION_TO_INSTALL:=0.30.0

all: symfony

DDTRACE_DEB=$(ROOT_DIR)/nginx_file_server/ddtrace.deb
$(DDTRACE_DEB):
ifdef CI
	cp build/packages/*.deb "$@"
else
	curl -L -o "$@" "https://github.com/DataDog/dd-trace-php/releases/download/$(VERSION_TO_INSTALL)/datadog-php-tracer_$(VERSION_TO_INSTALL)_amd64.deb"
endif

nginx_file_server: $(DDTRACE_DEB)
	docker-compose -f $(ROOT_DIR)/nginx_file_server.yml build --no-cache nginx_file_server

symfony: nginx_file_server
	docker-compose -f $(ROOT_DIR)/nginx_file_server.yml -f $(ROOT_DIR)/symfony.yml pull
	docker-compose -f $(ROOT_DIR)/nginx_file_server.yml -f $(ROOT_DIR)/symfony.yml run symfony php ./phpunit --debug -v

clean:
	test -e $(DDTRACE_DEB) && rm $(DDTRACE_DEB) || true
