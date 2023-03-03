Q := @
PROJECT_ROOT := $(shell pwd)
REQUEST_INIT_HOOK_PATH := $(PROJECT_ROOT)/bridge/dd_wrap_autoloader.php
SHELL := /bin/bash
BUILD_SUFFIX := extension
BUILD_DIR := $(PROJECT_ROOT)/tmp/build_$(BUILD_SUFFIX)
ZAI_BUILD_DIR := $(PROJECT_ROOT)/tmp/build_zai
TEA_BUILD_DIR := $(PROJECT_ROOT)/tmp/build_tea
TEA_INSTALL_DIR := $(TEA_BUILD_DIR)/opt
TEA_BUILD_TESTS := ON
COMPONENTS_BUILD_DIR := $(PROJECT_ROOT)/tmp/build_components
SO_FILE := $(BUILD_DIR)/modules/ddtrace.so
WALL_FLAGS := -Wall -Wextra
CFLAGS := $(shell [ -n "${DD_TRACE_DOCKER_DEBUG}" ] && echo -O0 || echo -O2) -g $(WALL_FLAGS)
ROOT_DIR:=$(shell dirname $(realpath $(lastword $(MAKEFILE_LIST))))
PHP_EXTENSION_DIR=$(shell php -r 'print ini_get("extension_dir");')
PHP_MAJOR_MINOR:=$(shell php -r 'echo PHP_MAJOR_VERSION . PHP_MINOR_VERSION;')
PHP_MAJOR_DOT_MINOR:=$(shell php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
ARCHITECTURE=$(shell uname -m)

VERSION := $(shell awk -F\' '/const VERSION/ {print $$2}' < src/DDTrace/Tracer.php)
PROFILING_RELEASE_URL := https://github.com/DataDog/dd-prof-php/releases/download/v0.7.2/datadog-profiling.tar.gz
APPSEC_RELEASE_URL := https://github.com/DataDog/dd-appsec-php/releases/download/v0.6.0/dd-appsec-php-0.6.0-amd64.tar.gz

INI_FILE := $(shell php -i | awk -F"=>" '/Scan this dir for additional .ini files/ {print $$2}')/ddtrace.ini

RUN_TESTS_IS_PARALLEL := $(shell test $(shell php-config --vernum) -gt 70399 && echo 1 || echo 0)

ifeq ($(RUN_TESTS_IS_PARALLEL), 1)
RUN_TESTS_EXTRA_ARGS := -j$(shell nproc)
else
RUN_TESTS_EXTRA_ARGS :=
endif

RUN_TESTS_CMD := REPORT_EXIT_STATUS=1 TEST_PHP_SRCDIR=$(PROJECT_ROOT) USE_TRACKED_ALLOC=1 php -n -d 'memory_limit=-1' $(BUILD_DIR)/run-tests.php -g FAIL,XFAIL,BORK,WARN,LEAK,XLEAK,SKIP --show-diff -n -p $(shell which php) -q $(RUN_TESTS_EXTRA_ARGS)

C_FILES := $(shell find components ext src/dogstatsd zend_abstract_interface -name '*.c' -o -name '*.h' | awk '{ printf "$(BUILD_DIR)/%s\n", $$1 }' )
TEST_FILES := $(shell find tests/ext -name '*.php*' -o -name '*.inc' -o -name '*.json' -o -name 'CONFLICTS' | awk '{ printf "$(BUILD_DIR)/%s\n", $$1 }' )
TEST_OPCACHE_FILES := $(shell find tests/opcache -name '*.php*' -o -name '.gitkeep' | awk '{ printf "$(BUILD_DIR)/%s\n", $$1 }' )
TEST_STUB_FILES := $(shell find tests/ext -type d -name 'stubs' -exec find '{}' -type f \; | awk '{ printf "$(BUILD_DIR)/%s\n", $$1 }' )
INIT_HOOK_TEST_FILES := $(shell find tests/C2PHP -name '*.phpt' -o -name '*.inc' | awk '{ printf "$(BUILD_DIR)/%s\n", $$1 }' )
M4_FILES := $(shell find m4 -name '*.m4*' | awk '{ printf "$(BUILD_DIR)/%s\n", $$1 }' )

# The following differentiation exists so we can build only (but always) the relevant files while executing tests
#  - when a `.phpt` changes, we only copy the file to the build dir and we DO NOT rebuild
#  - when a `.c` changes, we copy the file to the build dir and we DO the specific .lo build and linking
#  - when a `.h` (or anything else) changes, we remove all .lo files from the build directory so a full build is done
# The latter, avoids that during development we change something in a header included in multiple .c files, then we
# change only one of those .c files, we only rebuild that one, we SEGFAULT.
#
# Note: while this adds some complexity, as a matter of facts it does not impact production builds in CI which are done
# from scratch. But the benefits are that we can have the quickest possible `modify --> test` cycle possible.
$(BUILD_DIR)/tests/%: tests/%
	$(Q) echo Copying tests/$* to $@
	$(Q) mkdir -p $(dir $@)
	$(Q) cp -a tests/$* $@

$(BUILD_DIR)/%.c: %.c
	$(Q) echo Copying $*.c to $@
	$(Q) mkdir -p $(dir $@)
	$(Q) cp -a $*.c $@

$(BUILD_DIR)/%: %
	$(Q) echo Copying $* to $@
	$(Q) mkdir -p $(dir $@)
	$(Q) cp -a $* $@
	$(Q) rm -f tmp/build_extension/ext/**/*.lo

JUNIT_RESULTS_DIR := $(shell pwd)

all: $(BUILD_DIR)/configure $(SO_FILE)

$(BUILD_DIR)/config.m4: $(M4_FILES) $(BUILD_DIR)/ddtrace.sym

$(BUILD_DIR)/configure: $(BUILD_DIR)/config.m4
	$(Q) (cd $(BUILD_DIR); phpize && sed -i 's/\/FAILED/\/\\bFAILED/' $(BUILD_DIR)/run-tests.php) # Fix PHP 5.4 exit code bug when running selected tests (FAILED vs XFAILED)

$(BUILD_DIR)/Makefile: $(BUILD_DIR)/configure
	$(Q) (cd $(BUILD_DIR); ./configure)

$(SO_FILE): $(C_FILES) $(BUILD_DIR)/Makefile
	$(Q) $(MAKE) -C $(BUILD_DIR) -j CFLAGS="$(CFLAGS)"

asan: $(C_FILES) $(BUILD_DIR)/Makefile
	$(MAKE) -C $(BUILD_DIR) -j CFLAGS="$(CFLAGS) -fsanitize=address" LDFLAGS="-fsanitize=address"

$(PHP_EXTENSION_DIR)/ddtrace.so: $(SO_FILE)
	$(Q) $(SUDO) $(MAKE) -C $(BUILD_DIR) install

install: $(PHP_EXTENSION_DIR)/ddtrace.so

$(INI_FILE):
	$(Q) echo "extension=ddtrace.so" | $(SUDO) tee -a $@

install_ini: $(INI_FILE)

install_all: install install_ini

run_tests: $(TEST_FILES) $(TEST_STUB_FILES) $(BUILD_DIR)/configure
	$(RUN_TESTS_CMD) $(BUILD_DIR)/$(TESTS)

test_c: $(SO_FILE) $(TEST_FILES) $(TEST_STUB_FILES)
	DD_TRACE_CLI_ENABLED=1 $(RUN_TESTS_CMD) -d extension=$(SO_FILE) $(BUILD_DIR)/$(TESTS)

test_c_coverage: dist_clean
	DD_TRACE_DOCKER_DEBUG=1 EXTRA_CFLAGS="-fprofile-arcs -ftest-coverage" $(MAKE) test_c || exit 0

test_c_disabled: $(SO_FILE) $(TEST_FILES) $(TEST_STUB_FILES)
	( \
	DD_TRACE_CLI_ENABLED=0 DD_TRACE_DEBUG=1 $(RUN_TESTS_CMD) -d extension=$(SO_FILE) $(BUILD_DIR)/$(TESTS) || true; \
	! grep -E 'Successfully triggered flush with trace of size|=== Total [0-9]+ memory leaks detected ===|Segmentation fault' $$(find $(BUILD_DIR)/$(TESTS) -name "*.out" | grep -v segfault_backtrace_enabled.out); \
	)

test_opcache: $(SO_FILE) $(TEST_OPCACHE_FILES)
	DD_TRACE_CLI_ENABLED=1 $(RUN_TESTS_CMD) -d extension=$(SO_FILE) -d zend_extension=opcache.so $(BUILD_DIR)/tests/opcache

test_c_mem: export DD_TRACE_CLI_ENABLED=1
test_c_mem: $(SO_FILE) $(TEST_FILES) $(TEST_STUB_FILES)
	$(RUN_TESTS_CMD) -d extension=$(SO_FILE) -m $(BUILD_DIR)/$(TESTS)

test_c2php: $(SO_FILE) $(INIT_HOOK_TEST_FILES)
	( \
	set -xe; \
	export DD_TRACE_CLI_ENABLED=1; \
	export USE_ZEND_ALLOC=0; \
	export ZEND_DONT_UNLOAD_MODULES=1; \
	export USE_TRACKED_ALLOC=1; \
	$(shell grep -Pzo '(?<=--ENV--)(?s).+?(?=--)' $(INIT_HOOK_TEST_FILES)) valgrind -q --tool=memcheck --trace-children=yes --vex-iropt-register-updates=allregs-at-mem-access php -n -d extension=$(SO_FILE) -d ddtrace.request_init_hook=$$(pwd)/bridge/dd_wrap_autoloader.php $(INIT_HOOK_TEST_FILES); \
	)

test_with_init_hook_asan: $(SO_FILE) $(INIT_HOOK_TEST_FILES)
	( \
	set -xe; \
	export DD_TRACE_CLI_ENABLED=1; \
	export TEST_PHP_JUNIT=$(JUNIT_RESULTS_DIR)/asan-extension-init-hook-test.xml; \
	$(MAKE) -C $(BUILD_DIR) CFLAGS="-g -fsanitize=address" LDFLAGS="-fsanitize=address" clean all; \
	$(RUN_TESTS_CMD) -d extension=$(SO_FILE) -d ddtrace.request_init_hook=$$(pwd)/bridge/dd_wrap_autoloader.php --asan $(INIT_HOOK_TEST_FILES); \
	)

test_c_asan: $(SO_FILE) $(TEST_FILES) $(TEST_STUB_FILES)
	( \
	set -xe; \
	export TEST_PHP_JUNIT=$(JUNIT_RESULTS_DIR)/asan-extension-test.xml; \
	$(MAKE) -C $(BUILD_DIR) CFLAGS="-g -fsanitize=address" LDFLAGS="-fsanitize=address" clean all; \
	USE_ZEND_ALLOC=0 DD_TRACE_CLI_ENABLED=1 $(RUN_TESTS_CMD) -d extension=$(SO_FILE) --asan $(BUILD_DIR)/$(TESTS); \
	)

test_opcache_asan: $(SO_FILE) $(TEST_OPCACHE_FILES)
	( \
	set -xe; \
	export TEST_PHP_JUNIT=$(JUNIT_RESULTS_DIR)/asan-extension-test.xml; \
	$(MAKE) -C $(BUILD_DIR) CFLAGS="-g -fsanitize=address" LDFLAGS="-fsanitize=address" clean all; \
	USE_ZEND_ALLOC=0 DD_TRACE_CLI_ENABLED=1 $(RUN_TESTS_CMD) -d extension=$(SO_FILE) -d zend_extension=opcache.so $(BUILD_DIR)/tests/opcache \
	)

test_extension_ci: $(SO_FILE) $(TEST_FILES) $(TEST_STUB_FILES)
	( \
	set -xe; \
	export DD_TRACE_CLI_ENABLED=1; \
	export TEST_PHP_JUNIT=$(JUNIT_RESULTS_DIR)/normal-extension-test.xml; \
	$(MAKE) -C $(BUILD_DIR) CFLAGS="-g" clean all; \
	$(RUN_TESTS_CMD) -d extension=$(SO_FILE) $(BUILD_DIR)/$(TESTS); \
	\
	export TEST_PHP_JUNIT=$(JUNIT_RESULTS_DIR)/valgrind-extension-test.xml; \
	export TEST_PHP_OUTPUT=$(JUNIT_RESULTS_DIR)/valgrind-run-tests.out; \
	$(RUN_TESTS_CMD) -d extension=$(SO_FILE) -m -s $$TEST_PHP_OUTPUT $(BUILD_DIR)/$(TESTS) && ! grep -e 'LEAKED TEST SUMMARY' $$TEST_PHP_OUTPUT; \
	)

build_tea:
	$(Q) test -f $(TEA_BUILD_DIR)/.built || \
	( \
		mkdir -p "$(TEA_BUILD_DIR)" "$(TEA_INSTALL_DIR)"; \
		cd $(TEA_BUILD_DIR); \
		CMAKE_PREFIX_PATH=/opt/catch2 \
		cmake \
			-DCMAKE_INSTALL_PREFIX=$(TEA_INSTALL_DIR) \
			-DCMAKE_BUILD_TYPE=Debug \
			-DBUILD_TEA_TESTING=$(TEA_BUILD_TESTS) \
			-DPHP_CONFIG=$(shell which php-config) \
		$(PROJECT_ROOT)/tea; \
		$(MAKE) $(MAKEFLAGS) && touch $(TEA_BUILD_DIR)/.built; \
	)

test_tea: clean_tea build_tea
	( \
	$(MAKE) -C $(TEA_BUILD_DIR) test; \
	! grep -e "=== Total .* memory leaks detected ===" $(TEA_BUILD_DIR)/Testing/Temporary/LastTest.log; \
	)

install_tea: build_tea
	$(Q) test -f $(TEA_BUILD_DIR)/.installed || \
	( \
		$(MAKE) -C $(TEA_BUILD_DIR) install; \
		touch $(TEA_BUILD_DIR)/.installed; \
	)

build_tea_asan:
	$(Q) test -f $(TEA_BUILD_DIR)/.built.asan || \
	( \
		mkdir -p "$(TEA_BUILD_DIR)" "$(TEA_INSTALL_DIR)"; \
		cd $(TEA_BUILD_DIR); \
		CMAKE_PREFIX_PATH=/opt/catch2 \
		cmake \
			-DCMAKE_INSTALL_PREFIX=$(TEA_INSTALL_DIR) \
			-DCMAKE_BUILD_TYPE=Debug \
			-DBUILD_TEA_TESTING=$(TEA_BUILD_TESTS) \
			-DCMAKE_TOOLCHAIN_FILE=$(PROJECT_ROOT)/cmake/asan.cmake \
			-DPHP_CONFIG=$(shell which php-config) \
		$(PROJECT_ROOT)/tea; \
		$(MAKE) $(MAKEFLAGS) && touch $(TEA_BUILD_DIR)/.built.asan; \
	)

test_tea_asan: clean_tea build_tea_asan
	( \
	$(MAKE) -C $(TEA_BUILD_DIR) test; \
	! grep -e "=== Total .* memory leaks detected ===" $(TEA_BUILD_DIR)/Testing/Temporary/LastTest.log; \
	)

install_tea_asan: build_tea_asan
	$(Q) test -f $(TEA_BUILD_DIR)/.installed.asan || \
	( \
		$(MAKE) -C $(TEA_BUILD_DIR) install; \
		touch $(TEA_BUILD_DIR)/.installed.asan; \
	)

build_tea_coverage:
	$(Q) test -f $(TEA_BUILD_DIR)/.built.coverage || \
	( \
		mkdir -p "$(TEA_BUILD_DIR)" "$(TEA_INSTALL_DIR)"; \
		cd $(TEA_BUILD_DIR); \
		CMAKE_PREFIX_PATH=/opt/catch2 \
		cmake \
			-DCMAKE_INSTALL_PREFIX=$(TEA_INSTALL_DIR) \
			-DCMAKE_BUILD_TYPE=Debug \
			-DBUILD_TEA_TESTING=$(TEA_BUILD_TESTS) \
			-DCMAKE_C_FLAGS="-O0 --coverage" \
			-DPHP_CONFIG=$(shell which php-config) \
		$(PROJECT_ROOT)/tea; \
		$(MAKE) $(MAKEFLAGS) && touch $(TEA_BUILD_DIR)/.built.coverage; \
	)

test_tea_coverage: clean_tea build_tea_coverage
	( \
	$(MAKE) -C $(TEA_BUILD_DIR) test; \
	! grep -e "=== Total .* memory leaks detected ===" $(TEA_BUILD_DIR)/Testing/Temporary/LastTest.log; \
	)

install_tea_coverage: build_tea_coverage
	$(Q) test -f $(TEA_BUILD_DIR)/.installed.coverage || \
	( \
		$(MAKE) -C $(TEA_BUILD_DIR) install; \
		touch $(TEA_BUILD_DIR)/.installed.coverage; \
	)

clean_tea:
	rm -rf $(TEA_BUILD_DIR)

build_zai: install_tea
	( \
	mkdir -p "$(ZAI_BUILD_DIR)"; \
	cd $(ZAI_BUILD_DIR); \
	CMAKE_PREFIX_PATH=/opt/catch2 \
	Tea_ROOT=$(TEA_INSTALL_DIR) \
	cmake -DCMAKE_BUILD_TYPE=Debug -DBUILD_ZAI_TESTING=ON -DPHP_CONFIG=$(shell which php-config) $(PROJECT_ROOT)/zend_abstract_interface; \
	$(MAKE) $(MAKEFLAGS); \
	)

test_zai: build_zai
	$(MAKE) -C $(ZAI_BUILD_DIR) test $(shell [ -z "${TESTS}"] || echo "ARGS='--test-dir ${TESTS}'") && ! grep -e "=== Total .* memory leaks detected ===" $(ZAI_BUILD_DIR)/Testing/Temporary/LastTest.log

build_zai_asan: install_tea_asan
	( \
	mkdir -p "$(ZAI_BUILD_DIR)"; \
	cd $(ZAI_BUILD_DIR); \
	CMAKE_PREFIX_PATH=/opt/catch2 \
	Tea_ROOT=$(TEA_INSTALL_DIR) \
	cmake \
		-DCMAKE_BUILD_TYPE=Debug \
		-DBUILD_ZAI_TESTING=ON \
		-DCMAKE_TOOLCHAIN_FILE=$(PROJECT_ROOT)/cmake/asan.cmake \
		-DPHP_CONFIG=$(shell which php-config) \
	$(PROJECT_ROOT)/zend_abstract_interface; \
	$(MAKE) clean $(MAKEFLAGS); \
	$(MAKE) -j $(MAKEFLAGS); \
	)

test_zai_asan: build_zai_asan
	$(MAKE) -C $(ZAI_BUILD_DIR) test $(shell [ -z "${TESTS}"] || echo "ARGS='--test-dir ${TESTS}'") USE_ZEND_ALLOC=0 USE_TRACKED_ALLOC=1 && ! grep -e "=== Total .* memory leaks detected ===" $(ZAI_BUILD_DIR)/Testing/Temporary/LastTest.log

build_zai_coverage: install_tea_coverage
	( \
	mkdir -p "$(ZAI_BUILD_DIR)"; \
	cd $(ZAI_BUILD_DIR); \
	CMAKE_PREFIX_PATH=/opt/catch2 \
	Tea_ROOT=$(TEA_INSTALL_DIR) \
	cmake -DCMAKE_BUILD_TYPE=Debug -DCMAKE_C_FLAGS="-O0 --coverage" -DBUILD_ZAI_TESTING=ON -DPHP_CONFIG=$(shell which php-config) $(PROJECT_ROOT)/zend_abstract_interface; \
	$(MAKE) $(MAKEFLAGS); \
	)

test_zai_coverage: build_zai_coverage
	$(MAKE) -C $(ZAI_BUILD_DIR) test $(shell [ -z "${TESTS}"] || echo "ARGS='--test-dir ${TESTS}'") && ! grep -e "=== Total .* memory leaks detected ===" $(ZAI_BUILD_DIR)/Testing/Temporary/LastTest.log

clean_zai:
	rm -rf $(ZAI_BUILD_DIR)

build_components_coverage:
	( \
	mkdir -p "$(COMPONENTS_BUILD_DIR)"; \
	cd $(COMPONENTS_BUILD_DIR); \
	CMAKE_PREFIX_PATH=/opt/catch2 cmake -DCMAKE_BUILD_TYPE=Debug -DDATADOG_PHP_TESTING=ON -DCMAKE_C_FLAGS="-O0 --coverage" $(PROJECT_ROOT)/components; \
	$(MAKE) $(MAKEFLAGS); \
	)

test_components_coverage: build_components_coverage
	$(MAKE) -C $(COMPONENTS_BUILD_DIR) test $(shell [ -z "${TESTS}" ] || echo "ARGS='--test-dir ${TESTS}'") && ! grep -e "=== Total .* memory leaks detected ===" $(COMPONENTS_BUILD_DIR)/Testing/Temporary/LastTest.log

test_coverage_collect:
	$(Q) lcov \
		--directory $(PROJECT_ROOT)/tmp \
		--capture \
		--exclude "/usr/include/*.h" \
		--exclude "/usr/include/*/*/*" \
		--exclude "/opt/php/*/include/php/Zend/*" \
		--exclude "$(BUILD_DIR)/components/*/*" \
		--exclude "$(BUILD_DIR)/zend_abstract_interface/*/*" \
		--exclude "$(BUILD_DIR)/ext/vendor/*/*" \
		--exclude "$(BUILD_DIR)/src/dogstatsd/*" \
		--exclude "$(BUILD_DIR)/src/dogstatsd/dogstatsd_client/*" \
		--output-file $(PROJECT_ROOT)/tmp/coverage.info
	$(Q) sed -i 's+tmp/build_extension/ext+ext+g' $(PROJECT_ROOT)/tmp/coverage.info

test_coverage_output:
	$(Q) genhtml \
		--legend \
		--title "PHP v$(shell php-config --version) / dd-trace-php combined coverage" \
		-o $(PROJECT_ROOT)/coverage \
		--prefix $(PROJECT_ROOT) \
		$(PROJECT_ROOT)/tmp/coverage.info

test_coverage: dist_clean test_components_coverage test_tea_coverage test_zai_coverage test_c_coverage test_coverage_collect test_coverage_output

clean_components:
	rm -rf $(COMPONENTS_BUILD_DIR)

dist_clean:
	rm -rf $(BUILD_DIR) $(TEA_BUILD_DIR) $(ZAI_BUILD_DIR) $(COMPONENTS_BUILD_DIR)

clean:
	if [[ -f "$(BUILD_DIR)/Makefile" ]]; then $(MAKE) -C $(BUILD_DIR) clean; fi
	rm -f $(BUILD_DIR)/configure*
	rm -f $(SO_FILE)
	rm -f composer.lock

sudo:
	$(eval SUDO:=sudo)

debug:
	$(eval CFLAGS=$(CFLAGS) -O0 -g)

prod:
	$(eval CFLAGS=$(CFLAGS) -O2 -g0)

strict:
	$(eval CFLAGS=-Wall -Werror -Wextra)

clang_find_files_to_lint:
	@find . \( \
	-path ./.git -prune -o \
	-path ./tmp -prune -o \
	-path ./vendor -prune -o \
	-path ./tests -prune -o \
	-path ./ext/vendor/mpack -prune -o \
	-path ./ext/vendor/mt19937 -prune -o \
	-path ./tooling/generation -prune -o \
	-type f \) \
	\( -iname "*.h" -o -iname "*.c" \)

CLANG_FORMAT := clang-format-6.0

clang_format_check:
	@while read fname; do \
		changes=$$($(CLANG_FORMAT) -output-replacements-xml $$fname | grep -c "<replacement " || true); \
		if [ $$changes != 0 ]; then \
			$(CLANG_FORMAT) -output-replacements-xml $$fname; \
			echo "$$fname did not pass clang-format, consider running: make clang_format_fix"; \
			touch .failure; \
		fi \
	done <<< $$($(MAKE) clang_find_files_to_lint)

clang_format_fix:
	$(MAKE) clang_find_files_to_lint | xargs clang-format -i

EXT_DIR:=/opt/datadog-php
PACKAGE_NAME:=datadog-php-tracer
FPM_INFO_OPTS=-a $(ARCHITECTURE) -n $(PACKAGE_NAME) -m dev@datadoghq.com --license "BSD 3-Clause License" --version $(VERSION) \
	--provides $(PACKAGE_NAME) --vendor DataDog  --url "https://docs.datadoghq.com/tracing/setup/php/" --no-depends
FPM_DIR_OPTS=--directories $(EXT_DIR)/etc --config-files $(EXT_DIR)/etc -s dir
FPM_FILES=extensions_$(shell test $(ARCHITECTURE) = arm64 && echo aarch64 || echo $(ARCHITECTURE))/=$(EXT_DIR)/extensions \
	package/post-install.sh=$(EXT_DIR)/bin/post-install.sh package/ddtrace.ini.example=$(EXT_DIR)/etc/ \
	docs=$(EXT_DIR)/docs README.md=$(EXT_DIR)/docs/README.md UPGRADE-0.10.md=$(EXT_DIR)/docs/UPGRADE-0.10.md\
	src=$(EXT_DIR)/dd-trace-sources \
	bridge=$(EXT_DIR)/dd-trace-sources
FPM_OPTS=$(FPM_INFO_OPTS) $(FPM_DIR_OPTS) --after-install=package/post-install.sh

PACKAGES_BUILD_DIR:=build/packages

$(PACKAGES_BUILD_DIR):
	mkdir -p "$@"

.deb.%: ARCHITECTURE=$(*)
.deb.%: $(PACKAGES_BUILD_DIR)
	fpm -p $(PACKAGES_BUILD_DIR) -t deb $(FPM_OPTS) $(FPM_FILES)
.rpm.%: ARCHITECTURE=$(*)
.rpm.%: $(PACKAGES_BUILD_DIR)
	fpm -p $(PACKAGES_BUILD_DIR) -t rpm $(FPM_OPTS) $(FPM_FILES)
.apk.%: ARCHITECTURE=$(*)
.apk.%: $(PACKAGES_BUILD_DIR)
	fpm -p $(PACKAGES_BUILD_DIR) -t apk $(FPM_OPTS) --depends=bash --depends=curl $(FPM_FILES)

# Example .tar.gz.aarch64, .tar.gz.x86_64
.tar.gz.%: ARCHITECTURE=$(*)
.tar.gz.%: $(PACKAGES_BUILD_DIR)
	mkdir -p /tmp/$(PACKAGES_BUILD_DIR)
	rm -rf /tmp/$(PACKAGES_BUILD_DIR)/*
	fpm -p /tmp/$(PACKAGES_BUILD_DIR)/$(PACKAGE_NAME)-$(VERSION) -t dir $(FPM_OPTS) $(FPM_FILES)
	tar zcf $(PACKAGES_BUILD_DIR)/$(PACKAGE_NAME)-$(VERSION).$(ARCHITECTURE).tar.gz -C /tmp/$(PACKAGES_BUILD_DIR)/$(PACKAGE_NAME)-$(VERSION) . --owner=0 --group=0

bundle.tar.gz: $(PACKAGES_BUILD_DIR)
	bash ./tooling/bin/generate-final-artifact.sh \
		$(VERSION) \
		$(PACKAGES_BUILD_DIR) \
		$(PROFILING_RELEASE_URL) \
		$(APPSEC_RELEASE_URL)
	bash ./tooling/bin/generate-installers.sh \
		$(VERSION) \
		$(PACKAGES_BUILD_DIR)

build_pecl_package:
	BUILD_DIR='$(BUILD_DIR)/'; \
	FILES="$(C_FILES) $(TEST_FILES) $(TEST_STUB_FILES) $(M4_FILES)"; \
	tooling/bin/pecl-build $${FILES//$${BUILD_DIR}/}

packages: .apk.x86_64 .apk.aarch64 .rpm.x86_64 .rpm.aarch64 .deb.x86_64 .deb.arm64 .tar.gz.x86_64 .tar.gz.aarch64 bundle.tar.gz
	tar zcf packages.tar.gz $(PACKAGES_BUILD_DIR) --owner=0 --group=0

verify_version:
	@grep -q "#define PHP_DDTRACE_VERSION \"$(VERSION)" ext/version.h || (echo ext/version.h Version missmatch && exit 1)
	@grep -q "const VERSION = '$(VERSION)" src/DDTrace/Tracer.php || (echo src/DDTrace/Tracer.php Version missmatch && exit 1)
	@echo "All version files match"

verify_all: verify_version

# Generates the bridge/_generated_api and _generate_internal.php files. Note it only works on PHP < 8.0 because:
#  - we need classpreloader: 1.4.* because otherwise the generated file is not compatible with 5.4
#  - classpreloader: 1.4.* does not work on PHP 8 (even from a dedicated composer.json file), showing an incompatibility
#    with nikic/php-parser lexer's.
#  - even if we leave classpreloader: 1.4.* and not use it for PHP 8, this is not enough because it would force
#    phpunit version down to 5 (nikic common dependency) which is not compatible with PHP 8.
generate:
	@composer -dtooling/generation update
	@composer -dtooling/generation generate
	@composer -dtooling/generation verify

# Find all generated core dumps, sorted by date descending
cores:
	find . -path "./*/vendor" -prune -false -o \( -type f -regex ".*\/core\.?[0-9]*" \) -printf "%T@ %Tc %p\n" | sort -n -r

########################################################################################################################
# TESTS
########################################################################################################################
REQUEST_INIT_HOOK := -d ddtrace.request_init_hook=$(REQUEST_INIT_HOOK_PATH)
ENV_OVERRIDE := $(shell [ -n "${DD_TRACE_DOCKER_DEBUG}" ] && echo DD_AUTOLOAD_NO_COMPILE=true) DD_TRACE_CLI_ENABLED=true
TEST_EXTRA_INI ?=

### DDTrace tests ###
TESTS_ROOT := ./tests
COMPOSER := COMPOSER_MEMORY_LIMIT=-1 composer --no-interaction
COMPOSER_TESTS := $(COMPOSER) --working-dir=$(TESTS_ROOT)
PHPUNIT_OPTS := $(PHPUNIT_OPTS)
PHPUNIT := $(TESTS_ROOT)/vendor/bin/phpunit $(PHPUNIT_OPTS) --config=$(TESTS_ROOT)/phpunit.xml

TEST_INTEGRATIONS_70 := \
	test_integrations_deferred_loading \
	test_integrations_curl \
	test_integrations_memcached \
	test_integrations_mongodb1 \
	test_integrations_mysqli \
	test_integrations_pdo \
	test_integrations_elasticsearch1 \
	test_integrations_guzzle5 \
	test_integrations_guzzle6 \
	test_integrations_pcntl \
	test_integrations_phpredis3 \
	test_integrations_phpredis4 \
	test_integrations_phpredis5 \
	test_integrations_predis1 \
	test_opentracing_beta5

TEST_WEB_70 := \
	test_metrics \
	test_web_cakephp_28 \
	test_web_codeigniter_22 \
	test_web_laravel_42 \
	test_web_lumen_52 \
	test_web_nette_24 \
	test_web_slim_312 \
	test_web_symfony_23 \
	test_web_symfony_28 \
	test_web_symfony_30 \
	test_web_symfony_33 \
	test_web_symfony_34 \
	test_web_yii_2 \
	test_web_wordpress_48 \
	test_web_wordpress_55 \
	test_web_zend_1 \
	test_web_custom

TEST_INTEGRATIONS_71 := \
	test_integrations_deferred_loading \
	test_integrations_curl \
	test_integrations_memcached \
	test_integrations_mongodb1 \
	test_integrations_mysqli \
	test_integrations_pdo \
	test_integrations_elasticsearch1 \
	test_integrations_guzzle5 \
	test_integrations_guzzle6 \
	test_integrations_pcntl \
	test_integrations_phpredis3 \
	test_integrations_phpredis4 \
	test_integrations_phpredis5 \
	test_integrations_predis1 \
	test_opentracing_beta5 \
	test_opentracing_beta6 \
	test_opentracing_10

TEST_WEB_71 := \
	test_metrics \
	test_web_cakephp_28 \
	test_web_codeigniter_22 \
	test_web_laravel_42 \
	test_web_laravel_57 \
	test_web_laravel_58 \
	test_web_lumen_52 \
	test_web_lumen_56 \
	test_web_lumen_58 \
	test_web_nette_24 \
	test_web_nette_30 \
	test_web_slim_312 \
	test_web_symfony_23 \
	test_web_symfony_28 \
	test_web_symfony_30 \
	test_web_symfony_33 \
	test_web_symfony_34 \
	test_web_symfony_40 \
	test_web_symfony_42 \
	test_web_yii_2 \
	test_web_wordpress_48 \
	test_web_wordpress_55 \
	test_web_zend_1 \
	test_web_custom

TEST_INTEGRATIONS_72 := \
	test_integrations_deferred_loading \
	test_integrations_curl \
	test_integrations_memcached \
	test_integrations_mongodb1 \
	test_integrations_mysqli \
	test_integrations_pdo \
	test_integrations_elasticsearch1 \
	test_integrations_guzzle5 \
	test_integrations_guzzle6 \
	test_integrations_pcntl \
	test_integrations_phpredis3 \
	test_integrations_phpredis4 \
	test_integrations_phpredis5 \
	test_integrations_predis1 \
	test_opentracing_beta5 \
	test_opentracing_beta6 \
	test_opentracing_10

TEST_WEB_72 := \
	test_metrics \
	test_web_codeigniter_22 \
	test_web_laravel_42 \
	test_web_laravel_57 \
	test_web_laravel_58 \
	test_web_lumen_52 \
	test_web_lumen_56 \
	test_web_lumen_58 \
	test_web_nette_24 \
	test_web_nette_30 \
	test_web_slim_312 \
	test_web_slim_4 \
	test_web_symfony_23 \
	test_web_symfony_28 \
	test_web_symfony_30 \
	test_web_symfony_33 \
	test_web_symfony_34 \
	test_web_symfony_40 \
	test_web_symfony_42 \
	test_web_symfony_44 \
	test_web_symfony_50 \
	test_web_symfony_51 \
	test_web_symfony_52 \
	test_web_wordpress_48 \
	test_web_wordpress_55 \
	test_web_yii_2 \
	test_web_zend_1 \
	test_web_custom

TEST_INTEGRATIONS_73 :=\
	test_integrations_deferred_loading \
	test_integrations_curl \
	test_integrations_memcached \
	test_integrations_mongodb1 \
	test_integrations_mysqli \
	test_integrations_pdo \
	test_integrations_elasticsearch7 \
	test_integrations_guzzle5 \
	test_integrations_guzzle6 \
	test_integrations_pcntl \
	test_integrations_phpredis3 \
	test_integrations_phpredis4 \
	test_integrations_phpredis5 \
	test_integrations_predis1 \
	test_opentracing_beta5 \
	test_opentracing_beta6 \
	test_opentracing_10

TEST_WEB_73 := \
	test_metrics \
	test_web_codeigniter_22 \
	test_web_laravel_57 \
	test_web_laravel_58 \
	test_web_laravel_8x \
	test_web_lumen_52 \
	test_web_lumen_56 \
	test_web_lumen_58 \
	test_web_nette_24 \
	test_web_nette_30 \
	test_web_slim_312 \
	test_web_slim_4 \
	test_web_symfony_34 \
	test_web_symfony_40 \
	test_web_symfony_42 \
	test_web_symfony_44 \
	test_web_symfony_50 \
	test_web_symfony_51 \
	test_web_symfony_52 \
	test_web_wordpress_48 \
	test_web_wordpress_55 \
	test_web_yii_2 \
	test_web_zend_1 \
	test_web_custom

TEST_INTEGRATIONS_74 := \
	test_integrations_deferred_loading \
	test_integrations_curl \
	test_integrations_memcached \
	test_integrations_mongodb1 \
	test_integrations_mysqli \
	test_integrations_pdo \
	test_integrations_elasticsearch7 \
	test_integrations_elasticsearch8 \
	test_integrations_guzzle5 \
	test_integrations_guzzle6 \
	test_integrations_pcntl \
	test_integrations_phpredis3 \
	test_integrations_phpredis4 \
	test_integrations_phpredis5 \
	test_integrations_predis1 \
	test_integrations_roadrunner \
	test_opentracing_beta5 \
	test_opentracing_beta6 \
	test_opentracing_10

TEST_WEB_74 := \
	test_metrics \
	test_web_codeigniter_22 \
	test_web_laravel_57 \
	test_web_laravel_58 \
	test_web_laravel_8x \
	test_web_lumen_52 \
	test_web_lumen_56 \
	test_web_lumen_58 \
	test_web_nette_24 \
	test_web_nette_30 \
	test_web_slim_312 \
	test_web_slim_4 \
	test_web_symfony_34 \
	test_web_symfony_40 \
	test_web_symfony_44 \
	test_web_symfony_50 \
	test_web_symfony_51 \
	test_web_symfony_52 \
	test_web_wordpress_48 \
	test_web_wordpress_55 \
	test_web_wordpress_59 \
	test_web_yii_2 \
	test_web_zend_1 \
	test_web_custom

# NOTE: test_integrations_phpredis5 is not included in the PHP 8.0 integrations tests because of this bug that only
# shows up in debug builds of PHP (https://github.com/phpredis/phpredis/issues/1869).
# Since we run tests in CI using php debug builds, we run test_integrations_phpredis5 in a separate non-debug container.
# Once the fix for https://github.com/phpredis/phpredis/issues/1869 is released, we can remove that additional container
# and add back again test_integrations_phpredis5 to the PHP 8.0 test suite.
TEST_INTEGRATIONS_80 := \
	test_integrations_deferred_loading \
	test_integrations_curl \
	test_integrations_memcached \
	test_integrations_mongodb1 \
	test_integrations_mysqli \
	test_integrations_pdo \
	test_integrations_elasticsearch7 \
	test_integrations_guzzle5 \
	test_integrations_guzzle6 \
	test_integrations_pcntl \
	test_integrations_predis1 \
	test_opentracing_10

TEST_WEB_80 := \
	test_metrics \
	test_web_codeigniter_22 \
	test_web_laravel_8x \
	test_web_nette_24 \
	test_web_nette_30 \
	test_web_slim_312 \
	test_web_slim_4 \
	test_web_symfony_44 \
	test_web_symfony_51 \
	test_web_symfony_52 \
	test_web_wordpress_59 \
	test_web_yii_2 \
	test_web_zend_1_21 \
	test_web_custom

TEST_INTEGRATIONS_81 := \
	test_integrations_curl \
	test_integrations_deferred_loading \
	test_integrations_memcached \
	test_integrations_mongodb1 \
	test_integrations_mysqli \
	test_integrations_pcntl \
	test_integrations_pdo \
	test_integrations_elasticsearch7 \
	test_integrations_predis1 \
	test_opentracing_10

TEST_WEB_81 := \
	test_metrics \
	test_web_codeigniter_22 \
	test_web_laravel_8x \
	test_web_nette_24 \
	test_web_nette_30 \
	test_web_slim_312 \
	test_web_slim_4 \
	test_web_symfony_52 \
	test_web_wordpress_59 \
	test_web_custom \
	test_web_zend_1_21
#	test_web_yii_2 \

TEST_INTEGRATIONS_82 := \
	test_integrations_curl \
	test_integrations_deferred_loading \
	test_integrations_memcached \
	test_integrations_mongodb1 \
	test_integrations_mysqli \
	test_integrations_pcntl \
	test_integrations_pdo \
	test_integrations_elasticsearch7 \
	test_integrations_elasticsearch8 \
	test_integrations_predis1 \
	test_integrations_roadrunner \
	test_opentracing_10

TEST_WEB_82 := \
	test_metrics \
	test_web_codeigniter_22 \
	test_web_laravel_8x \
	test_web_nette_24 \
	test_web_nette_30 \
	test_web_slim_312 \
	test_web_slim_4 \
	test_web_symfony_52 \
	test_web_symfony_62 \
	test_web_wordpress_59 \
	test_web_custom \
	test_web_zend_1_21
#	test_web_yii_2 \

FILTER := .

define run_tests
	$(ENV_OVERRIDE) php $(TEST_EXTRA_INI) $(REQUEST_INIT_HOOK) $(PHPUNIT) $(1) --filter=$(FILTER)
endef

# use this as the first target if you want to use uncompiled files instead of the _generated_*.php compiled file.
dev:
	$(Q) :
	$(Q) $(eval ENV_OVERRIDE:=$(ENV_OVERRIDE) DD_AUTOLOAD_NO_COMPILE=true)

use_generated:
	$(Q) :
	$(Q) $(eval ENV_OVERRIDE:=$(ENV_OVERRIDE) DD_AUTOLOAD_NO_COMPILE=)

clean_test: clean_test_scenarios
	rm -rf $(TESTS_ROOT)/composer.lock $(TESTS_ROOT)/.scenarios.lock $(TESTS_ROOT)/vendor
	find $(TESTS_ROOT)/Frameworks/ -path "*/vendor/*" -prune -o -wholename "*/cache/*.php" -print -exec rm -rf {} \;

clean_test_scenarios:
	$(TESTS_ROOT)/clean-composer-scenario-locks.sh

$(TESTS_ROOT)/composer.lock: composer_tests_update

composer_tests_update:
	$(COMPOSER_TESTS) update

global_test_run_dependencies: install_all $(TESTS_ROOT)/composer.lock

test_all: \
	test_unit \
	test_integration \
	test_auto_instrumentation \
	test_composer \
	test_distributed_tracing \
	test_integrations \
	test_web

test: global_test_run_dependencies
	$(call run_tests,$(TESTS))

test_unit: global_test_run_dependencies
	$(call run_tests,--testsuite=unit $(TESTS))

test_integration: global_test_run_dependencies
	$(call run_tests,--testsuite=integration $(TESTS))

test_auto_instrumentation: global_test_run_dependencies
	$(call run_tests,--testsuite=auto-instrumentation $(TESTS))
	# Cleaning up composer.json files in tests/AutoInstrumentation modified for TLS during tests
	git checkout $(TESTS_ROOT)/AutoInstrumentation/**/composer.json

test_composer: global_test_run_dependencies
	$(call run_tests,--testsuite=composer-tests $(TESTS))

test_distributed_tracing: global_test_run_dependencies
	$(call run_tests,--testsuite=distributed-tracing $(TESTS))

test_metrics: global_test_run_dependencies
	$(call run_tests,--testsuite=metrics $(TESTS))

test_opentracing_beta5: global_test_run_dependencies
	$(MAKE) test_scenario_opentracing_beta5
	$(call run_tests,tests/OpenTracerUnit)

test_opentracing_beta6: global_test_run_dependencies
	$(MAKE) test_scenario_opentracing_beta6
	$(call run_tests,tests/OpenTracerUnit)

test_opentracing_10: global_test_run_dependencies
	$(MAKE) test_scenario_opentracing10
	$(call run_tests,tests/OpenTracer1Unit)

test_integrations: $(TEST_INTEGRATIONS_$(PHP_MAJOR_MINOR))
test_web: $(TEST_WEB_$(PHP_MAJOR_MINOR))

test_integrations_deferred_loading: global_test_run_dependencies
	$(MAKE) test_scenario_predis1
	$(call run_tests,tests/Integrations/DeferredLoading)
test_integrations_curl: global_test_run_dependencies
	$(call run_tests,tests/Integrations/Curl)
test_integrations_elasticsearch1: global_test_run_dependencies
	$(MAKE) test_scenario_elasticsearch1
	$(call run_tests,tests/Integrations/Elasticsearch/V1)
test_integrations_elasticsearch7: global_test_run_dependencies
	$(MAKE) test_scenario_elasticsearch7
	$(call run_tests,tests/Integrations/Elasticsearch/V1)
test_integrations_elasticsearch8: global_test_run_dependencies
	$(MAKE) test_scenario_elasticsearch8
	$(call run_tests,tests/Integrations/Elasticsearch/V8)
test_integrations_guzzle5: global_test_run_dependencies
	$(MAKE) test_scenario_guzzle5
	$(call run_tests,tests/Integrations/Guzzle/V5)
test_integrations_guzzle6: global_test_run_dependencies
	$(MAKE) test_scenario_guzzle6
	$(call run_tests,tests/Integrations/Guzzle/V6)
test_integrations_memcached: global_test_run_dependencies
	$(MAKE) test_scenario_default
	$(call run_tests,tests/Integrations/Memcached)
test_integrations_mysqli: global_test_run_dependencies
	$(MAKE) test_scenario_default
	$(call run_tests,tests/Integrations/Mysqli)
test_integrations_mongo: global_test_run_dependencies
	$(MAKE) test_scenario_default
	$(call run_tests,tests/Integrations/Mongo)
test_integrations_mongodb1:
	$(MAKE) test_scenario_mongodb1
	$(call run_tests,tests/Integrations/MongoDB)
test_integrations_pcntl: global_test_run_dependencies
	$(call run_tests,tests/Integrations/PCNTL)
test_integrations_pdo: global_test_run_dependencies
	$(MAKE) test_scenario_default
	$(call run_tests,tests/Integrations/PDO)
test_integrations_phpredis3: global_test_run_dependencies
	$(MAKE) test_scenario_phpredis3
	$(call run_tests,tests/Integrations/PHPRedis/V3)
test_integrations_phpredis4: global_test_run_dependencies
	$(MAKE) test_scenario_phpredis4
	$(call run_tests,tests/Integrations/PHPRedis/V4)
test_integrations_phpredis5: global_test_run_dependencies
	$(MAKE) test_scenario_phpredis5
	$(call run_tests,tests/Integrations/PHPRedis/V5)
test_integrations_predis1: global_test_run_dependencies
	$(MAKE) test_scenario_predis1
	$(call run_tests,tests/Integrations/Predis)
test_integrations_roadrunner: global_test_run_dependencies
	$(COMPOSER) --working-dir=tests/Frameworks/Roadrunner/Version_2 update
	$(call run_tests,tests/Integrations/Roadrunner/V2)
test_web_cakephp_28: global_test_run_dependencies
	$(COMPOSER) --working-dir=tests/Frameworks/CakePHP/Version_2_8 update
	$(call run_tests,--testsuite=cakephp-28-test)
test_web_codeigniter_22: global_test_run_dependencies
	$(call run_tests,--testsuite=codeigniter-22-test)
test_web_laravel_42: global_test_run_dependencies
	$(COMPOSER) --working-dir=tests/Frameworks/Laravel/Version_4_2 update
	php tests/Frameworks/Laravel/Version_4_2/artisan optimize
	$(call run_tests,tests/Integrations/Laravel/V4)
test_web_laravel_57: global_test_run_dependencies
	$(COMPOSER) --working-dir=tests/Frameworks/Laravel/Version_5_7 update
	$(call run_tests,tests/Integrations/Laravel/V5_7)
test_web_laravel_58: global_test_run_dependencies
	$(COMPOSER) --working-dir=tests/Frameworks/Laravel/Version_5_8 update
	$(call run_tests,--testsuite=laravel-58-test)
test_web_laravel_8x: global_test_run_dependencies
	$(COMPOSER) --working-dir=tests/Frameworks/Laravel/Version_8_x update
	$(call run_tests,--testsuite=laravel-8x-test)
test_web_lumen_52: global_test_run_dependencies
	$(COMPOSER) --working-dir=tests/Frameworks/Lumen/Version_5_2 update
	$(call run_tests,tests/Integrations/Lumen/V5_2)
test_web_lumen_56: global_test_run_dependencies
	$(COMPOSER) --working-dir=tests/Frameworks/Lumen/Version_5_6 update
	$(call run_tests,tests/Integrations/Lumen/V5_6)
test_web_lumen_58: global_test_run_dependencies
	$(COMPOSER) --working-dir=tests/Frameworks/Lumen/Version_5_8 update
	$(call run_tests,tests/Integrations/Lumen/V5_8)
test_web_slim_312: global_test_run_dependencies
	$(COMPOSER) --working-dir=tests/Frameworks/Slim/Version_3_12 update
	$(call run_tests,--testsuite=slim-312-test)
test_web_slim_4: global_test_run_dependencies
	$(COMPOSER) --working-dir=tests/Frameworks/Slim/Version_4 update
	$(call run_tests,--testsuite=slim-4-test)
test_web_symfony_23: global_test_run_dependencies
	$(COMPOSER) --working-dir=tests/Frameworks/Symfony/Version_2_3 update
	$(call run_tests,tests/Integrations/Symfony/V2_3)
test_web_symfony_28: global_test_run_dependencies
	$(COMPOSER) --working-dir=tests/Frameworks/Symfony/Version_2_8 update
	$(call run_tests,tests/Integrations/Symfony/V2_8)
test_web_symfony_30: global_test_run_dependencies
	$(COMPOSER) --working-dir=tests/Frameworks/Symfony/Version_3_0 update
	php tests/Frameworks/Symfony/Version_3_0/bin/console cache:clear --no-warmup --env=prod
	$(call run_tests,tests/Integrations/Symfony/V3_0)
test_web_symfony_33: global_test_run_dependencies
	$(COMPOSER) --working-dir=tests/Frameworks/Symfony/Version_3_3 update
	php tests/Frameworks/Symfony/Version_3_3/bin/console cache:clear --no-warmup --env=prod
	$(call run_tests,tests/Integrations/Symfony/V3_3)
test_web_symfony_34: global_test_run_dependencies
	$(COMPOSER) --working-dir=tests/Frameworks/Symfony/Version_3_4 update
	php tests/Frameworks/Symfony/Version_3_4/bin/console cache:clear --no-warmup --env=prod
	$(call run_tests,tests/Integrations/Symfony/V3_4)
test_web_symfony_40: global_test_run_dependencies
	# We hit broken updates in this unmaintained version, so we committed a
	# working composer.lock and we composer install instead of composer update
	$(COMPOSER) --working-dir=tests/Frameworks/Symfony/Version_4_0 install
	php tests/Frameworks/Symfony/Version_4_0/bin/console cache:clear --no-warmup --env=prod
	$(call run_tests,tests/Integrations/Symfony/V4_0)
test_web_symfony_42: global_test_run_dependencies
	$(COMPOSER) --working-dir=tests/Frameworks/Symfony/Version_4_2 update
	php tests/Frameworks/Symfony/Version_4_2/bin/console cache:clear --no-warmup --env=prod
	$(call run_tests,tests/Integrations/Symfony/V4_2)
test_web_symfony_44: global_test_run_dependencies
	$(COMPOSER) --working-dir=tests/Frameworks/Symfony/Version_4_4 update
	php tests/Frameworks/Symfony/Version_4_4/bin/console cache:clear --no-warmup --env=prod
	$(call run_tests,--testsuite=symfony-44-test)
test_web_symfony_50: global_test_run_dependencies
	$(COMPOSER) --working-dir=tests/Frameworks/Symfony/Version_5_0 install # EOL; install from lock
	php tests/Frameworks/Symfony/Version_5_0/bin/console cache:clear --no-warmup --env=prod
	$(call run_tests,tests/Integrations/Symfony/V5_0)
test_web_symfony_51: global_test_run_dependencies
	$(COMPOSER) --working-dir=tests/Frameworks/Symfony/Version_5_1 update
	php tests/Frameworks/Symfony/Version_5_1/bin/console cache:clear --no-warmup --env=prod
	$(call run_tests,tests/Integrations/Symfony/V5_1)
test_web_symfony_52: global_test_run_dependencies
	$(COMPOSER) --working-dir=tests/Frameworks/Symfony/Version_5_2 update
	php tests/Frameworks/Symfony/Version_5_2/bin/console cache:clear --no-warmup --env=prod
	$(call run_tests,--testsuite=symfony-52-test)
test_web_symfony_62: global_test_run_dependencies
	$(COMPOSER) --working-dir=tests/Frameworks/Symfony/Version_6_2 update
	php tests/Frameworks/Symfony/Version_6_2/bin/console cache:clear --no-warmup --env=prod
	$(call run_tests,--testsuite=symfony-62-test)

test_web_wordpress_48: global_test_run_dependencies
	$(call run_tests,tests/Integrations/WordPress/V4_8)
test_web_wordpress_55: global_test_run_dependencies
	$(call run_tests,tests/Integrations/WordPress/V5_5)
test_web_wordpress_59: global_test_run_dependencies
	$(call run_tests,tests/Integrations/WordPress/V5_9)
test_web_yii_2: global_test_run_dependencies
	$(COMPOSER) --working-dir=tests/Frameworks/Yii/Version_2_0 update
	$(call run_tests,tests/Integrations/Yii/V2_0)
test_web_nette_24: global_test_run_dependencies
	$(COMPOSER) --working-dir=tests/Frameworks/Nette/Version_2_4 update
	$(call run_tests,tests/Integrations/Nette/V2_4)
test_web_nette_30: global_test_run_dependencies
	$(COMPOSER) --working-dir=tests/Frameworks/Nette/Version_3_0 update
	$(call run_tests,tests/Integrations/Nette/V3_0)
test_web_zend_1: global_test_run_dependencies
	$(call run_tests,tests/Integrations/ZendFramework/V1)
test_web_zend_1_21: global_test_run_dependencies
	$(call run_tests,tests/Integrations/ZendFramework/V1_21)
test_web_custom: global_test_run_dependencies
	$(COMPOSER) --working-dir=tests/Frameworks/Custom/Version_Autoloaded update
	$(call run_tests,--testsuite=custom-framework-autoloading-test)

test_scenario_%:
	$(Q) $(COMPOSER_TESTS) scenario $*

### Api tests ###
API_TESTS_ROOT := ./tests/api

test_api_unit: composer.lock global_test_run_dependencies
	$(ENV_OVERRIDE) php $(REQUEST_INIT_HOOK) vendor/bin/phpunit --config=phpunit.xml $(API_TESTS_ROOT)/Unit $(TESTS)

# Just test it does not crash, i.e. the exit code
test_internal_api_randomized: $(SO_FILE)
	php -ddatadog.trace.cli_enabled=1 -d extension=$(SO_FILE) tests/internal-api-stress-test.php 2>/dev/null

composer.lock: composer.json
	$(Q) composer update

.PHONY: dev dist_clean clean cores all clang_format_check clang_format_fix install sudo_install test_c test_c_mem test_extension_ci test_zai test_zai_asan test install_ini install_all \
	.apk .rpm .deb .tar.gz sudo debug prod strict run-tests.php verify_pecl_file_definitions verify_version verify_package_xml verify_all
