TMP_SCENARIO_FOLDER := ./.tmp.scenarios
DURATION := 30s
TRACER_VERSION := 0.49.0
DOCKER_IMAGE_PREFIX := dd-random-testing:
TRACER_TEST_URL := https://303308-119990860-gh.circle-artifacts.com/0/datadog-php-tracer-1.0.0_nightly-1.x86_64.rpm
# TRACER_COMPARE_URL := $(shell curl --silent https://api.github.com/repos/DataDog/dd-trace-php/releases/latest | grep -e 'browser_download_url.*\.rpm' | cut -d'"' -f 4)


build: centos7.build

%.build:
	docker build --build-arg PHP_MAJOR=7 --build-arg PHP_MINOR=4 -t $(DOCKER_IMAGE_PREFIX)$(*) ./docker

%.shell:
	docker run --rm -ti \
		-v $$(pwd)/tracer-versions:/tracer_versions \
		$(DOCKER_IMAGE_PREFIX)$(*) \
		bash

download.rpm:
#	@echo "Downloading tracer for comparison at url: $(TRACER_COMPARE_URL)"
#	@curl --silent -L -o tracer-versions/ddtrace-compare.rpm "$(TRACER_COMPARE_URL)"
#	@echo "Done"
	@echo "Downloading tracer funder test at url: $(TRACER_TEST_URL)"
	@curl --silent -L -o tracer-versions/ddtrace-test.rpm "$(TRACER_TEST_URL)"
	@echo "Done"

generate: scenarios_clean
	@php randomizer.php

scenarios_clean:
	@rm -rf $$(pwd)/$(TMP_SCENARIO_FOLDER)/**















########### OLD #################
clean:
	docker-compose down -v
	rm -rf app/vendor

# build:
# 	docker-compose build

up: tracer-versions/datadog-php-tracer_$(TRACER_VERSION)_amd64.deb
	TRACER_VERSION=${TRACER_VERSION} docker-compose up -d

download: tracer-versions/datadog-php-tracer_$(TRACER_VERSION)_amd64.deb

tracer-versions/datadog-php-tracer_$(TRACER_VERSION)_amd64.deb:
	curl -L -o ./tracer-versions/datadog-php-tracer_$(TRACER_VERSION)_amd64.deb https://github.com/DataDog/dd-trace-php/releases/download/$(TRACER_VERSION)/datadog-php-tracer_$(TRACER_VERSION)_amd64.deb

load:
	@ echo "Running load tests for ${DURATION}."
	@ echo "GET http://localhost:8000" | vegeta attack -duration=${DURATION} -connections=20 -rate=40 | tee results.bin | vegeta report
