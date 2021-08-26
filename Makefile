Q := @
PROJECT_ROOT := $(shell pwd)
REQUEST_INIT_HOOK_PATH := $(PROJECT_ROOT)/bridge/dd_wrap_autoloader.php
SHELL := /bin/bash
BUILD_SUFFIX := extension
BUILD_DIR := $(PROJECT_ROOT)/tmp/build_$(BUILD_SUFFIX)
ZAI_BUILD_DIR := $(PROJECT_ROOT)/tmp/build_zai
SO_FILE := $(BUILD_DIR)/modules/ddtrace.so
WALL_FLAGS := -Wall -Wextra
EXTRA_CFLAGS :=
CFLAGS := -O2 $(shell [ -n "${DD_TRACE_DOCKER_DEBUG}" ] && echo -O0 -g) $(EXTRA_CFLAGS) $(WALL_FLAGS)
ROOT_DIR:=$(shell dirname $(realpath $(lastword $(MAKEFILE_LIST))))
PHP_EXTENSION_DIR=$(shell php -r 'print ini_get("extension_dir");')
PHP_MAJOR_MINOR:=$(shell php -r 'echo PHP_MAJOR_VERSION . PHP_MINOR_VERSION;')

VERSION:=$(shell awk -F\' '/const VERSION/ {print $$2}' < src/DDTrace/Tracer.php)

INI_FILE := $(shell php -i | awk -F"=>" '/Scan this dir for additional .ini files/ {print $$2}')/ddtrace.ini

RUN_TESTS_EXTRA_ARGS :=
RUN_TESTS_CMD := REPORT_EXIT_STATUS=1 TEST_PHP_SRCDIR=$(PROJECT_ROOT) USE_TRACKED_ALLOC=1 php -n -d 'memory_limit=-1' $(BUILD_DIR)/run-tests.php --show-diff -n -p $(shell which php) -q $(RUN_TESTS_EXTRA_ARGS)

C_FILES := $(shell find components ext src/dogstatsd zend_abstract_interface -name '*.c' -o -name '*.h' | awk '{ printf "$(BUILD_DIR)/%s\n", $$1 }' )
TEST_FILES := $(shell find tests/ext -name '*.php*' -o -name '*.inc' | awk '{ printf "$(BUILD_DIR)/%s\n", $$1 }' )
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

$(BUILD_DIR)/config.m4: $(M4_FILES)

$(BUILD_DIR)/configure: $(BUILD_DIR)/config.m4
	$(Q) (cd $(BUILD_DIR); phpize && sed -i 's/\/FAILED/\/\\bFAILED/' $(BUILD_DIR)/run-tests.php) # Fix PHP 5.4 exit code bug when running selected tests (FAILED vs XFAILED)

$(BUILD_DIR)/Makefile: $(BUILD_DIR)/configure
	$(Q) (cd $(BUILD_DIR); ./configure)

$(SO_FILE): $(C_FILES) $(BUILD_DIR)/Makefile
	$(Q) $(MAKE) -C $(BUILD_DIR) CFLAGS="$(CFLAGS)"

$(PHP_EXTENSION_DIR)/ddtrace.so: $(SO_FILE)
	$(Q) $(SUDO) $(MAKE) -C $(BUILD_DIR) install

install: $(PHP_EXTENSION_DIR)/ddtrace.so

$(INI_FILE):
	$(Q) echo "extension=ddtrace.so" | $(SUDO) tee -a $@

install_ini: $(INI_FILE)

install_all: install install_ini

run_tests: $(TEST_FILES) $(TEST_STUB_FILES) $(BUILD_DIR)/configure
	$(RUN_TESTS_CMD) $(BUILD_DIR)/$(TESTS)

test_c: export DD_TRACE_CLI_ENABLED=1
test_c: $(SO_FILE) $(TEST_FILES) $(TEST_STUB_FILES)
	$(RUN_TESTS_CMD) -d extension=$(SO_FILE) $(BUILD_DIR)/$(TESTS)

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
	valgrind -q --tool=memcheck --trace-children=yes --vex-iropt-register-updates=allregs-at-mem-access php -n -d extension=$(SO_FILE) -d ddtrace.request_init_hook=$$(pwd)/bridge/dd_wrap_autoloader.php $(INIT_HOOK_TEST_FILES); \
	)

test_with_init_hook_asan: $(SO_FILE) $(INIT_HOOK_TEST_FILES)
	( \
	set -xe; \
	export DD_TRACE_CLI_ENABLED=1; \
	export TEST_PHP_JUNIT=$(JUNIT_RESULTS_DIR)/asan-extension-init-hook-test.xml; \
	$(MAKE) -C $(BUILD_DIR) CFLAGS="-g -fsanitize=address" LDFLAGS="-fsanitize=address" clean all; \
	$(RUN_TESTS_CMD) -d extension=$(SO_FILE) -d ddtrace.request_init_hook=$$(pwd)/bridge/dd_wrap_autoloader.php --asan $(INIT_HOOK_TEST_FILES); \
	)

test_c_asan: export DD_TRACE_CLI_ENABLED=1
test_c_asan: $(SO_FILE) $(TEST_FILES) $(TEST_STUB_FILES)
	( \
	set -xe; \
	export TEST_PHP_JUNIT=$(JUNIT_RESULTS_DIR)/asan-extension-test.xml; \
	$(MAKE) -C $(BUILD_DIR) CFLAGS="-g -fsanitize=address" LDFLAGS="-fsanitize=address" clean all; \
	$(RUN_TESTS_CMD) -d extension=$(SO_FILE) --asan $(BUILD_DIR)/$(TESTS); \
	)

test_extension_ci: $(SO_FILE) $(TEST_FILES) $(TEST_STUB_FILES)
	( \
	set -xe; \
	export TEST_PHP_JUNIT=$(JUNIT_RESULTS_DIR)/normal-extension-test.xml; \
	$(MAKE) -C $(BUILD_DIR) CFLAGS="-g" clean all; \
	$(RUN_TESTS_CMD) -d extension=$(SO_FILE) $(BUILD_DIR)/$(TESTS); \
	\
	export TEST_PHP_JUNIT=$(JUNIT_RESULTS_DIR)/valgrind-extension-test.xml; \
	export TEST_PHP_OUTPUT=$(JUNIT_RESULTS_DIR)/valgrind-run-tests.out; \
	$(RUN_TESTS_CMD) -d extension=$(SO_FILE) -m -s $$TEST_PHP_OUTPUT $(BUILD_DIR)/$(TESTS) && ! grep -e 'LEAKED TEST SUMMARY' $$TEST_PHP_OUTPUT; \
	)

build_zai:
	( \
	mkdir -p "$(ZAI_BUILD_DIR)"; \
	cd $(ZAI_BUILD_DIR); \
	CMAKE_PREFIX_PATH=/opt/catch2 cmake -DCMAKE_BUILD_TYPE=Debug -DBUILD_ZAI_TESTING=ON -DPHP_CONFIG=$(shell which php-config) $(PROJECT_ROOT)/zend_abstract_interface; \
	$(MAKE) $(MAKEFLAGS); \
	)

test_zai: build_zai
	$(MAKE) -C $(ZAI_BUILD_DIR) test $(shell [ -z "${TESTS}"] || echo "ARGS='--test-dir ${TESTS}'") && ! grep -e "=== Total .* memory leaks detected ===" $(ZAI_BUILD_DIR)/Testing/Temporary/LastTest.log

build_zai_asan:
	( \
	mkdir -p "$(ZAI_BUILD_DIR)"; \
	cd $(ZAI_BUILD_DIR); \
	CMAKE_PREFIX_PATH=/opt/catch2 cmake -DCMAKE_BUILD_TYPE=Debug -DBUILD_ZAI_TESTING=ON -DBUILD_ZAI_ASAN=ON -DPHP_CONFIG=$(shell which php-config) $(PROJECT_ROOT)/zend_abstract_interface; \
	$(MAKE) clean $(MAKEFLAGS); \
	$(MAKE) $(MAKEFLAGS); \
	)

test_zai_asan: build_zai_asan
	$(MAKE) -C $(ZAI_BUILD_DIR) test $(shell [ -z "${TESTS}"] || echo "ARGS='--test-dir ${TESTS}'") USE_ZEND_ALLOC=0 USE_TRACKED_ALLOC=1 && ! grep -e "=== Total .* memory leaks detected ===" $(ZAI_BUILD_DIR)/Testing/Temporary/LastTest.log

clean_zai:
	rm -rf $(ZAI_BUILD_DIR)

dist_clean:
	rm -rf $(BUILD_DIR) $(ZAI_BUILD_DIR)

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
FPM_INFO_OPTS=-a native -n $(PACKAGE_NAME) -m dev@datadoghq.com --license "BSD 3-Clause License" --version $(VERSION) \
	--provides $(PACKAGE_NAME) --vendor DataDog  --url "https://docs.datadoghq.com/tracing/setup/php/" --no-depends
FPM_DIR_OPTS=--directories $(EXT_DIR)/etc --config-files $(EXT_DIR)/etc -s dir
FPM_FILES=extensions/=$(EXT_DIR)/extensions \
	package/post-install.sh=$(EXT_DIR)/bin/post-install.sh package/ddtrace.ini.example=$(EXT_DIR)/etc/ \
	docs=$(EXT_DIR)/docs README.md=$(EXT_DIR)/docs/README.md UPGRADE-0.10.md=$(EXT_DIR)/docs/UPGRADE-0.10.md\
	src=$(EXT_DIR)/dd-trace-sources \
	bridge=$(EXT_DIR)/dd-trace-sources
FPM_OPTS=$(FPM_INFO_OPTS) $(FPM_DIR_OPTS) --after-install=package/post-install.sh

PACKAGES_BUILD_DIR:=build/packages

$(PACKAGES_BUILD_DIR):
	mkdir -p "$@"

.deb: $(PACKAGES_BUILD_DIR)
	fpm -p $(PACKAGES_BUILD_DIR) -t deb $(FPM_OPTS) $(FPM_FILES)
.rpm: $(PACKAGES_BUILD_DIR)
	fpm -p $(PACKAGES_BUILD_DIR) -t rpm $(FPM_OPTS) $(FPM_FILES)
.apk: $(PACKAGES_BUILD_DIR)
	fpm -p $(PACKAGES_BUILD_DIR) -t apk $(FPM_OPTS) --depends=bash --depends=curl --depends=libexecinfo $(FPM_FILES)
.tar.gz: $(PACKAGES_BUILD_DIR)
	mkdir -p /tmp/$(PACKAGES_BUILD_DIR)
	fpm -p /tmp/$(PACKAGES_BUILD_DIR)/$(PACKAGE_NAME)-$(VERSION) -t dir $(FPM_OPTS) $(FPM_FILES)
	tar zcf $(PACKAGES_BUILD_DIR)/$(PACKAGE_NAME)-$(VERSION).x86_64.tar.gz -C /tmp/$(PACKAGES_BUILD_DIR)/$(PACKAGE_NAME)-$(VERSION) . --owner=0 --group=0

packages: .apk .rpm .deb .tar.gz
	tar zcf packages.tar.gz $(PACKAGES_BUILD_DIR) --owner=0 --group=0

verify_pecl_file_definitions:
	@for i in $(C_FILES) $(TEST_FILES) $(TEST_STUB_FILES) $(M4_FILES); do\
		grep -q $${i#"$(BUILD_DIR)/"} package.xml && continue;\
		echo package.xml is missing \"$${i#"$(BUILD_DIR)/"}\"; \
		exit 1;\
	done
	@echo "PECL file definitions are correct"

verify_version:
	@grep -q "#define PHP_DDTRACE_VERSION \"$(VERSION)" ext/version.h || (echo ext/version.h Version missmatch && exit 1)
	@grep -q "const VERSION = '$(VERSION)" src/DDTrace/Tracer.php || (echo src/DDTrace/Tracer.php Version missmatch && exit 1)
	@echo "All version files match"

verify_all: verify_pecl_file_definitions verify_version

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

TEST_INTEGRATIONS_54 := \
	test_integrations_deferred_loading \
	test_integrations_curl \
	test_integrations_memcached \
	test_integrations_mysqli \
	test_integrations_pcntl \
	test_integrations_pdo \
	test_integrations_elasticsearch1 \
	test_integrations_guzzle5 \
	test_integrations_predis1

TEST_WEB_54 := \
	test_web_cakephp_28 \
	test_web_laravel_42 \
	test_web_yii_2 \
	test_web_zend_1 \
	test_web_custom

TEST_INTEGRATIONS_55 := \
	test_integrations_deferred_loading \
	test_integrations_curl \
	test_integrations_memcached \
	test_integrations_mysqli \
	test_integrations_mongo \
	test_integrations_pcntl \
	test_integrations_pdo \
	test_integrations_elasticsearch1 \
	test_integrations_guzzle5 \
	test_integrations_guzzle6 \
	test_integrations_predis1

TEST_WEB_55 := \
	test_web_cakephp_28 \
	test_web_codeigniter_22 \
	test_web_laravel_42 \
	test_web_lumen_52 \
	test_web_slim_312 \
	test_web_symfony_23 \
	test_web_symfony_28 \
	test_web_symfony_30 \
	test_web_symfony_33 \
	test_web_symfony_34 \
	test_web_yii_2 \
	test_web_wordpress_48 \
	test_web_zend_1 \
	test_web_custom

TEST_INTEGRATIONS_56 := \
	test_integrations_deferred_loading \
	test_integrations_curl \
	test_integrations_memcached \
	test_integrations_mysqli \
	test_integrations_mongo \
	test_integrations_pcntl \
	test_integrations_pdo \
	test_integrations_elasticsearch1 \
	test_integrations_guzzle5 \
	test_integrations_guzzle6 \
	test_integrations_predis1 \
	test_opentracing_beta5

TEST_WEB_56 := \
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

TEST_INTEGRATIONS_70 := \
	test_integrations_deferred_loading \
	test_integrations_curl \
	test_integrations_memcached \
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
	test_integrations_mysqli \
	test_integrations_pdo \
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
	test_integrations_mysqli \
	test_integrations_pdo \
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

# NOTE: test_integrations_phpredis5 is not included in the PHP 8.0 integrations tests because of this bug that only
# shows up in debug builds of PHP (https://github.com/phpredis/phpredis/issues/1869).
# Since we run tests in CI using php debug builds, we run test_integrations_phpredis5 in a separate non-debug container.
# Once the fix for https://github.com/phpredis/phpredis/issues/1869 is released, we can remove that additional container
# and add back again test_integrations_phpredis5 to the PHP 8.0 test suite.
TEST_INTEGRATIONS_80 := \
	test_integrations_deferred_loading \
	test_integrations_curl \
	test_integrations_mysqli \
	test_integrations_pdo \
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
	test_web_yii_2 \
	test_web_custom

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
	$(call run_tests,tests/Integrations/Elasticsearch)
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
	$(call run_tests,tests/Integrations/Laravel/V8_x)
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
	$(call run_tests,tests/Integrations/Symfony/V4_4)
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
	$(call run_tests,tests/Integrations/Symfony/V5_2)

test_web_wordpress_48: global_test_run_dependencies
	$(call run_tests,tests/Integrations/WordPress/V4_8)
test_web_wordpress_55: global_test_run_dependencies
	$(call run_tests,tests/Integrations/WordPress/V5_5)
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
test_web_custom: global_test_run_dependencies
	$(COMPOSER) --working-dir=tests/Frameworks/Custom/Version_Autoloaded update
	$(call run_tests,--testsuite=custom-framework-autoloading-test)

test_scenario_%:
	$(Q) $(COMPOSER_TESTS) scenario $*

### Api tests ###
API_TESTS_ROOT := ./tests/api

test_api_unit: composer.lock global_test_run_dependencies
	$(ENV_OVERRIDE) php $(REQUEST_INIT_HOOK) vendor/bin/phpunit --config=phpunit.xml $(API_TESTS_ROOT)/Unit $(TESTS)

composer.lock: composer.json
	$(Q) composer update

.PHONY: dev dist_clean clean cores all clang_format_check clang_format_fix install sudo_install test_c test_c_mem test_extension_ci test_zai test_zai_asan test install_ini install_all \
	.apk .rpm .deb .tar.gz sudo debug prod strict run-tests.php verify_pecl_file_definitions verify_version verify_package_xml verify_all
