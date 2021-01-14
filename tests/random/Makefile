TMP_SCENARIO_FOLDER := ./.tmp.scenarios
TMP_RESULTS_FOLDER := $(TMP_SCENARIO_FOLDER)/.results
DURATION := 30s
TRACER_VERSION := 0.49.0
DOCKER_IMAGE_PREFIX := dd-random-testing:
TRACER_TEST_URL := https://303308-119990860-gh.circle-artifacts.com/0/datadog-php-tracer-1.0.0-nightly.x86_64.tar.gz
# TRACER_COMPARE_URL := $(shell curl --silent https://api.github.com/repos/DataDog/dd-trace-php/releases/latest | grep -e 'browser_download_url.*\.tar\.gz' | cut -d'"' -f 4)
TRACER_DOWNLOAD_PATH := ./.tracer-versions

build: centos7.build

%.build:
	docker build --build-arg PHP_MAJOR=7 --build-arg PHP_MINOR=4 -t $(DOCKER_IMAGE_PREFIX)$(*) ./docker

%.shell: %.build
	docker run --rm -ti \
		$(DOCKER_IMAGE_PREFIX)$(*) \
		bash

download:
#	@echo "Downloading tracer for comparison at url: $(TRACER_COMPARE_URL)"
#	@curl --silent -L -o tracer-versions/ddtrace-compare.tar.gz "$(TRACER_COMPARE_URL)"
#	@echo "Done"
	@echo "Downloading tracer funder test at url: $(TRACER_TEST_URL)"
	@mkdir -p ${TRACER_DOWNLOAD_PATH}
	@curl --silent -L -o ${TRACER_DOWNLOAD_PATH}/ddtrace-test.tar.gz "$(TRACER_TEST_URL)"
	@echo "Done"

generate: scenarios_clean results_folder
	@php generate-scenarios.php
	@cp -r ${TRACER_DOWNLOAD_PATH} $$(pwd)/$(TMP_SCENARIO_FOLDER)

scenarios_clean:
	@rm -rf $$(pwd)/$(TMP_SCENARIO_FOLDER)/**

results_folder:
	@mkdir -p $(TMP_RESULTS_FOLDER)













########### OLD #################
# clean:
# 	docker-compose down -v
# 	rm -rf app/vendor

# # build:
# # 	docker-compose build

# up: tracer-versions/datadog-php-tracer_$(TRACER_VERSION)_amd64.deb
# 	TRACER_VERSION=${TRACER_VERSION} docker-compose up -d

# # download: tracer-versions/datadog-php-tracer_$(TRACER_VERSION)_amd64.deb

# tracer-versions/datadog-php-tracer_$(TRACER_VERSION)_amd64.deb:
# 	curl -L -o ./tracer-versions/datadog-php-tracer_$(TRACER_VERSION)_amd64.deb https://github.com/DataDog/dd-trace-php/releases/download/$(TRACER_VERSION)/datadog-php-tracer_$(TRACER_VERSION)_amd64.deb

# load:
# 	@ echo "Running load tests for ${DURATION}."
# 	@ echo "GET http://localhost:8000" | vegeta attack -duration=${DURATION} -connections=20 -rate=40 | tee results.bin | vegeta report
