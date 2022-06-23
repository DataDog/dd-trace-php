TMP_SCENARIO_FOLDER := ./.tmp.scenarios
TMP_RESULTS_FOLDER := $(TMP_SCENARIO_FOLDER)/.results
DOCKER_COMPOSE_FILE := $(TMP_SCENARIO_FOLDER)/docker-compose.yml
DOCKER_IMAGE_PREFIX := datadog/dd-trace-ci:php-randomizedtests-
DOCKER_IMAGE_VERSION := 2
DOCKER_IMAGE := $(DOCKER_IMAGE_PREFIX)centos7-$(*)-$(DOCKER_IMAGE_VERSION)
DOCKER_IMAGE_AARCH64 := $(DOCKER_IMAGE)-aarch64
DOCKER_IMAGE_X86_64 := $(DOCKER_IMAGE)-x86_64
LIBRARY_DOWNLOAD_PATH := ./.library-versions
CONCURRENT_JOBS := 5
DURATION := 30s
NUMBER_OF_SCENARIOS := 20
VERSIONS := *

pull: pull.centos7

#################################################################
# aarch64 section, to be build on an aarch64 machine, i.e. on AWS
#################################################################

build.centos7.aarch64: \
	build.centos7.aarch64.7.4 \
	build.centos7.aarch64.8.0 \
	build.centos7.aarch64.8.1

build.centos7.aarch64.%:
	docker build --build-arg PHP_MAJOR_MINOR="$(*)" -t $(DOCKER_IMAGE_AARCH64) ./docker

push.centos7.aarch64: \
	push.centos7.aarch64.7.4 \
	push.centos7.aarch64.8.0 \
	push.centos7.aarch64.8.1

push.centos7.aarch64.%: build.centos7.aarch64.%
	docker push $(DOCKER_IMAGE_AARCH64)

#################################################################
# end of aarch64
#################################################################

#################################################################
# x86_64 section, to be build on an x86_64 machine, i.e. on AWS
#################################################################

build.centos7.x86_64: \
	build.centos7.x86_64.5.4 \
	build.centos7.x86_64.5.5 \
	build.centos7.x86_64.5.6 \
	build.centos7.x86_64.7.0 \
	build.centos7.x86_64.7.1 \
	build.centos7.x86_64.7.2 \
	build.centos7.x86_64.7.3 \
	build.centos7.x86_64.7.4 \
	build.centos7.x86_64.8.0 \
	build.centos7.x86_64.8.1

build.centos7.x86_64.%:
	docker build --build-arg PHP_MAJOR_MINOR="$(*)" -t $(DOCKER_IMAGE_X86_64) ./docker

push.centos7.x86_64: \
	push.centos7.x86_64.5.4 \
	push.centos7.x86_64.5.5 \
	push.centos7.x86_64.5.6 \
	push.centos7.x86_64.7.0 \
	push.centos7.x86_64.7.1 \
	push.centos7.x86_64.7.2 \
	push.centos7.x86_64.7.3 \
	push.centos7.x86_64.7.4 \
	push.centos7.x86_64.8.0 \
	push.centos7.x86_64.8.1

push.centos7.x86_64.%: build.centos7.x86_64.%
	docker push $(DOCKER_IMAGE_X86_64)

#################################################################
# end of x86_64
#################################################################

manifest.create:
	docker pull $(DOCKER_IMAGE_AARCH64)
	docker pull $(DOCKER_IMAGE_X86_64)
	docker manifest create \
		$(DOCKER_IMAGE) \
		--amend \
			$(DOCKER_IMAGE_AARCH64) \
			$(DOCKER_IMAGE_X86_64)

manifest.push: manifest.create
	docker manifest push $(DOCKER_IMAGE)

pull.centos7: \
	pull.centos7.5.4 \
	pull.centos7.5.5 \
	pull.centos7.5.6 \
	pull.centos7.7.0 \
	pull.centos7.7.1 \
	pull.centos7.7.2 \
	pull.centos7.7.3 \
	pull.centos7.7.4 \
	pull.centos7.8.0 \
	pull.centos7.8.1

pull.centos7.%:
	@docker pull $(DOCKER_IMAGE_PREFIX)centos7-$(*)-$(DOCKER_IMAGE_VERSION)

library.local:
	@mkdir -p $(LIBRARY_DOWNLOAD_PATH)
#	For now we only handle centos in randomized tests
	@cp ../../build/packages/dd-library-php-*-x86_64-linux-gnu.tar.gz $(LIBRARY_DOWNLOAD_PATH)/dd-library-php-x86_64-linux-gnu.tar.gz

library.local.arm:
	@mkdir -p $(LIBRARY_DOWNLOAD_PATH)
#	For now we only handle centos in randomized tests
	@cp ../../build/packages/datadog-php-tracer-*-arm64*.tar.gz $(LIBRARY_DOWNLOAD_PATH)/dd-library-php-arm64-linux-gnu.tar.gz

library.download:
	@echo "Downloading library at url: $(LIBRARY_TEST_URL)"
	@mkdir -p $(LIBRARY_DOWNLOAD_PATH)
#	For now we only handle centos in randomized tests
	@curl -L -o $(LIBRARY_DOWNLOAD_PATH)/dd-library-php-x86_64-linux-gnu.tar.gz "$(LIBRARY_TEST_URL)"
	@echo "Done"

library.version:
	@echo "Downloading library version: $(LIBRARY_VERSION)"
	@mkdir -p $(LIBRARY_DOWNLOAD_PATH)
#	For now we only handle centos in randomized tests
	@curl -L -o $(LIBRARY_DOWNLOAD_PATH)/dd-library-php-x86_64-linux-gnu.tar.gz "https://github.com/DataDog/dd-trace-php/releases/download/$(LIBRARY_VERSION)/dd-library-php-$(LIBRARY_VERSION)-x86_64-linux-gnu.tar.gz"
	@echo "Done"

generate: clean results_folder
	@php generate-scenarios.php --seed=$(SEED) --number=$(NUMBER_OF_SCENARIOS) --versions=$(VERSIONS)
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

.PHONY: build.centos7.aarch64 push.centos7.aarch64 \
		build.centos7.x86_64 push.centos7.x86_64 \
		pull.centos7 \
		manifest.create manifest.push
