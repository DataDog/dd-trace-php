.PHONY=all symfony clean
ROOT_DIR:=$(shell dirname $(realpath $(lastword $(MAKEFILE_LIST))))
all: symfony

DDTRACE_DEB=$(ROOT_DIR)/ddtrace.deb
$(DDTRACE_DEB):
ifdef CI
	cp build/packages/*.deb "$@"
else
	curl -L -o "$@" "https://github.com/DataDog/dd-trace-php/releases/download/0.30.0/datadog-php-tracer_0.30.0_amd64.deb"
endif

symfony: $(DDTRACE_DEB)
	docker-compose -f $(ROOT_DIR)/symfony.yml pull
	docker-compose -f $(ROOT_DIR)/symfony.yml run symfony php ./phpunit --debug -v

clean:
	test -e $(DDTRACE_DEB) && rm $(DDTRACE_DEB) || true
