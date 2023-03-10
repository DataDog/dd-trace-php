TMP_SCENARIO_FOLDER := ./.tmp.scenarios
TMP_RESULTS_FOLDER := $(TMP_SCENARIO_FOLDER)/.results
DOCKER_COMPOSE_FILE := $(TMP_SCENARIO_FOLDER)/docker-compose.yml
DOCKER_IMAGE_PREFIX := datadog/dd-trace-ci:php-randomizedtests-
DOCKER_IMAGE_VERSION := 2
LIBRARY_DOWNLOAD_PATH := ./.library-versions
CONCURRENT_JOBS := 5
DURATION := 30s
NUMBER_OF_SCENARIOS := 10
VERSIONS := *
PLATFORMS := *
ARCHITECTURE := $(shell uname -m)

publish: publish.centos7 publish.buster

pull: pull.centos7 pull.buster

publish.centos7: \
	publish.centos7.7.0 \
	publish.centos7.7.1 \
	publish.centos7.7.2 \
	publish.centos7.7.3 \
	publish.centos7.7.4 \
	publish.centos7.8.0 \
	publish.centos7.8.1 \
	publish.centos7.8.2

publish.buster: \
	publish.buster.7.4 \
	publish.buster.8.0 \
	publish.buster.8.1 \
	publish.buster.8.2

publish.centos7.%: build.centos7.%
	docker buildx bake --push php-$(*)

pull.centos7: \
	pull.centos7.7.0 \
	pull.centos7.7.1 \
	pull.centos7.7.2 \
	pull.centos7.7.3 \
	pull.centos7.7.4 \
	pull.centos7.8.0 \
	pull.centos7.8.1 \
	pull.centos7.8.2

pull.buster: \
	pull.buster.7.4 \
	pull.buster.8.0 \
	pull.buster.8.1 \
	pull.buster.8.2

pull.centos7.%:
	@docker pull $(DOCKER_IMAGE_PREFIX)centos7-$(*)-$(DOCKER_IMAGE_VERSION)

pull.buster.%:
	@docker pull $(DOCKER_IMAGE_PREFIX)buster-$(*)-$(DOCKER_IMAGE_VERSION)

library.local:
	@mkdir -p $(LIBRARY_DOWNLOAD_PATH)
#	For now we only handle centos in randomized tests
	@cp ../../build/packages/dd-library-php-*-$(ARCHITECTURE)-linux-gnu.tar.gz $(LIBRARY_DOWNLOAD_PATH)/dd-library-php.tar.gz

library.download:
	@echo "Downloading library at url: $(LIBRARY_TEST_URL)"
	@mkdir -p $(LIBRARY_DOWNLOAD_PATH)
#	For now we only handle centos in randomized tests
	@curl -L -o $(LIBRARY_DOWNLOAD_PATH)/dd-library-php.tar.gz "$(LIBRARY_TEST_URL)"
	@echo "Done"

library.version:
	@echo "Downloading library version: $(LIBRARY_VERSION)"
	@mkdir -p $(LIBRARY_DOWNLOAD_PATH)
#	For now we only handle glibc in randomized tests
	@curl -L -o $(LIBRARY_DOWNLOAD_PATH)/dd-library-php.tar.gz "https://github.com/DataDog/dd-trace-php/releases/download/$(LIBRARY_VERSION)/dd-library-php-$(LIBRARY_VERSION)-$(ARCHITECTURE)-linux-gnu.tar.gz"
	@echo "Done"

generate: clean results_folder
	@php generate-scenarios.php --seed=$(SEED) --number=$(NUMBER_OF_SCENARIOS) --versions=$(VERSIONS) --platforms=$(PLATFORMS)
	@cp -r $(LIBRARY_DOWNLOAD_PATH) $$(pwd)/$(TMP_SCENARIO_FOLDER)

generate.%: clean results_folder
	@php generate-scenarios.php --scenario $(*)
	@cp -r $(LIBRARY_DOWNLOAD_PATH) $$(pwd)/$(TMP_SCENARIO_FOLDER)

scenarios_clean:
	@rm -rf $$(pwd)/$(TMP_SCENARIO_FOLDER)/**

results_folder:
	@mkdir -p $(TMP_RESULTS_FOLDER)

test: clean_results
	@echo "Starting tests with $(CONCURRENT_JOBS) concurrent runs."
	@make -C $(TMP_SCENARIO_FOLDER) --jobs=$(CONCURRENT_JOBS) test DURATION=$(DURATION)

analyze:
	@php analyze-results.php .tmp.scenarios/.results

clean:
	@rm -rf $(TMP_SCENARIO_FOLDER)

clean_results:
	@rm -rf $(TMP_RESULTS_FOLDER)

.PHONY: build.centos7 publish.centos7 pull.centos7
