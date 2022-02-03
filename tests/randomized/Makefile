TMP_SCENARIO_FOLDER := ./.tmp.scenarios
TMP_RESULTS_FOLDER := $(TMP_SCENARIO_FOLDER)/.results
DOCKER_COMPOSE_FILE := $(TMP_SCENARIO_FOLDER)/docker-compose.yml
REGRESSIONS_FOLDER := ./regressions
REGRESSION_NAME := "provide-regression-name"
DOCKER_IMAGE_PREFIX := datadog/dd-trace-ci:php-randomizedtests-
LIBRARY_DOWNLOAD_PATH := ./.library-versions
CONCURRENT_JOBS := 5
DURATION := 30s
NUMBER_OF_SCENARIOS := 20

build: build.centos7

publish: publish.centos7

pull: pull.centos7

build.centos7:
	@docker build --build-arg PHP_MAJOR=8 --build-arg PHP_MINOR=0 -t $(DOCKER_IMAGE_PREFIX)centos7-8.0 ./docker
	@docker build --build-arg PHP_MAJOR=7 --build-arg PHP_MINOR=4 -t $(DOCKER_IMAGE_PREFIX)centos7-7.4 ./docker
	@docker build --build-arg PHP_MAJOR=7 --build-arg PHP_MINOR=3 -t $(DOCKER_IMAGE_PREFIX)centos7-7.3 ./docker
	@docker build --build-arg PHP_MAJOR=7 --build-arg PHP_MINOR=2 -t $(DOCKER_IMAGE_PREFIX)centos7-7.2 ./docker
	@docker build --build-arg PHP_MAJOR=7 --build-arg PHP_MINOR=1 -t $(DOCKER_IMAGE_PREFIX)centos7-7.1 ./docker
	@docker build --build-arg PHP_MAJOR=7 --build-arg PHP_MINOR=0 -t $(DOCKER_IMAGE_PREFIX)centos7-7.0 ./docker
	@docker build --build-arg PHP_MAJOR=5 --build-arg PHP_MINOR=6 -t $(DOCKER_IMAGE_PREFIX)centos7-5.6 ./docker
	@docker build --build-arg PHP_MAJOR=5 --build-arg PHP_MINOR=5 -t $(DOCKER_IMAGE_PREFIX)centos7-5.5 ./docker
	@docker build --build-arg PHP_MAJOR=5 --build-arg PHP_MINOR=4 -t $(DOCKER_IMAGE_PREFIX)centos7-5.4 ./docker

publish.centos7: build.centos7
	@docker push $(DOCKER_IMAGE_PREFIX)centos7-8.0
	@docker push $(DOCKER_IMAGE_PREFIX)centos7-7.4
	@docker push $(DOCKER_IMAGE_PREFIX)centos7-7.3
	@docker push $(DOCKER_IMAGE_PREFIX)centos7-7.2
	@docker push $(DOCKER_IMAGE_PREFIX)centos7-7.1
	@docker push $(DOCKER_IMAGE_PREFIX)centos7-7.0
	@docker push $(DOCKER_IMAGE_PREFIX)centos7-5.6
	@docker push $(DOCKER_IMAGE_PREFIX)centos7-5.5
	@docker push $(DOCKER_IMAGE_PREFIX)centos7-5.4

pull.centos7:
	@docker pull $(DOCKER_IMAGE_PREFIX)centos7-8.0
	@docker pull $(DOCKER_IMAGE_PREFIX)centos7-7.4
	@docker pull $(DOCKER_IMAGE_PREFIX)centos7-7.3
	@docker pull $(DOCKER_IMAGE_PREFIX)centos7-7.2
	@docker pull $(DOCKER_IMAGE_PREFIX)centos7-7.1
	@docker pull $(DOCKER_IMAGE_PREFIX)centos7-7.0
	@docker pull $(DOCKER_IMAGE_PREFIX)centos7-5.6
	@docker pull $(DOCKER_IMAGE_PREFIX)centos7-5.5
	@docker pull $(DOCKER_IMAGE_PREFIX)centos7-5.4

library.local:
	@mkdir -p $(LIBRARY_DOWNLOAD_PATH)
#	For now we only handle centos in randomized tests
	@cp ../../build/packages/dd-library-php-*-x86_64-linux-gnu.tar.gz $(LIBRARY_DOWNLOAD_PATH)/dd-library-php-x86_64-linux-gnu.tar.gz

library.download:
	@echo "Downloading library at url: $(LIBRARY_TEST_URL)"
	@mkdir -p $(LIBRARY_DOWNLOAD_PATH)
#	For now we only handle centos in randomized tests
	@curl --silent -L -o $(LIBRARY_DOWNLOAD_PATH)/dd-library-php-x86_64-linux-gnu.tar.gz "$(LIBRARY_TEST_URL)"
	@echo "Done"

library.version:
	@echo "Downloading library version: $(LIBRARY_VERSION)"
	@mkdir -p $(LIBRARY_DOWNLOAD_PATH)
#	For now we only handle centos in randomized tests
	@curl -L -o $(LIBRARY_DOWNLOAD_PATH)/dd-library-php-x86_64-linux-gnu.tar.gz "https://github.com/DataDog/dd-trace-php/releases/download/$(LIBRARY_VERSION)/dd-library-php-$(LIBRARY_VERSION)-x86_64-linux-gnu.tar.gz"
	@echo "Done"

generate: clean results_folder
	@php generate-scenarios.php --seed=$(SEED) --number=$(NUMBER_OF_SCENARIOS)
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

# Example: make freeze SCENARIO_NAME=randomized-12345678-centos7-7.4 REGRESSION_NAME=GH1070-origin-header
freeze:
	@rm -rf /tmp/regression-$(REGRESSION_NAME)
	@cp -r $(TMP_SCENARIO_FOLDER)/$(SCENARIO_NAME) /tmp/regression-$(REGRESSION_NAME)
	@grep -Ril "$(SCENARIO_NAME)" /tmp/regression-$(REGRESSION_NAME) | xargs sed -i '' -e 's|$(SCENARIO_NAME)|regression-$(REGRESSION_NAME)|'
	@mkdir -p $(REGRESSIONS_FOLDER)
	@cp -r /tmp/regression-$(REGRESSION_NAME) $(REGRESSIONS_FOLDER)
	@rm -rf /tmp/regression-$(REGRESSION_NAME)
	@echo "Done"
