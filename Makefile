SHELL := /bin/bash
BUILD_SUFFIX := extension
BUILD_DIR := tmp/build_$(BUILD_SUFFIX)
SO_FILE := $(BUILD_DIR)/modules/ddtrace.so
WALL_FLAGS := -Wall -Wextra
CFLAGS := -O2 $(WALL_FLAGS)
ROOT_DIR:=$(shell dirname $(realpath $(lastword $(MAKEFILE_LIST))))

VERSION:=$(shell cat src/DDTrace/version.php | grep return | awk '{print $$2}' | cut -d\' -f2)
VERSION_WITHOUT_SUFFIX:=$(shell cat src/DDTrace/version.php | grep return | awk '{print $$2}' | cut -d\' -f2 | cut -d- -f1)

INI_FILE := /usr/local/etc/php/conf.d/ddtrace.ini

C_FILES := $(shell find src/{dogstatsd,ext} -name '*.c' -o -name '*.h' | awk '{ printf "$(BUILD_DIR)/%s\n", $$1 }' )
TEST_FILES := $(shell find tests/ext -name '*.php*' | awk '{ printf "$(BUILD_DIR)/%s\n", $$1 }' )

ALL_FILES := $(C_FILES) $(TEST_FILES) $(BUILD_DIR)/config.m4

$(BUILD_DIR)/%: %
	$(Q) echo Copying $* to build dir
	$(Q) mkdir -p $(dir $@)
	$(Q) cp -a $* $@

JUNIT_RESULTS_DIR := $(shell pwd)

all: $(BUILD_DIR)/configure $(SO_FILE)
Q := @

$(BUILD_DIR)/configure: $(BUILD_DIR)/config.m4
	$(Q) (cd $(BUILD_DIR); phpize)

$(BUILD_DIR)/Makefile: $(BUILD_DIR)/configure
	$(Q) (cd $(BUILD_DIR); ./configure)

$(SO_FILE): $(ALL_FILES) $(BUILD_DIR)/Makefile
	$(Q) $(MAKE) -C $(BUILD_DIR) CFLAGS="$(CFLAGS)"

install: $(SO_FILE)
	$(Q) $(SUDO) $(MAKE) -C $(BUILD_DIR) install

$(INI_FILE):
	$(Q) echo "extension=ddtrace.so" | $(SUDO) tee -a $@

install_ini: $(INI_FILE)

install_all: install install_ini

test_c: export DD_TRACE_CLI_ENABLED=1
test_c: $(SO_FILE)
	$(MAKE) -C $(BUILD_DIR) test TESTS="-q --show-all -d ddtrace.request_init_hook=$(ROOT_DIR)/bridge/dd_wrap_autoloader.php $(TESTS)"

test_c_mem: export DD_TRACE_CLI_ENABLED=1
test_c_mem: $(SO_FILE)
	$(MAKE) -C $(BUILD_DIR) test TESTS="-q --show-all -d ddtrace.request_init_hook=$(ROOT_DIR)/bridge/dd_wrap_autoloader.php -m $(TESTS)"

test_c_asan: export DD_TRACE_CLI_ENABLED=1
test_c_asan: $(SO_FILE)
	( \
	set -xe; \
	export REPORT_EXIT_STATUS=1; \
	\
	export TEST_PHP_JUNIT=$(JUNIT_RESULTS_DIR)/asan-extension-test.xml; \
	$(MAKE) -C $(BUILD_DIR) CFLAGS="-g -fsanitize=address" LDFLAGS="-fsanitize=address" clean test  TESTS="-q --asan --show-all $(TESTS)" && grep -e 'errors="0"' $$TEST_PHP_JUNIT; \
	)

test_extension_ci: $(SO_FILE)
	( \
	set -xe; \
	export REPORT_EXIT_STATUS=1; \
	\
	export TEST_PHP_JUNIT=$(JUNIT_RESULTS_DIR)/normal-extension-test.xml; \
	$(MAKE) -C $(BUILD_DIR) test  TESTS="-q --show-all $(TESTS)" && grep -e 'errors="0"' $$TEST_PHP_JUNIT; \
	\
	export TEST_PHP_JUNIT=$(JUNIT_RESULTS_DIR)/valgrind-extension-test.xml; \
	export TEST_PHP_OUTPUT=$(JUNIT_RESULTS_DIR)/valgrind-run-tests.out; \
	$(MAKE) -C $(BUILD_DIR) CFLAGS="-g" clean test  TESTS="-q -m -s $$TEST_PHP_OUTPUT --show-all $(TESTS)" && grep -e 'errors="0"' $$TEST_PHP_JUNIT  && ! grep -e 'LEAKED TEST SUMMARY' $$TEST_PHP_OUTPUT; \
	)

test_integration: install_ini
	composer test -- $(PHPUNIT)

dist_clean:
	rm -rf $(BUILD_DIR)

clean:
	$(MAKE) -C $(BUILD_DIR) clean

sudo:
	$(eval SUDO:=sudo)

debug:
	$(eval CFLAGS="-g")

strict:
	$(eval CFLAGS=-Wall -Werror -Wextra)

clang_find_files_to_lint:
	@find ./ \
	-path ./tmp -prune -o \
	-path ./vendor -prune -o \
	-path ./tests -prune -o \
	-path ./src/ext/mpack -prune -o \
	-path ./src/ext/third-party -prune -o \
	-iname "*.h" -o -iname "*.c" \
	-type f

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
FPM_OPTS=$(FPM_INFO_OPTS) $(FPM_DIR_OPTS) --after-install=package/post-install.sh --depends="php > 7"

PACKAGES_BUILD_DIR:=build/packages

$(PACKAGES_BUILD_DIR):
	mkdir -p "$@"

.deb: $(PACKAGES_BUILD_DIR)
	fpm -p $(PACKAGES_BUILD_DIR) -t deb $(FPM_OPTS) $(FPM_FILES)
.rpm: $(PACKAGES_BUILD_DIR)
	fpm -p $(PACKAGES_BUILD_DIR) -t rpm $(FPM_OPTS) $(FPM_FILES)
.apk: $(PACKAGES_BUILD_DIR)
	fpm -p $(PACKAGES_BUILD_DIR) -t apk $(FPM_OPTS) --depends=libc6-compat --depends=bash $(FPM_FILES)
.tar.gz: $(PACKAGES_BUILD_DIR)
	fpm -p $(PACKAGES_BUILD_DIR)/$(PACKAGE_NAME)-$(VERSION).x86_64.tar.gz -t tar $(FPM_OPTS) $(FPM_FILES)

packages: .apk .rpm .deb .tar.gz
	tar -zcf packages.tar.gz $(PACKAGES_BUILD_DIR)

verify_pecl_file_definitions:
	@for i in $(notdir $(C_FILES) $(TEST_FILES)); do\
		grep -q $$i package.xml && continue;\
		echo package.xml is missing \"$$i\"; \
		exit 1;\
	done
	@echo "PECL file definitions are correct"

verify_version:
	@grep -q "#define PHP_DDTRACE_VERSION \"$(VERSION)" src/ext/version.h || (echo src/ext/version.h Version missmatch && exit 1)
	@grep -q "const VERSION = '$(VERSION)" src/DDTrace/Tracer.php || (echo src/DDTrace/Tracer.php Version missmatch && exit 1)
	@echo "All version files match"

verify_all: verify_pecl_file_definitions verify_version

.PHONY: dist_clean clean all clang_format_check clang_format_fix install sudo_install test_c test_c_mem test_extension_ci test test_integration install_ini install_all \
	.apk .rpm .deb .tar.gz sudo debug strict run-tests.php verify_pecl_file_definitions verify_version verify_package_xml verify_all
