Q := @
, := ,
PROJECT_ROOT := ${PWD}
TRACER_SOURCE_DIR := $(PROJECT_ROOT)/src/
ASAN ?= $(shell ldd $(shell which php) 2>/dev/null | grep -q asan && echo 1)
SHELL = /bin/bash
APPSEC_SOURCE_DIR = $(PROJECT_ROOT)/appsec/
BUILD_SUFFIX = extension
BUILD_DIR_NAME = tmp/build_$(BUILD_SUFFIX)
BUILD_DIR = $(PROJECT_ROOT)/$(BUILD_DIR_NAME)
BUILD_DIR_APPSEC = $(BUILD_DIR)/appsec/
ZAI_BUILD_DIR = $(PROJECT_ROOT)/tmp/build_zai$(if $(ASAN),_asan)
TEA_BUILD_DIR = $(PROJECT_ROOT)/tmp/build_tea$(if $(ASAN),_asan)
TEA_INSTALL_DIR = $(TEA_BUILD_DIR)/opt
TEA_BUILD_TESTS ?= OFF
TEA_BUILD_BENCHMARKS ?= OFF
TEA_BENCHMARK_REPETITIONS ?= 10
# Note: If the tea benchmark format or output is changed, make changes to ./benchmark/runall.sh
TEA_BENCHMARK_FORMAT ?= json
TEA_BENCHMARK_OUTPUT ?= $(PROJECT_ROOT)/tea/benchmarks/reports/tracer-tea-bench-results.$(TEA_BENCHMARK_FORMAT)
BENCHMARK_EXTRA ?=
COMPONENTS_BUILD_DIR = $(PROJECT_ROOT)/tmp/build_components
SO_FILE = $(BUILD_DIR)/modules/ddtrace.so
AR_FILE = $(BUILD_DIR)/modules/ddtrace.a
WALL_FLAGS = -Wall -Wextra
CFLAGS ?= $(shell [ -n "${DD_TRACE_DOCKER_DEBUG}" ] && echo -O0 || echo -O2) -g $(WALL_FLAGS)
LDFLAGS ?=
PHP_EXTENSION_DIR = $(shell ASAN_OPTIONS=detect_leaks=0 php -d ddtrace.disable=1 -r 'print ini_get("extension_dir");')
PHP_MAJOR_MINOR = $(shell ASAN_OPTIONS=detect_leaks=0 php -n -r 'echo PHP_MAJOR_VERSION . PHP_MINOR_VERSION;')
ARCHITECTURE = $(shell uname -m)
QUIET_TESTS := ${CI_COMMIT_SHA}
RUST_DEBUG_BUILD ?= $(shell [ -n "${DD_TRACE_DOCKER_DEBUG}" ] && echo 1)
EXTRA_CONFIGURE_OPTIONS ?=
ASSUME_COMPILED := ${DD_TRACE_ASSUME_COMPILED}
MAX_TEST_PARALLELISM ?= $(shell nproc)
ALL_TEST_ENV_OVERRIDE := $(shell [ -n "${DD_TRACE_DOCKER_DEBUG}" ] && echo DD_TRACE_IGNORE_AGENT_SAMPLING_RATES=1) DD_TRACE_GIT_METADATA_ENABLED=0 DD_CRASHTRACKER_RECEIVER_TIMEOUT_MS=15000

VERSION := $(shell cat VERSION)

INI_DIR := $(shell ASAN_OPTIONS=detect_leaks=0 php -d ddtrace.disable=1 -i | awk -F"=>" '/Scan this dir for additional .ini files/ {print $$2}')
INI_FILE := $(INI_DIR)/ddtrace.ini
TRACER_SOURCES_INI := -d datadog.trace.sources_path=$(TRACER_SOURCE_DIR)

RUN_TESTS_IS_PARALLEL ?= $(shell test $(PHP_MAJOR_MINOR) -ge 74 && echo 1)

# shuffle parallel tests to evenly distribute test load, avoiding a batch of 32 tests being request-replayer tests
RUN_TESTS_CMD := DD_SERVICE= DD_ENV= REPORT_EXIT_STATUS=1 TEST_PHP_SRCDIR=$(PROJECT_ROOT) USE_TRACKED_ALLOC=1 php -n -d 'memory_limit=-1' $(BUILD_DIR)/run-tests.php $(if $(QUIET_TESTS),,-g FAIL,XFAIL,BORK,WARN,LEAK,XLEAK,SKIP) $(if $(ASAN), --asan) --show-diff -n -p $(shell which php) -q $(if $(RUN_TESTS_IS_PARALLEL), --shuffle -j$(MAX_TEST_PARALLELISM))

C_FILES = $(shell find components components-rs ext src/dogstatsd zend_abstract_interface -name '*.c' -o -name '*.h' | awk '{ printf "$(BUILD_DIR)/%s\n", $$1 }' )
TEST_FILES = $(shell find tests/ext -name '*.php*' -o -name '*.inc' -o -name '*.json' -o -name '*.yaml' -o -name 'CONFLICTS' | awk '{ printf "$(BUILD_DIR)/%s\n", $$1 }' )
RUST_FILES = $(BUILD_DIR)/Cargo.toml $(BUILD_DIR)/Cargo.lock $(shell find components-rs -name '*.c' -o -name '*.rs' -o -name 'Cargo.toml' | awk '{ printf "$(BUILD_DIR)/%s\n", $$1 }' ) $(shell find libdatadog/{build-common,datadog-ffe,datadog-ipc,datadog-ipc-macros,datadog-live-debugger,datadog-live-debugger-ffi,datadog-remote-config,datadog-sidecar,datadog-sidecar-ffi,datadog-sidecar-macros,libdd-alloc,libdd-common,libdd-common-ffi,libdd-crashtracker,libdd-crashtracker-ffi,libdd-data-pipeline,libdd-ddsketch,libdd-dogstatsd-client,libdd-library-config,libdd-library-config-ffi,libdd-log,libdd-telemetry,libdd-telemetry-ffi,libdd-tinybytes,libdd-trace-*,spawn_worker,tools/{cc_utils,sidecar_mockgen},libdd-trace-*,Cargo.toml} \( -type l -o -type f \) \( -path "*/src*" -o -path "*/examples*" -o -path "*Cargo.toml" -o -path "*/build.rs" -o -path "*/tests/dataservice.rs" -o -path "*/tests/service_functional.rs" \) -not -path "*/datadog-ipc/build.rs" -not -path "*/datadog-sidecar-ffi/build.rs")
ALL_OBJECT_FILES = $(C_FILES) $(RUST_FILES) $(BUILD_DIR)/Makefile
TEST_OPCACHE_FILES = $(shell find tests/opcache -name '*.php*' -o -name '.gitkeep' | awk '{ printf "$(BUILD_DIR)/%s\n", $$1 }' )
TEST_STUB_FILES = $(shell find tests/ext -type d -name 'stubs' -exec find '{}' -type f \; | awk '{ printf "$(BUILD_DIR)/%s\n", $$1 }' )
INIT_HOOK_TEST_FILES = $(shell find tests/C2PHP -name '*.phpt' -o -name '*.inc' | awk '{ printf "$(BUILD_DIR)/%s\n", $$1 }' )
M4_FILES = $(shell find m4 -name '*.m4*' | awk '{ printf "$(BUILD_DIR)/%s\n", $$1 }' ) $(BUILD_DIR)/config.m4
XDEBUG_SO_FILE = $(shell find $(shell php-config --extension-dir) -type f -name "xdebug*.so" -exec basename {} \; | tail -n 1)

# Make 'sed -i' portable
ifeq ($(shell { sed --version 2>&1 || echo ''; } | grep GNU > /dev/null && echo GNU || true),GNU)
	SED_I = sed -i
else
	SED_I = sed -i ''
endif

all: $(BUILD_DIR)/configure $(SO_FILE)

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

$(BUILD_DIR)/%Cargo.toml: %Cargo.toml
	$(Q) echo Copying $*Cargo.toml to $@
	$(Q) mkdir -p $(dir $@)
	$(Q) cp -a $*Cargo.toml $@
	$(SED_I) -E 's/"\.\.\/([^"]*)"/"..\/..\/..\/\1"/' $@

$(BUILD_DIR)/Cargo.toml: Cargo.toml
	$(Q) echo Copying Cargo.toml to $@
	$(Q) mkdir -p $(dir $@)
	$(Q) cp -a Cargo.toml $@
	$(SED_I) -E 's/, "profiling",?//' $@

$(BUILD_DIR)/%: %
	$(Q) echo Copying $* to $@
	$(Q) mkdir -p $(dir $@)
	$(Q) cp -a $* $@
	$(Q) rm -f tmp/build_extension/ext/**/*.lo

JUNIT_RESULTS_DIR := $(shell pwd)

all: $(BUILD_DIR)/configure $(SO_FILE)

$(BUILD_DIR)/configure: $(M4_FILES) $(BUILD_DIR)/ddtrace.sym $(BUILD_DIR)/VERSION
	$(Q) (cd $(BUILD_DIR); phpize && $(SED_I) 's/\/FAILED/\/\\bFAILED/' $(BUILD_DIR)/run-tests.php) # Fix PHP 5.4 exit code bug when running selected tests (FAILED vs XFAILED)

$(BUILD_DIR)/run-tests.php: $(if $(ASSUME_COMPILED),, $(BUILD_DIR)/configure)
	$(if $(ASSUME_COMPILED), cp $(shell dirname $(shell realpath $(shell which phpize)))/../lib/php/build/run-tests.php $(BUILD_DIR)/run-tests.php)
	sed -i 's/\bdl(/(bool)(/' $(BUILD_DIR)/run-tests.php # this dl() stuff in run-tests.php is for --EXTENSIONS-- sections, which we don't use; just strip it away (see https://github.com/php/php-src/issues/15367)

$(BUILD_DIR)/Makefile: $(BUILD_DIR)/configure
	$(Q) (cd $(BUILD_DIR); $(if $(ASAN),CFLAGS="${CFLAGS} -DZEND_TRACK_ARENA_ALLOC") ./configure --$(if $(RUST_DEBUG_BUILD),enable,disable)-ddtrace-rust-debug $(if $(ASAN), --enable-ddtrace-sanitize) $(EXTRA_CONFIGURE_OPTIONS))

$(SO_FILE): $(if $(ASSUME_COMPILED),, $(ALL_OBJECT_FILES) $(BUILD_DIR)/compile_rust.sh)
	$(if $(ASSUME_COMPILED),,$(Q) $(MAKE) -C $(BUILD_DIR) -j)

$(AR_FILE): $(ALL_OBJECT_FILES)
	$(Q) $(MAKE) -C $(BUILD_DIR) -j ./modules/ddtrace.a all

$(PHP_EXTENSION_DIR)/ddtrace.so: $(SO_FILE)
	$(Q) $(SUDO) $(if $(ASSUME_COMPILED),cp $(BUILD_DIR)/modules/ddtrace.so $(PHP_EXTENSION_DIR)/ddtrace.so,$(MAKE) -C $(BUILD_DIR) install)

install: $(PHP_EXTENSION_DIR)/ddtrace.so

set_static_option:
	$(eval EXTRA_CONFIGURE_OPTIONS := --enable-ddtrace-rust-library-split)

static: set_static_option $(AR_FILE)

$(INI_FILE):
	$(Q) echo "extension=ddtrace.so" | $(SUDO) tee -a $@

install_ini: $(INI_FILE)

delete_ini:
	$(SUDO) rm $(INI_FILE)

install_appsec:
	cmake -S $(APPSEC_SOURCE_DIR) -B $(BUILD_DIR_APPSEC)
	cd $(BUILD_DIR_APPSEC);make extension ddappsec-helper
	cp $(BUILD_DIR_APPSEC)/ddappsec.so $(PHP_EXTENSION_DIR)/ddappsec.so
	cp $(BUILD_DIR_APPSEC)/libddappsec-helper.so $(PHP_EXTENSION_DIR)/libddappsec-helper.so
	cp $(APPSEC_SOURCE_DIR)/recommended.json /tmp/recommended.json
	$(Q) echo "extension=ddappsec.so" | $(SUDO) tee -a $(INI_FILE)
	$(Q) echo "datadog.appsec.cli_start_on_rinit=true" | $(SUDO) tee -a $(INI_FILE)
	$(Q) echo "datadog.appsec.helper_path=$(PHP_EXTENSION_DIR)/libddappsec-helper.so" | $(SUDO) tee -a $(INI_FILE)
	$(Q) echo "datadog.appsec.rules=/tmp/recommended.json" | $(SUDO) tee -a $(INI_FILE)
	$(Q) echo "datadog.appsec.helper_socket_path=/tmp/ddappsec.sock" | $(SUDO) tee -a $(INI_FILE)
	$(Q) echo "datadog.appsec.helper_lock_path=/tmp/ddappsec.lock" | $(SUDO) tee -a $(INI_FILE)
	$(Q) echo "datadog.appsec.log_file=/tmp/logs/appsec.log" | $(SUDO) tee -a $(INI_FILE)
	$(Q) echo "datadog.appsec.helper_log_file=/tmp/logs/helper.log" | $(SUDO) tee -a $(INI_FILE)

install_all: install install_ini

run_tests: $(TEST_FILES) $(TEST_STUB_FILES) $(BUILD_DIR)/run-tests.php
	$(ALL_TEST_ENV_OVERRIDE) $(RUN_TESTS_CMD) $(TESTS)

test_c: $(SO_FILE) $(TEST_FILES) $(TEST_STUB_FILES) $(BUILD_DIR)/run-tests.php
	$(if $(ASAN), USE_ZEND_ALLOC=0 USE_TRACKED_ALLOC=1 LSAN_OPTIONS=fast_unwind_on_malloc=0$${LSAN_OPTIONS:+$(,)$${LSAN_OPTIONS}}) $(ALL_TEST_ENV_OVERRIDE) $(RUN_TESTS_CMD) -d extension=$(SO_FILE) $(BUILD_DIR)/$(subst $(BUILD_DIR_NAME)/,,$(TESTS))

test_c_coverage: dist_clean
	DD_TRACE_DOCKER_DEBUG=1 EXTRA_CFLAGS="-fprofile-arcs -ftest-coverage" $(MAKE) test_c || exit 0

test_c_disabled: $(SO_FILE) $(TEST_FILES) $(TEST_STUB_FILES) $(BUILD_DIR)/run-tests.php
	( \
	DD_TRACE_CLI_ENABLED=0 DD_TRACE_DEBUG=1 $(RUN_TESTS_CMD) -d extension=$(SO_FILE) $(BUILD_DIR)/$(TESTS) || true; \
	! grep -E 'Successfully triggered flush with trace of size|=== Total [0-9]+ memory leaks detected ===|Segmentation fault|Assertion ' $$(find $(BUILD_DIR)/$(TESTS) -name "*.out" | grep -v segfault_backtrace_enabled.out); \
	)

test_c_observer: $(SO_FILE) $(TEST_FILES) $(TEST_STUB_FILES) $(BUILD_DIR)/run-tests.php
	$(if $(ASAN), USE_ZEND_ALLOC=0 USE_TRACKED_ALLOC=1) $(ALL_TEST_ENV_OVERRIDE) $(RUN_TESTS_CMD) -d extension=$(SO_FILE) -d extension=zend_test.so -d zend_test.observer.enabled=1 -d zend_test.observer.observe_all=1 -d zend_test.observer.show_output=0 $(BUILD_DIR)/$(TESTS)

test_opcache: $(SO_FILE) $(TEST_OPCACHE_FILES) $(BUILD_DIR)/run-tests.php
	$(if $(ASAN), USE_ZEND_ALLOC=0 USE_TRACKED_ALLOC=1) $(RUN_TESTS_CMD) -d extension=$(SO_FILE) $(shell test $(PHP_MAJOR_MINOR) -lt 85 && echo "-d zend_extension=opcache.so") $(BUILD_DIR)/tests/opcache

test_c_mem: $(SO_FILE) $(TEST_FILES) $(TEST_STUB_FILES) $(BUILD_DIR)/run-tests.php
	$(RUN_TESTS_CMD) -d extension=$(SO_FILE) -m $(BUILD_DIR)/$(TESTS)

test_c2php: $(SO_FILE) $(INIT_HOOK_TEST_FILES) $(BUILD_DIR)/run-tests.php
	( \
	set -xe; \
	export PATH="$(PROJECT_ROOT)/tests/ext/valgrind:$$PATH"; \
	sed -i 's/stream_socket_accept($$listenSock, 5)/stream_socket_accept($$listenSock, 20)/' $(BUILD_DIR)/run-tests.php; \
	export USE_ZEND_ALLOC=0; \
	export ZEND_DONT_UNLOAD_MODULES=1; \
	export USE_TRACKED_ALLOC=1; \
	$(shell grep -Pzo '(?<=--ENV--)(?s).+?(?=--)' $(INIT_HOOK_TEST_FILES)) valgrind -q --tool=memcheck --trace-children=yes --vex-iropt-register-updates=allregs-at-mem-access bash -c '$(RUN_TESTS_CMD) -d extension=$(SO_FILE) $(TRACER_SOURCES_INI) -d pcre.jit=0 $(INIT_HOOK_TEST_FILES)'; \
	)

test_with_init_hook: $(SO_FILE) $(INIT_HOOK_TEST_FILES) $(BUILD_DIR)/run-tests.php
	$(if $(ASAN), USE_ZEND_ALLOC=0 USE_TRACKED_ALLOC=1) $(RUN_TESTS_CMD) -d extension=$(SO_FILE) $(TRACER_SOURCES_INI) $(INIT_HOOK_TEST_FILES);

test_extension_ci: $(SO_FILE) $(TEST_FILES) $(TEST_STUB_FILES) $(BUILD_DIR)/run-tests.php
	( \
	set -xe; \
	export PATH="$(PROJECT_ROOT)/tests/ext/valgrind:$$PATH"; \
	export TEST_PHP_JUNIT=$(JUNIT_RESULTS_DIR)/normal-extension-test.xml; \
	$(ALL_TEST_ENV_OVERRIDE) $(RUN_TESTS_CMD) -d extension=$(SO_FILE) $(BUILD_DIR)/$(TESTS); \
	\
	export TEST_PHP_JUNIT=$(JUNIT_RESULTS_DIR)/valgrind-extension-test.xml; \
	export TEST_PHP_OUTPUT=$(JUNIT_RESULTS_DIR)/valgrind-run-tests.out; \
	$(ALL_TEST_ENV_OVERRIDE) $(RUN_TESTS_CMD) -d extension=$(SO_FILE) -m -s $$TEST_PHP_OUTPUT $(BUILD_DIR)/$(TESTS) && ! grep -e 'LEAKED TEST SUMMARY' $$TEST_PHP_OUTPUT; \
	)

build_tea: TEA_BUILD_TESTS=ON
build_tea: TEA_PREFIX_PATH=/opt/catch2
build_tea: build_tea_common

build_tea_benchmarks: TEA_BUILD_BENCHMARKS=ON
build_tea_benchmarks: TEA_PREFIX_PATH=/opt/gbench
build_tea_benchmarks: build_tea_common

build_tea_common:
	$(Q) test -f $(TEA_BUILD_DIR)/.built || \
	( \
		mkdir -p "$(TEA_BUILD_DIR)" "$(TEA_INSTALL_DIR)"; \
		cd $(TEA_BUILD_DIR); \
		CMAKE_PREFIX_PATH=$(TEA_PREFIX_PATH) \
		cmake \
			-DCMAKE_INSTALL_PREFIX=$(TEA_INSTALL_DIR) \
			-DCMAKE_BUILD_TYPE=Debug \
			-DBUILD_TEA_TESTING=$(TEA_BUILD_TESTS) \
			-DBUILD_TEA_BENCHMARKING=$(TEA_BUILD_BENCHMARKS) \
			-DPhpConfig_ROOT=$(shell php-config --prefix) \
			$(if $(ASAN), -DCMAKE_TOOLCHAIN_FILE=$(PROJECT_ROOT)/cmake/asan.cmake) \
		$(PROJECT_ROOT)/tea; \
		$(MAKE) $(MAKEFLAGS) && touch $(TEA_BUILD_DIR)/.built; \
	)

test_tea: clean_tea build_tea
	( \
		$(if $(ASAN), USE_ZEND_ALLOC=0 USE_TRACKED_ALLOC=1) $(MAKE) -C $(TEA_BUILD_DIR) test; \
		! grep -e "=== Total .* memory leaks detected ===" $(TEA_BUILD_DIR)/Testing/Temporary/LastTest.log; \
	)

benchmarks_tea: clean_tea build_tea_benchmarks
	$(TEA_BUILD_DIR)/benchmarks/tea_benchmarks \
		--benchmark_repetitions=$(TEA_BENCHMARK_REPETITIONS) \
		--benchmark_out=$(TEA_BENCHMARK_OUTPUT) \
		--benchmark_format=$(TEA_BENCHMARK_FORMAT) \
		--benchmark_time_unit=ms

install_tea: build_tea
	$(Q) test -f $(TEA_BUILD_DIR)/.installed || \
	( \
		$(MAKE) -C $(TEA_BUILD_DIR) install; \
		touch $(TEA_BUILD_DIR)/.installed; \
	)

build_tea_coverage: TEA_BUILD_TESTS=ON
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
			-DCMAKE_SHARED_LINKER_FLAGS="--coverage" \
			-DCMAKE_MODULE_LINKER_FLAGS="--coverage" \
			-DCMAKE_EXE_LINKER_FLAGS="--coverage" \
			-DPhpConfig_ROOT=$(shell php-config --prefix) \
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
		cmake -DCMAKE_BUILD_TYPE=Debug -DBUILD_ZAI_TESTING=ON $(if $(ASAN), -DCMAKE_TOOLCHAIN_FILE=$(PROJECT_ROOT)/cmake/asan.cmake) -DPhpConfig_ROOT=$(shell php-config --prefix) $(PROJECT_ROOT)/zend_abstract_interface; \
		$(MAKE) $(MAKEFLAGS); \
	)

test_zai: build_zai
	$(MAKE) -C $(ZAI_BUILD_DIR) test $(shell [ -z "${TESTS}"] || echo "ARGS='--test-dir ${TESTS}'") $(if $(ASAN), USE_ZEND_ALLOC=0 USE_TRACKED_ALLOC=1) && ! grep -e "=== Total .* memory leaks detected ===" $(ZAI_BUILD_DIR)/Testing/Temporary/LastTest.log

build_zai_coverage: install_tea_coverage
	( \
	mkdir -p "$(ZAI_BUILD_DIR)"; \
	cd $(ZAI_BUILD_DIR); \
	CMAKE_PREFIX_PATH=/opt/catch2 \
	Tea_ROOT=$(TEA_INSTALL_DIR) \
	cmake -DCMAKE_BUILD_TYPE=Debug -DCMAKE_C_FLAGS="-O0 --coverage" -DCMAKE_SHARED_LINKER_FLAGS="--coverage" -DCMAKE_MODULE_LINKER_FLAGS="--coverage" -DCMAKE_EXE_LINKER_FLAGS="--coverage" -DBUILD_ZAI_TESTING=ON -DPhpConfig_ROOT=$(shell php-config --prefix) $(PROJECT_ROOT)/zend_abstract_interface; \
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
	CMAKE_PREFIX_PATH=/opt/catch2 cmake -DCMAKE_BUILD_TYPE=Debug -DDATADOG_PHP_TESTING=ON -DCMAKE_C_FLAGS="-O0 --coverage" -DCMAKE_SHARED_LINKER_FLAGS="--coverage" -DCMAKE_MODULE_LINKER_FLAGS="--coverage" -DCMAKE_EXE_LINKER_FLAGS="--coverage" $(PROJECT_ROOT)/components; \
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
		--exclude "$(BUILD_DIR)/components-rs/*" \
		--exclude "$(BUILD_DIR)/zend_abstract_interface/*/*" \
		--exclude "$(BUILD_DIR)/ext/vendor/*/*" \
		--exclude "$(BUILD_DIR)/src/dogstatsd/*" \
		--exclude "$(BUILD_DIR)/src/dogstatsd/dogstatsd_client/*" \
		--output-file $(PROJECT_ROOT)/tmp/coverage.info
	$(Q) $(SED_I) 's+tmp/build_extension/ext+ext+g' $(PROJECT_ROOT)/tmp/coverage.info

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
	rm -f composer.lock composer.lock-php$(PHP_MAJOR_MINOR)
	echo $(ZAI_BUILD_DIR)

sudo:
	$(eval SUDO:=sudo)

debug:
	$(eval CFLAGS=$(CFLAGS) -O0 -g)

prod:
	$(eval CFLAGS=$(CFLAGS) -O2 -g0)

strict:
	$(eval CFLAGS=-Wall -Werror -Wextra)

compile_profiler:
	(cd profiling; CARGO_TARGET_DIR=$(PROJECT_ROOT)/tmp/build_profiler cargo build --release --features trigger_time_sample)

install_profiler: compile_profiler
	cp $(PROJECT_ROOT)/tmp/build_profiler/release/libdatadog_php_profiling.so $(PHP_EXTENSION_DIR)/datadog-profiling.so
	$(Q) echo "extension=datadog-profiling.so" | $(SUDO) tee $(INI_DIR)/datadog-profiling.ini

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

cbindgen: remove_cbindgen generate_cbindgen

remove_cbindgen:
	rm -f components-rs/ddtrace.h components-rs/live-debugger.h components-rs/telemetry.h components-rs/sidecar.h components-rs/common.h components-rs/crashtracker.h components-rs/library-config.h

generate_cbindgen: cbindgen_binary # Regenerate components-rs/ddtrace.h components-rs/live-debugger.h components-rs/telemetry.h components-rs/sidecar.h components-rs/common.h components-rs/crashtracker.h components-rs/library-config.h
	( \
		$(command rustup && echo run nightly --) cbindgen --crate ddtrace-php  \
			--config cbindgen.toml \
			--output $(PROJECT_ROOT)/components-rs/ddtrace.h; \
		cd libdatadog; \
		$(command rustup && echo run nightly --) cbindgen --crate libdd-common-ffi \
			--config libdd-common-ffi/cbindgen.toml \
			--output $(PROJECT_ROOT)/components-rs/common.h; \
		$(command rustup && echo run nightly --) cbindgen --crate datadog-live-debugger-ffi  \
			--config datadog-live-debugger-ffi/cbindgen.toml \
			--output $(PROJECT_ROOT)/components-rs/live-debugger.h; \
		$(command rustup && echo run nightly --) cbindgen --crate libdd-telemetry-ffi  \
			--config libdd-telemetry-ffi/cbindgen.toml \
			--output $(PROJECT_ROOT)/components-rs/telemetry.h; \
		$(command rustup && echo run nightly --) cbindgen --crate datadog-sidecar-ffi  \
			--config datadog-sidecar-ffi/cbindgen.toml \
			--output $(PROJECT_ROOT)/components-rs/sidecar.h; \
		$(command rustup && echo run nightly --) cbindgen --crate libdd-crashtracker-ffi  \
			--config libdd-crashtracker-ffi/cbindgen.toml \
			--output $(PROJECT_ROOT)/components-rs/crashtracker.h; \
		$(command rustup && echo run nightly --) cbindgen --crate libdd-library-config-ffi  \
			--config libdd-library-config-ffi/cbindgen.toml \
			--output $(PROJECT_ROOT)/components-rs/library-config.h; \
		if test -d $(PROJECT_ROOT)/tmp; then \
			mkdir -pv "$(BUILD_DIR)"; \
			export CARGO_TARGET_DIR="$(BUILD_DIR)/target"; \
		fi; \
		cargo run -p tools --bin dedup_headers -- $(PROJECT_ROOT)/components-rs/common.h $(PROJECT_ROOT)/components-rs/ddtrace.h $(PROJECT_ROOT)/components-rs/live-debugger.h $(PROJECT_ROOT)/components-rs/telemetry.h $(PROJECT_ROOT)/components-rs/sidecar.h $(PROJECT_ROOT)/components-rs/crashtracker.h $(PROJECT_ROOT)/components-rs/library-config.h \
	)

cbindgen_binary:
	if ! command -v cbindgen &> /dev/null; then \
		cargo install cbindgen --version 0.29.0 --locked; \
	fi

EXT_DIR:=/opt/datadog-php
PACKAGE_NAME:=datadog-php-tracer
FPM_DIR_OPTS=--directories $(EXT_DIR)/etc --config-files $(EXT_DIR)/etc -s dir
define FPM_FILES
	extensions_$(shell test $(1) = arm64 && echo aarch64 || echo $(1))/=$(EXT_DIR)/extensions \
		$(shell test $(1) = windows || echo package/post-install.sh=$(EXT_DIR)/bin/post-install.sh) package/ddtrace.ini.example=$(EXT_DIR)/etc/ \
		docs=$(EXT_DIR)/docs README.md=$(EXT_DIR)/docs/README.md \
		src=$(EXT_DIR)/dd-trace-sources
endef
define FPM_OPTS
	-a $(1) -n $(PACKAGE_NAME) -m dev@datadoghq.com --license "BSD 3-Clause License" --version $(VERSION) \
		--provides $(PACKAGE_NAME) --vendor DataDog  --url "https://docs.datadoghq.com/tracing/setup/php/" --no-depends \
		$(FPM_INFO_OPTS) $(FPM_DIR_OPTS) $(shell test $(1) = windows || echo --after-install=package/post-install.sh)
endef

PACKAGES_BUILD_DIR:=build/packages

$(PACKAGES_BUILD_DIR):
	mkdir -p "$@"

.deb.%: $(PACKAGES_BUILD_DIR)
	fpm -p $(PACKAGES_BUILD_DIR) -t deb $(call FPM_OPTS, $(*)) $(call FPM_FILES, $(*))
.rpm.%: $(PACKAGES_BUILD_DIR)
	fpm -p $(PACKAGES_BUILD_DIR) -t rpm $(call FPM_OPTS, $(*)) $(call FPM_FILES, $(*))
.apk.%: $(PACKAGES_BUILD_DIR)
	fpm -p $(PACKAGES_BUILD_DIR) -t apk $(call FPM_OPTS, $(*)) --depends=bash --depends=curl --depends=libgcc $(call FPM_FILES, $(*))

# Example .tar.gz.aarch64, .tar.gz.x86_64
.tar.gz.%: $(PACKAGES_BUILD_DIR)
	mkdir -p $(PROJECT_ROOT)/tmp/$(PACKAGES_BUILD_DIR)-$(*)
	rm -rf $(PROJECT_ROOT)/tmp/$(PACKAGES_BUILD_DIR)-$(*)/*
	fpm -p $(PROJECT_ROOT)/tmp/$(PACKAGES_BUILD_DIR)-$(*)/$(PACKAGE_NAME)-$(VERSION) -t dir $(call FPM_OPTS, $(*)) $(call FPM_FILES, $(*))
	tar -zcf $(PACKAGES_BUILD_DIR)/$(PACKAGE_NAME)-$(VERSION).$(*).tar.gz -C $(PROJECT_ROOT)/tmp/$(PACKAGES_BUILD_DIR)-$(*)/$(PACKAGE_NAME)-$(VERSION) . --owner=0 --group=0

bundle.tar.gz: $(PACKAGES_BUILD_DIR)
	bash ./tooling/bin/generate-final-artifact.sh \
		$(VERSION) \
		$(PACKAGES_BUILD_DIR) \
		$(PROJECT_ROOT)

$(PACKAGES_BUILD_DIR)/datadog-setup.php: $(PACKAGES_BUILD_DIR)
	bash ./tooling/bin/generate-installers.sh \
		$(VERSION) \
		$(PACKAGES_BUILD_DIR)

build_pecl_package:
	echo $(subst $(BUILD_DIR)/,,$(C_FILES) $(RUST_FILES) $(TEST_FILES) $(TEST_STUB_FILES) $(M4_FILES) Cargo.lock) | tooling/bin/pecl-build

dbgsym.tar.gz: $(PACKAGES_BUILD_DIR)
	$(if $(DDTRACE_MAKE_PACKAGES_ASAN), , tar -zcf $(PACKAGES_BUILD_DIR)/dd-library-php-$(VERSION)_windows_debugsymbols.tar.gz ./extensions_x86_64_debugsymbols --owner=0 --group=0)

installer_packages: .apk.x86_64 .apk.aarch64 .rpm.x86_64 .rpm.aarch64 .deb.x86_64 .deb.arm64 .tar.gz.x86_64 .tar.gz.aarch64 bundle.tar.gz dbgsym.tar.gz
	tar --use-compress-program=pigz --exclude='dd-library-php-ssi-*' -cf packages.tar.gz $(PACKAGES_BUILD_DIR) --owner=0 --group=0

ssi_packages: $(PACKAGES_BUILD_DIR)
	bash ./tooling/bin/generate-ssi-package.sh \
		$(VERSION) \
		$(PACKAGES_BUILD_DIR)

calculate_package_sha256_sums: $(PACKAGES_BUILD_DIR)/datadog-setup.php installer_packages
	(cd build/packages && find . -type f -exec sha256sum {} + > ../../package_sha256sums)

packages: $(PACKAGES_BUILD_DIR)/datadog-setup.php ssi_packages installer_packages

# Generates the src/bridge/_generated_*.php files.
generate:
	@composer -dtooling/generation update
	@composer -dtooling/generation generate
	@composer -dtooling/generation verify

# Generates the stubs file for the public API
generate_stubs:
	@composer -dtooling/stubs update
	@composer -dtooling/stubs generate

tested_versions:
	@composer -dtooling/tested_versions generate

# Find all generated core dumps, sorted by date descending
cores:
	find . -path "./*/vendor" -prune -false -o \( -type f -regex ".*\/core\.?[0-9]*" \) -printf "%T@ %Tc %p\n" | sort -n -r

########################################################################################################################
# TESTS
########################################################################################################################
ENV_OVERRIDE := $(shell ([ -n "${DD_TRACE_DOCKER_DEBUG}" ] && [ -z "${DD_TRACE_AUTOLOAD_NO_COMPILE}" ]) || ([ -n "${DD_TRACE_AUTOLOAD_NO_COMPILE}" ] && [ "${DD_TRACE_AUTOLOAD_NO_COMPILE}" != "0" ]) && [ -z "${DD_TRACE_SOURCES_PATH}" ] && echo DD_AUTOLOAD_NO_COMPILE=true DD_TRACE_SOURCES_PATH=$(TRACER_SOURCE_DIR)) DD_DOGSTATSD_URL=http://request-replayer:80 $(ALL_TEST_ENV_OVERRIDE)
TEST_EXTRA_INI ?=
TEST_EXTRA_ENV ?=

### DDTrace tests ###
TESTS_ROOT = ./tests
COMPOSER = $(if $(ASAN), ASAN_OPTIONS=detect_leaks=0) COMPOSER_MEMORY_LIMIT=-1 composer --no-interaction
DDPROF_IDENTIFIER ?=
PHPUNIT_OPTS ?=
PHPUNIT_JUNIT ?=
PHPUNIT = $(TESTS_ROOT)/vendor/bin/phpunit $(PHPUNIT_OPTS) $(if $(PHPUNIT_JUNIT),--log-junit $(PHPUNIT_JUNIT)) --config=$(TESTS_ROOT)/phpunit.xml
PHPUNIT_COVERAGE ?=
PHPBENCH_OPTS ?=
PHPBENCH_CONFIG ?= $(TESTS_ROOT)/phpbench.json
PHPBENCH_OPCACHE_CONFIG ?= $(TESTS_ROOT)/phpbench-opcache.json
PHPBENCH = $(TESTS_ROOT)/Benchmarks/vendor/bin/phpbench $(PHPBENCH_OPTS) run
PHPCOV = $(TESTS_ROOT)/vendor/bin/phpcov
TELEMETRY_ENABLED=0

TEST_INTEGRATIONS_70 := \
	test_integrations_deferred_loading \
	test_integrations_curl \
	test_integrations_kafka \
	test_integrations_memcache \
	test_integrations_memcached \
	test_integrations_mongodb_1x \
	test_integrations_mysqli \
	test_integrations_pdo \
	test_integrations_elasticsearch1 \
	test_integrations_guzzle5 \
	test_integrations_guzzle6 \
	test_integrations_pcntl \
	test_integrations_phpredis3 \
	test_integrations_phpredis4 \
	test_integrations_phpredis5 \
	test_integrations_predis_1 \
	test_integrations_ratchet \
	test_integrations_sqlsrv

TEST_WEB_70 := \
	test_metrics \
	test_web_cakephp_28 \
	test_web_codeigniter_22 \
	test_web_codeigniter_31 \
	test_web_laravel_42 \
	test_web_lumen_52 \
	test_web_nette_24 \
	test_web_slim_312 \
	test_web_symfony_23 \
	test_web_symfony_28 \
	test_web_symfony_30 \
	test_web_symfony_33 \
	test_web_symfony_34 \
	test_web_yii_2049 \
	test_web_wordpress_48 \
	test_web_wordpress_55 \
	test_web_wordpress_61 \
	test_web_zend_1 \
	test_web_custom

TEST_INTEGRATIONS_71 := \
	test_integrations_deferred_loading \
	test_integrations_amqp2 \
	test_integrations_curl \
	test_integrations_kafka \
	test_integrations_memcache \
	test_integrations_memcached \
	test_integrations_mongodb_1x \
	test_integrations_monolog1 \
	test_integrations_mysqli \
	test_integrations_pdo \
	test_integrations_elasticsearch1 \
	test_integrations_guzzle5 \
	test_integrations_guzzle6 \
	test_integrations_pcntl \
	test_integrations_phpredis3 \
	test_integrations_phpredis4 \
	test_integrations_phpredis5 \
	test_integrations_predis_1 \
	test_integrations_ratchet \
	test_integrations_sqlsrv \
	test_opentracing_10

TEST_WEB_71 := \
	test_metrics \
	test_web_cakephp_28 \
	test_web_cakephp_310 \
	test_web_codeigniter_22 \
	test_web_codeigniter_31 \
	test_web_laravel_42 \
	test_web_laravel_57 \
	test_web_laravel_58 \
	test_web_lumen_52 \
	test_web_lumen_56 \
	test_web_lumen_58 \
	test_web_nette_24 \
	test_web_slim_312 \
	test_web_symfony_23 \
	test_web_symfony_28 \
	test_web_symfony_30 \
	test_web_symfony_33 \
	test_web_symfony_34 \
	test_web_symfony_40 \
	test_web_symfony_42 \
	test_web_yii_2049 \
	test_web_wordpress_48 \
	test_web_wordpress_55 \
	test_web_wordpress_61 \
	test_web_zend_1 \
	test_web_custom

TEST_INTEGRATIONS_72 := \
	test_integrations_deferred_loading \
	test_integrations_amqp2 \
	test_integrations_amqp_latest \
	test_integrations_curl \
	test_integrations_kafka \
	test_integrations_memcache \
	test_integrations_memcached \
	test_integrations_mongodb_1x \
	test_integrations_monolog1 \
	test_integrations_monolog2 \
	test_integrations_mysqli \
	test_integrations_pdo \
	test_integrations_elasticsearch1 \
	test_integrations_guzzle5 \
	test_integrations_guzzle6 \
	test_integrations_guzzle_latest \
	test_integrations_pcntl \
	test_integrations_phpredis3 \
	test_integrations_phpredis4 \
	test_integrations_phpredis5 \
	test_integrations_predis_1 \
	test_integrations_predis_2 \
	test_integrations_predis_latest \
	test_integrations_ratchet \
	test_integrations_sqlsrv \
	test_opentracing_10

TEST_WEB_72 := \
	test_metrics \
	test_web_cakephp_310 \
	test_web_codeigniter_22 \
	test_web_codeigniter_31 \
	test_web_drupal_89 \
	test_web_laravel_42 \
	test_web_laravel_57 \
	test_web_laravel_58 \
	test_web_lumen_52 \
	test_web_lumen_56 \
	test_web_lumen_58 \
	test_web_nette_24 \
	test_web_nette_31 \
	test_web_slim_312 \
	test_web_slim_48 \
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
	test_web_wordpress_61 \
	test_web_yii_2049 \
	test_web_zend_1 \
	test_web_custom

TEST_INTEGRATIONS_73 :=\
	test_integrations_deferred_loading \
	test_integrations_amqp2 \
	test_integrations_amqp_latest \
	test_integrations_curl \
	test_integrations_kafka \
	test_integrations_memcache \
	test_integrations_memcached \
	test_integrations_mongodb_1x \
	test_integrations_monolog1 \
	test_integrations_monolog2 \
	test_integrations_mysqli \
	test_integrations_pdo \
	test_integrations_elasticsearch7 \
	test_integrations_guzzle5 \
	test_integrations_guzzle6 \
	test_integrations_guzzle_latest \
	test_integrations_pcntl \
	test_integrations_phpredis3 \
	test_integrations_phpredis4 \
	test_integrations_phpredis5 \
	test_integrations_predis_1 \
	test_integrations_predis_2 \
	test_integrations_predis_latest \
	test_integrations_ratchet \
	test_integrations_sqlsrv \
	test_opentracing_10

TEST_WEB_73 := \
	test_metrics \
	test_web_cakephp_310 \
	test_web_codeigniter_22 \
	test_web_codeigniter_31 \
	test_web_drupal_89 \
	test_web_laminas_mvc_33 \
	test_web_laravel_57 \
	test_web_laravel_58 \
	test_web_laravel_8x \
	test_web_lumen_52 \
	test_web_lumen_56 \
	test_web_lumen_58 \
	test_web_lumen_81 \
	test_web_magento_23 \
	test_web_nette_24 \
	test_web_nette_31 \
	test_web_slim_312 \
	test_web_slim_48 \
	test_web_symfony_34 \
	test_web_symfony_40 \
	test_web_symfony_42 \
	test_web_symfony_44 \
	test_web_symfony_50 \
	test_web_symfony_51 \
	test_web_symfony_52 \
	test_web_wordpress_48 \
	test_web_wordpress_55 \
	test_web_wordpress_61 \
	test_web_yii_latest \
	test_web_zend_1 \
	test_web_custom

TEST_INTEGRATIONS_74 := \
	test_integrations_deferred_loading \
	test_integrations_amqp2 \
	test_integrations_amqp_latest \
	test_integrations_curl \
	test_integrations_kafka \
	test_integrations_memcache \
	test_integrations_memcached \
	test_integrations_mongodb_1x \
	test_integrations_monolog1 \
	test_integrations_monolog2 \
	test_integrations_mysqli \
	test_opentelemetry_1 \
	test_integrations_pdo \
	test_integrations_elasticsearch7 \
	test_integrations_elasticsearch8 \
	test_integrations_guzzle5 \
	test_integrations_guzzle6 \
	test_integrations_guzzle_latest \
	test_integrations_pcntl \
	test_integrations_phpredis3 \
	test_integrations_phpredis4 \
	test_integrations_phpredis5 \
	test_integrations_predis_1 \
	test_integrations_predis_2 \
	test_integrations_predis_latest \
	test_integrations_ratchet \
	test_integrations_roadrunner \
	test_integrations_sqlsrv \
	test_opentracing_10

TEST_WEB_74 := \
	test_metrics \
	test_web_cakephp_310 \
	test_web_cakephp_45 \
	test_web_codeigniter_22 \
	test_web_codeigniter_31 \
	test_web_drupal_89 \
	test_web_drupal_95 \
	test_web_laminas_mvc_33 \
	test_web_laravel_57 \
	test_web_laravel_58 \
	test_web_laravel_8x \
	test_web_lumen_52 \
	test_web_lumen_56 \
	test_web_lumen_58 \
	test_web_lumen_81 \
	test_web_magento_23 \
	test_web_nette_24 \
	test_web_nette_31 \
	test_web_slim_312 \
	test_web_slim_latest \
	test_web_symfony_34 \
	test_web_symfony_40 \
	test_web_symfony_44 \
	test_web_symfony_50 \
	test_web_symfony_51 \
	test_web_symfony_52 \
	test_web_wordpress_48 \
	test_web_wordpress_55 \
	test_web_wordpress_59 \
	test_web_wordpress_61 \
	test_web_yii_latest \
	test_web_zend_1 \
	test_web_custom

# NOTE: test_integrations_phpredis5 is not included in the PHP 8.0 integrations tests because of this bug that only
# shows up in debug builds of PHP (https://github.com/phpredis/phpredis/issues/1869).
# Since we run tests in CI using php debug builds, we run test_integrations_phpredis5 in a separate non-debug container.
TEST_INTEGRATIONS_80 := \
	test_integrations_deferred_loading \
	test_integrations_amqp2 \
	test_integrations_amqp_latest \
	test_integrations_curl \
	test_integrations_kafka \
	test_integrations_laminaslog2 \
	test_integrations_memcache \
	test_integrations_memcached \
	test_integrations_mongodb_1x \
	test_integrations_monolog1 \
	test_integrations_monolog2 \
	test_integrations_mysqli \
	test_opentelemetry_1 \
	test_integrations_pdo \
	test_integrations_elasticsearch7 \
	test_integrations_elasticsearch8 \
	test_integrations_guzzle5 \
	test_integrations_guzzle6 \
	test_integrations_guzzle_latest \
	test_integrations_pcntl \
	test_integrations_phpredis5 \
	test_integrations_predis_1 \
	test_integrations_predis_2 \
	test_integrations_predis_latest \
	test_integrations_ratchet \
	test_integrations_sqlsrv \
	test_integrations_swoole_5 \
	test_opentracing_10

TEST_WEB_80 := \
	test_metrics \
	test_web_cakephp_45 \
	test_web_codeigniter_22 \
	test_web_codeigniter_31 \
	test_web_drupal_95 \
	test_web_laminas_rest_latest \
	test_web_laminas_mvc_33 \
	test_web_laravel_8x \
	test_web_laravel_9x \
	test_web_lumen_81 \
	test_web_lumen_90 \
	test_web_nette_24 \
	test_web_nette_31 \
	test_web_slim_312 \
	test_web_slim_latest \
	test_web_symfony_44 \
	test_web_symfony_51 \
	test_web_symfony_52 \
	test_web_wordpress_59 \
	test_web_wordpress_61 \
	test_web_yii_latest \
	test_web_zend_1_21 \
	test_web_custom

TEST_INTEGRATIONS_81 := \
	test_integrations_amqp2 \
	test_integrations_amqp_latest \
	test_integrations_curl \
	test_integrations_deferred_loading \
	test_integrations_kafka \
	test_integrations_laminaslog2 \
	test_integrations_memcache \
	test_integrations_memcached \
	test_integrations_mongodb_latest \
	test_integrations_monolog1 \
	test_integrations_monolog2 \
	test_integrations_monolog_latest \
	test_integrations_mysqli \
	test_opentelemetry_1 \
	test_opentelemetry_beta \
	test_integrations_googlespanner_latest \
	test_integrations_guzzle_latest \
	test_integrations_pcntl \
	test_integrations_pdo \
	test_integrations_elasticsearch7 \
	test_integrations_elasticsearch8 \
	test_integrations_phpredis5 \
	test_integrations_predis_1 \
	test_integrations_predis_2 \
	test_integrations_predis_latest \
	test_integrations_ratchet \
	test_integrations_sqlsrv \
	test_integrations_swoole_5 \
	test_opentracing_10

TEST_WEB_81 := \
	test_metrics \
	test_web_cakephp_45 \
	test_web_cakephp_latest \
	test_web_codeigniter_22 \
	test_web_codeigniter_31 \
	test_web_drupal_95 \
	test_web_drupal_101 \
	test_web_laminas_rest_latest \
	test_web_laminas_mvc_33 \
	test_web_laminas_mvc_latest \
	test_web_laravel_8x \
	test_web_laravel_9x \
	test_web_laravel_10x \
	test_web_lumen_81 \
	test_web_lumen_90 \
	test_web_magento_24 \
	test_web_nette_24 \
	test_web_nette_latest \
	test_web_slim_312 \
	test_web_slim_latest \
	test_web_wordpress_59 \
	test_web_wordpress_61 \
	test_web_custom \
	test_web_zend_1_21
#	test_web_yii_latest \

TEST_INTEGRATIONS_82 := \
	test_integrations_amqp2 \
	test_integrations_amqp_latest \
	test_integrations_curl \
	test_integrations_deferred_loading \
	test_integrations_kafka \
	test_integrations_laminaslog2 \
	test_integrations_memcache \
	test_integrations_memcached \
	test_integrations_mongodb_latest \
	test_integrations_monolog1 \
	test_integrations_monolog2 \
	test_integrations_monolog_latest \
	test_integrations_mysqli \
	test_integrations_openai_latest \
	test_opentelemetry_1 \
	test_opentelemetry_beta \
	test_integrations_googlespanner_latest \
	test_integrations_guzzle_latest \
	test_integrations_pcntl \
	test_integrations_pdo \
	test_integrations_elasticsearch7 \
	test_integrations_elasticsearch8 \
	test_integrations_elasticsearch_latest \
	test_integrations_phpredis5 \
	test_integrations_predis_1 \
	test_integrations_predis_2 \
	test_integrations_predis_latest \
	test_integrations_frankenphp \
	test_integrations_ratchet \
	test_integrations_roadrunner \
	test_integrations_sqlsrv \
	test_integrations_swoole_5 \
	test_opentracing_10

TEST_WEB_82 := \
	test_metrics \
	test_web_cakephp_45 \
	test_web_cakephp_latest \
	test_web_codeigniter_22 \
	test_web_codeigniter_31 \
	test_web_drupal_95 \
	test_web_drupal_101 \
	test_web_laminas_rest_latest \
	test_web_laminas_mvc_latest \
	test_web_laravel_8x \
	test_web_laravel_9x \
	test_web_laravel_10x \
	test_web_laravel_11x \
	test_web_laravel_latest \
	test_web_laravel_octane_latest \
	test_web_lumen_81 \
	test_web_lumen_90 \
	test_web_lumen_100 \
	test_web_magento_24 \
	test_web_nette_24 \
	test_web_nette_latest \
	test_web_slim_312 \
	test_web_slim_latest \
	test_web_symfony_62 \
	test_web_symfony_latest \
	test_web_wordpress_59 \
	test_web_wordpress_61 \
	test_web_custom \
	test_web_zend_1_21
#	test_web_yii_latest \

TEST_INTEGRATIONS_83 := \
	test_integrations_amqp2 \
	test_integrations_amqp_latest \
	test_integrations_curl \
	test_integrations_deferred_loading \
	test_integrations_kafka \
	test_integrations_laminaslog2 \
	test_integrations_memcache \
	test_integrations_memcached \
	test_integrations_mongodb_latest \
	test_integrations_monolog1 \
	test_integrations_monolog2 \
	test_integrations_monolog_latest \
	test_integrations_mysqli \
	test_integrations_openai_latest \
	test_opentelemetry_1 \
	test_opentelemetry_beta \
	test_integrations_googlespanner_latest \
	test_integrations_guzzle_latest \
	test_integrations_pcntl \
	test_integrations_pdo \
	test_integrations_elasticsearch7 \
	test_integrations_elasticsearch8 \
	test_integrations_elasticsearch_latest \
	test_integrations_phpredis5 \
	test_integrations_predis_1 \
	test_integrations_predis_2 \
	test_integrations_predis_latest \
	test_integrations_frankenphp \
	test_integrations_ratchet \
	test_integrations_roadrunner \
	test_integrations_sqlsrv \
	test_integrations_swoole_5 \
	test_opentracing_10

TEST_WEB_83 := \
	test_metrics \
	test_web_cakephp_45 \
	test_web_cakephp_latest \
	test_web_codeigniter_22 \
	test_web_codeigniter_31 \
	test_web_drupal_95 \
	test_web_laravel_8x \
	test_web_laravel_9x \
	test_web_laravel_10x \
	test_web_laravel_11x \
	test_web_laravel_latest \
	test_web_laravel_octane_latest \
	test_web_lumen_81 \
	test_web_lumen_90 \
	test_web_lumen_100 \
	test_web_nette_24 \
	test_web_nette_latest \
	test_web_slim_312 \
	test_web_slim_latest \
	test_web_symfony_62 \
	test_web_symfony_latest \
	test_web_wordpress_59 \
	test_web_wordpress_61 \
	test_web_custom \
	test_web_zend_1_21

TEST_INTEGRATIONS_84 := \
	test_integrations_amqp2 \
	test_integrations_amqp_latest \
	test_integrations_curl \
	test_integrations_deferred_loading \
	test_integrations_kafka \
	test_integrations_laminaslog2 \
	test_integrations_memcache \
	test_integrations_memcached \
	test_integrations_mongodb_latest \
	test_integrations_monolog1 \
	test_integrations_monolog2 \
	test_integrations_monolog_latest \
	test_integrations_mysqli \
	test_integrations_openai_latest \
	test_opentelemetry_1 \
	test_integrations_googlespanner_latest \
	test_integrations_guzzle_latest \
	test_integrations_pcntl \
	test_integrations_pdo \
	test_integrations_elasticsearch7 \
	test_integrations_elasticsearch8 \
	test_integrations_elasticsearch_latest \
	test_integrations_predis_2 \
	test_integrations_predis_latest \
	test_integrations_frankenphp \
	test_integrations_ratchet \
	test_integrations_roadrunner \
	test_integrations_sqlsrv \
	test_integrations_swoole_5 \
	test_opentracing_10

TEST_WEB_84 := \
	test_metrics \
	test_web_cakephp_latest \
	test_web_codeigniter_22 \
	test_web_codeigniter_31 \
	test_web_laravel_octane_latest \
	test_web_lumen_100 \
	test_web_nette_latest \
	test_web_slim_312 \
	test_web_symfony_latest \
	test_web_wordpress_59 \
	test_web_wordpress_61 \
	test_web_custom \
	test_web_zend_1_21

TEST_INTEGRATIONS_85 := \
	test_integrations_amqp2 \
	test_integrations_amqp_latest \
	test_integrations_curl \
	test_integrations_deferred_loading \
	test_integrations_kafka \
	test_integrations_laminaslog2 \
	test_integrations_memcache \
	test_integrations_memcached \
	test_integrations_mongodb_latest \
	test_integrations_monolog1 \
	test_integrations_monolog2 \
	test_integrations_monolog_latest \
	test_integrations_mysqli \
	test_integrations_openai_latest \
	test_opentelemetry_1 \
	test_integrations_guzzle_latest \
	test_integrations_pcntl \
	test_integrations_pdo \
	test_integrations_elasticsearch7 \
	test_integrations_elasticsearch8 \
	test_integrations_elasticsearch_latest \
	test_integrations_predis_2 \
	test_integrations_predis_latest \
	test_integrations_frankenphp \
	test_integrations_ratchet \
	test_integrations_sqlsrv \
	test_opentracing_10

TEST_WEB_85 := \
	test_metrics \
	test_web_cakephp_latest \
	test_web_codeigniter_22 \
	test_web_codeigniter_31 \
	test_web_lumen_100 \
	test_web_slim_312 \
	test_web_symfony_latest \
	test_web_wordpress_59 \
	test_web_wordpress_61 \
	test_web_custom \
	test_web_zend_1_21

# to check: test_web_drupal_95, test_web_laravel_latest, test_web_slim_latest, test_integrations_phpredis6

FILTER ?= .
MAX_RETRIES := 3
RUN_WEB_BENCHES_WITH_DDPROF ?=

# Note: The "composer show" command below outputs a csv with pairs of dependency;version such as "phpunit/phpunit;9.6.17"
define run_composer_with_retry
	for i in $$(seq 1 $(MAX_RETRIES)); do \
		echo "Attempting composer update (attempt $$i of $(MAX_RETRIES))..."; \
		$(COMPOSER) --working-dir=$(if $1,$1,.) update $2 && break || (echo "Retry $$i failed, waiting 5 seconds before next attempt..." && sleep 5); \
	done \

	mkdir -p /tmp/artifacts
	$(COMPOSER) --working-dir=$1 show -f json | grep -o '"name": "[^"]*\|"version": "[^"]*' | paste -d';' - - | sed 's/"name": //; s/"version": //' | tr -d '"' >> "/tmp/artifacts/web_versions.csv"
endef

define run_tests_without_coverage
	$(TEST_EXTRA_ENV) $(ENV_OVERRIDE) php $(TEST_EXTRA_INI) -d datadog.instrumentation_telemetry_enabled=$(shell (test $(TELEMETRY_ENABLED) && echo 1) || (test $(PHP_MAJOR_MINOR) -ge 83 && echo 1) || echo 0) -d datadog.trace.sidecar_trace_sender=$(shell test $(PHP_MAJOR_MINOR) -ge 83 && echo 1 || echo 0) $(TRACER_SOURCES_INI) $(PHPUNIT) $(1) --filter=$(FILTER)
endef

define run_tests_with_coverage
	$(TEST_EXTRA_ENV) $(ENV_OVERRIDE) php -d zend_extension=$(XDEBUG_SO_FILE) -d xdebug.mode=coverage $(TEST_EXTRA_INI) -d datadog.instrumentation_telemetry_enabled=$(TELEMETRY_ENABLED) $(TRACER_SOURCES_INI) $(PHPUNIT) $(1) --filter=$(FILTER) --coverage-php reports/cov/$(coverage_file)
endef

# Note: The condition below only checks for existence - i.e., whether PHPUNIT_COVERAGE is set to anything.
define run_tests
	$(eval coverage_file := $(shell echo $(1) | tr '[:upper:]' '[:lower:]' | tr '/=' '_' | tr -d '-').cov) \
	$(if $(PHPUNIT_COVERAGE),$(call run_tests_with_coverage,$(1)),$(call run_tests_without_coverage,$(1)))
endef

define run_tests_debug
	$(eval TEST_EXTRA_ENV=$(TEST_EXTRA_ENV) DD_TRACE_DEBUG=1)
	(set -o pipefail; { $(call run_tests,$(1)) 2>&1 >&3 | \
		tee >(grep --line-buffered -vE '\[ddtrace\] \[debug\]|\[ddtrace\] \[info\]' >&2) | \
		{ ! (grep --line-buffered -E '\[error\]|\[warning\]|\[deprecated\]' >/dev/null && \
		echo $$'\033[41m'"ERROR: Found debug log errors in the output."$$'\033[0m'); }; } 3>&1 \
	) && ([ -n "${DD_TRACE_DOCKER_DEBUG}" ] || timeout 10 bash -c 'wait' 2>/dev/null || true) \
	$(eval TEST_EXTRA_ENV=)
endef


define run_benchmarks
	$(ENV_OVERRIDE) $(2) php -d extension=redis-5.3.7.so $(TEST_EXTRA_INI) $(TRACER_SOURCES_INI) $(PHPBENCH) --config=$(1) "--filter=$(if $(RUN_WEB_BENCHES_WITH_DDPROF),$(FILTER),Ddprof(*SKIP)(*F)|^.*?$(FILTER))" --report=all --output=file --output=console $(BENCHMARK_EXTRA)
endef

define run_benchmarks_with_ddprof
	$(ENV_OVERRIDE) ddprof -S $(DDPROF_IDENTIFIER) php -d extension=redis-5.3.7.so $(TRACER_SOURCES_INI) $(REQUEST_INIT_HOOK) $(PHPBENCH) --config=$(1) "--filter=Ddprof(*SKIP)(*F)|^.*?$(FILTER)" --report=all --output=file --output=console $(BENCHMARK_EXTRA)
endef

define run_composer_with_lock
	rm $1/$(if $2,$(2:.json=.lock),composer.lock)-php* 2>/dev/null || true
	$(eval CURRENT_COMPOSER:=$(COMPOSER))
	$(if $(2), $(eval COMPOSER:=COMPOSER=$2 $(COMPOSER)))
	$(call run_composer_with_retry,$1,)
	$(eval COMPOSER:=$(CURRENT_COMPOSER))
	find $1/vendor* \( -name Tests -prune -o -name tests -prune \) -exec rm -rf '{}' \;
	touch $1/$(if $2,$(2:.json=.lock),composer.lock)-php$(PHP_MAJOR_MINOR)
endef

# use this as the first target if you want to use uncompiled files instead of the _generated_*.php compiled file.
dev:
	$(Q) :
	$(Q) $(eval ENV_OVERRIDE:=$(ENV_OVERRIDE) DD_AUTOLOAD_NO_COMPILE=true)

use_generated:
	$(Q) :
	$(Q) $(eval ENV_OVERRIDE:=$(ENV_OVERRIDE) DD_AUTOLOAD_NO_COMPILE=)

clean_test:
	find $(TESTS_ROOT)/ -not \( -name "Frameworks" -prune \) -not \( -name "ext" -prune \) -not \( -name "randomized" -prune \) -name "composer.lock" -o -name "vendor" -print -exec rm -rf {} \;
	find $(TESTS_ROOT)/Frameworks/ -path "*/vendor/*" -prune -o -wholename "*/cache/*.php" -print -exec rm -rf {} \;

composer_tests_update:
	$(call run_composer_with_lock,$(TESTS_ROOT))

global_test_run_dependencies: install_all $(TESTS_ROOT)/./composer.lock-php$(PHP_MAJOR_MINOR)

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
test_unit_coverage: global_test_run_dependencies
	PHPUNIT_COVERAGE=1 $(MAKE) test_unit

test_integration: global_test_run_dependencies
	$(eval TEST_EXTRA_ENV=DD_TRACE_AGENT_RETRIES=3 DD_TRACE_AGENT_FLUSH_INTERVAL=333 DD_TRACE_AGENT_PORT=9126 DD_AGENT_HOST=test-agent)
	$(call run_tests,--testsuite=integration $(TESTS))
	$(eval TEST_EXTRA_ENV=)
test_integration_coverage:
	PHPUNIT_COVERAGE=1 $(MAKE) test_integration

test_auto_instrumentation: global_test_run_dependencies
	$(call run_tests,--testsuite=auto-instrumentation $(TESTS))
	# Cleaning up composer.json files in tests/AutoInstrumentation modified for TLS during tests
	git checkout $(TESTS_ROOT)/AutoInstrumentation/**/composer.json
test_auto_instrumentation_coverage:
	PHPUNIT_COVERAGE=1 $(MAKE) test_auto_instrumentation

test_composer: global_test_run_dependencies
	$(call run_tests,--testsuite=composer-tests $(TESTS))
test_composer_coverage:
	PHPUNIT_COVERAGE=1 $(MAKE) test_composer

test_distributed_tracing: global_test_run_dependencies
	$(call run_tests,--testsuite=distributed-tracing $(TESTS))
test_distributed_tracing_coverage:
	PHPUNIT_COVERAGE=1 $(MAKE) test_distributed_tracing

test_metrics: global_test_run_dependencies
	$(call run_tests,--testsuite=metrics $(TESTS))

benchmarks_run_dependencies: global_test_run_dependencies tests/Frameworks/Symfony/Version_5_2/composer.lock-php$(PHP_MAJOR_MINOR) tests/Frameworks/Laravel/Version_10_x/composer.lock-php$(PHP_MAJOR_MINOR) tests/Benchmarks/composer.lock-php$(PHP_MAJOR_MINOR)
	php tests/Frameworks/Symfony/Version_5_2/bin/console cache:clear --no-warmup --env=prod

call_benchmarks:
	if [ -n "$(DDPROF_IDENTIFIER)" ]; then \
		$(call run_benchmarks_with_ddprof,$(PHPBENCH_CONFIG)); \
	else \
		$(call run_benchmarks,$(PHPBENCH_CONFIG)); \
	fi

call_benchmarks_opcache:
	if [ -n "$(DDPROF_IDENTIFIER)" ]; then \
		$(call run_benchmarks_with_ddprof,$(PHPBENCH_OPCACHE_CONFIG)); \
	else \
		$(call run_benchmarks,$(PHPBENCH_OPCACHE_CONFIG)); \
	fi

benchmarks: benchmarks_run_dependencies call_benchmarks

benchmarks_opcache: benchmarks_run_dependencies call_benchmarks_opcache

define run_opentelemetry_tests
	$(eval TEST_EXTRA_ENV=$(shell [ $(PHP_MAJOR_MINOR) -ge 81 ] && echo "OTEL_PHP_FIBERS_ENABLED=1" || echo '') DD_TRACE_OTEL_ENABLED=1 DD_TRACE_GENERATE_ROOT_SPAN=0 $1)
	$(call run_tests,--testsuite=opentelemetry1 $(TESTS))
	$(eval TEST_EXTRA_ENV=)
endef

test_opentelemetry_beta: global_test_run_dependencies tests/Frameworks/Custom/OpenTelemetry/composer.lock-php$(PHP_MAJOR_MINOR) tests/OpenTelemetry/composer-beta$(shell [ $(PHP_MAJOR_MINOR) -le 81 ] && echo "-pre-8.1" || echo '').lock-php$(PHP_MAJOR_MINOR)
	$(call run_opentelemetry_tests, TESTSUITE_VENDOR_DIR=vendor-beta)

tests/OpenTelemetry/composer-%.lock-php$(PHP_MAJOR_MINOR): tests/OpenTelemetry/composer-%.json
	$(call run_composer_with_lock,tests/OpenTelemetry,composer-$(*).json)

test_opentelemetry_1: global_test_run_dependencies tests/Frameworks/Custom/OpenTelemetry/composer.lock-php$(PHP_MAJOR_MINOR) tests/OpenTelemetry/composer$(shell [ $(PHP_MAJOR_MINOR) -le 81 ] && echo "-pre-8.1" || echo '').lock-php$(PHP_MAJOR_MINOR)
	$(call run_opentelemetry_tests)

test_opentracing_10: global_test_run_dependencies tests/OpenTracer1Unit/composer.lock-php$(PHP_MAJOR_MINOR) tests/Frameworks/Custom/OpenTracing/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests,tests/OpenTracer1Unit)
	$(call run_tests,tests/OpenTracing)

test_integrations: $(TEST_INTEGRATIONS_$(PHP_MAJOR_MINOR))
test_web: $(TEST_WEB_$(PHP_MAJOR_MINOR))

test_web_coverage:
	PHPUNIT_COVERAGE=1 $(MAKE) test_web
test_integrations_coverage:
	PHPUNIT_COVERAGE=1 $(MAKE) test_integrations

test_integrations_amqp2: global_test_run_dependencies tests/Integrations/AMQP/V2/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/AMQP/V2)
test_integrations_amqp_latest: global_test_run_dependencies tests/Integrations/AMQP/Latest/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/AMQP/Latest)
test_integrations_deferred_loading: global_test_run_dependencies tests/Integrations/DeferredLoading/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/DeferredLoading)
test_integrations_filesystem: global_test_run_dependencies
	$(call run_tests_debug,tests/Integrations/Filesystem)
test_integrations_curl: global_test_run_dependencies
	$(call run_tests_debug,tests/Integrations/Curl)
test_integrations_elasticsearch1: global_test_run_dependencies tests/Integrations/Elasticsearch/V1/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Elasticsearch/V1)
test_integrations_elasticsearch7: global_test_run_dependencies tests/Integrations/Elasticsearch/V7/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Elasticsearch/V7)
test_integrations_elasticsearch8: global_test_run_dependencies tests/Integrations/Elasticsearch/V8/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Elasticsearch/V8)
test_integrations_elasticsearch_latest: global_test_run_dependencies tests/Integrations/Elasticsearch/Latest/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Elasticsearch/Latest)
test_integrations_guzzle5: global_test_run_dependencies tests/Integrations/Guzzle/V5/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Guzzle/V5)
test_integrations_guzzle6: global_test_run_dependencies  tests/Integrations/Guzzle/V6/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Guzzle/V6)
test_integrations_guzzle_latest: global_test_run_dependencies tests/Integrations/Guzzle/Latest/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Guzzle/Latest)
test_integrations_kafka: global_test_run_dependencies
	$(call run_tests_debug,tests/Integrations/Kafka)
test_integrations_laminaslog2: global_test_run_dependencies tests/Integrations/Logs/LaminasLogV2/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Logs/LaminasLogV2)
test_integrations_memcached: global_test_run_dependencies
	$(call run_tests_debug,tests/Integrations/Memcached)
test_integrations_memcache: global_test_run_dependencies
	$(call run_tests_debug,tests/Integrations/Memcache)
test_integrations_monolog1: global_test_run_dependencies tests/Integrations/Logs/MonologV1/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Logs/MonologV1)
test_integrations_monolog2: global_test_run_dependencies tests/Integrations/Logs/MonologV2/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Logs/MonologV2)
test_integrations_monolog_latest: global_test_run_dependencies tests/Integrations/Logs/MonologLatest/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Logs/MonologLatest)
test_integrations_mysqli: global_test_run_dependencies
	$(call run_tests_debug,tests/Integrations/Mysqli)
test_integrations_mongo: global_test_run_dependencies
	$(call run_tests_debug,tests/Integrations/Mongo)
test_integrations_mongodb_1x: global_test_run_dependencies tests/Integrations/MongoDB/V1_x/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/MongoDB/V1_x)
test_integrations_mongodb_latest: global_test_run_dependencies tests/Integrations/MongoDB/Latest/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/MongoDB/Latest)
test_integrations_openai_latest: global_test_run_dependencies tests/Integrations/OpenAI/Latest/composer.lock-php$(PHP_MAJOR_MINOR)
	$(eval TELEMETRY_ENABLED=1)
	$(call run_tests_debug,tests/Integrations/OpenAI/Latest)
 	$(eval TELEMETRY_ENABLED=0)
test_integrations_pcntl: global_test_run_dependencies
	$(call run_tests_debug,tests/Integrations/PCNTL)
test_integrations_pdo: global_test_run_dependencies
	$(call run_tests_debug,tests/Integrations/PDO)
test_integrations_phpredis3: global_test_run_dependencies
	$(eval TEST_EXTRA_INI=-d extension=redis-3.1.6.so)
	$(call run_tests_debug,tests/Integrations/PHPRedis/V3)
	$(eval TEST_EXTRA_INI=)
test_integrations_phpredis4: global_test_run_dependencies
	$(eval TEST_EXTRA_INI=-d extension=redis-4.3.0.so)
	$(call run_tests_debug,tests/Integrations/PHPRedis/V4)
	$(eval TEST_EXTRA_INI=)
test_integrations_phpredis5: global_test_run_dependencies
	$(eval TEST_EXTRA_ENV=DD_IGNORE_ARGINFO_ZPP_CHECK=1)
	$(eval TEST_EXTRA_INI=-d extension=redis-5.3.7.so)
	$(call run_tests_debug,tests/Integrations/PHPRedis/V5)
	$(eval TEST_EXTRA_INI=)
	$(eval TEST_EXTRA_ENV=)
test_integrations_predis_1: global_test_run_dependencies tests/Integrations/Predis/V1/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Predis/V1)
test_integrations_predis_2: global_test_run_dependencies tests/Integrations/Predis/V2/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Predis/V2)
test_integrations_predis_latest: global_test_run_dependencies tests/Integrations/Predis/Latest/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Predis/Latest)
test_integrations_frankenphp: global_test_run_dependencies
	$(call run_tests_debug,--testsuite=frankenphp-test)
test_integrations_roadrunner: global_test_run_dependencies tests/Frameworks/Roadrunner/Version_2/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Roadrunner/V2)
test_integrations_ratchet: global_test_run_dependencies tests/Integrations/Ratchet/V0_4/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Ratchet/V0_4)
test_integrations_googlespanner_latest: global_test_run_dependencies tests/Integrations/GoogleSpanner/Latest/composer.lock-php$(PHP_MAJOR_MINOR)
	$(eval TEST_EXTRA_ENV=ZEND_DONT_UNLOAD_MODULES=1)
	$(eval TEST_EXTRA_INI=-d extension=grpc.so)
	$(call run_tests_debug,tests/Integrations/GoogleSpanner/Latest)
	$(eval TEST_EXTRA_INI=)
	$(eval TEST_EXTRA_ENV=)
test_integrations_sqlsrv: global_test_run_dependencies
	$(eval TEST_EXTRA_INI=-d extension=sqlsrv.so)
	$(call run_tests_debug,tests/Integrations/SQLSRV)
	$(eval TEST_EXTRA_INI=)
test_integrations_swoole_5: global_test_run_dependencies
	$(call run_tests_debug,--testsuite=swoole-test)
test_web_apigw: global_test_run_dependencies tests/Frameworks/Laravel/Latest/composer.lock-php$(PHP_MAJOR_MINOR) tests/Frameworks/Laravel/Octane/Latest/composer.lock-php$(PHP_MAJOR_MINOR) tests/Frameworks/Roadrunner/Version_2/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,--testsuite=api-gateway-test)
test_web_cakephp_28: global_test_run_dependencies tests/Frameworks/CakePHP/Version_2_8/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,--testsuite=cakephp-28-test)
test_web_cakephp_310: global_test_run_dependencies tests/Frameworks/CakePHP/Version_3_10/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,--testsuite=cakephp-310-test)
test_web_cakephp_45: global_test_run_dependencies tests/Frameworks/CakePHP/Version_4_5/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,--testsuite=cakephp-45-test)
test_web_cakephp_latest: global_test_run_dependencies tests/Frameworks/CakePHP/Latest/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,--testsuite=cakephp-latest-test)
test_web_codeigniter_22: global_test_run_dependencies
	$(call run_tests_debug,--testsuite=codeigniter-22-test)
test_web_codeigniter_31: global_test_run_dependencies tests/Frameworks/CodeIgniter/Version_3_1/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,--testsuite=codeigniter-31-test)
test_web_drupal_89: global_test_run_dependencies tests/Frameworks/Drupal/Version_8_9/core/composer.lock-php tests/Frameworks/Drupal/Version_8_9/composer.lock-php
	$(call run_tests_debug,tests/Integrations/Drupal/V8_9)
test_web_drupal_95: global_test_run_dependencies tests/Frameworks/Drupal/Version_9_5/core/composer.lock-php tests/Frameworks/Drupal/Version_9_5/composer.lock-php
	$(call run_tests_debug,tests/Integrations/Drupal/V9_5)
test_web_drupal_101: global_test_run_dependencies tests/Frameworks/Drupal/Version_10_1/core/composer.lock-php tests/Frameworks/Drupal/Version_10_1/composer.lock-php
	$(call run_tests_debug,tests/Integrations/Drupal/V10_1)
test_web_laminas_rest_latest: global_test_run_dependencies tests/Frameworks/Laminas/ApiTools/Latest/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Laminas/ApiTools/Latest)
test_web_laminas_mvc_33: global_test_run_dependencies tests/Frameworks/Laminas/Mvc/Version_3_3/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Laminas/Mvc/V3_3)
test_web_laminas_mvc_latest: global_test_run_dependencies tests/Frameworks/Laminas/Mvc/Latest/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Laminas/Mvc/Latest)
test_web_laravel_42: global_test_run_dependencies tests/Frameworks/Laravel/Version_4_2/composer.lock-php$(PHP_MAJOR_MINOR)
	php tests/Frameworks/Laravel/Version_4_2/artisan optimize
	$(call run_tests_debug,tests/Integrations/Laravel/V4)
test_web_laravel_57: global_test_run_dependencies tests/Frameworks/Laravel/Version_5_7/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Laravel/V5_7)
test_web_laravel_58: global_test_run_dependencies tests/Frameworks/Laravel/Version_5_8/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,--testsuite=laravel-58-test)
test_web_laravel_8x: global_test_run_dependencies tests/Frameworks/Laravel/Version_8_x/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,--testsuite=laravel-8x-test)
test_web_laravel_9x: global_test_run_dependencies tests/Frameworks/Laravel/Version_9_x/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,--testsuite=laravel-9x-test)
test_web_laravel_10x: global_test_run_dependencies tests/Frameworks/Laravel/Version_10_x/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,--testsuite=laravel-10x-test)
test_web_laravel_11x: global_test_run_dependencies tests/Frameworks/Laravel/Version_11_x/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,--testsuite=laravel-11x-test)
test_web_laravel_latest: global_test_run_dependencies tests/Frameworks/Laravel/Latest/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,--testsuite=laravel-latest-test)
test_web_laravel_octane_latest: global_test_run_dependencies tests/Frameworks/Laravel/Octane/Latest/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,--testsuite=laravel-octane-latest-test)
test_web_lumen_52: global_test_run_dependencies tests/Frameworks/Lumen/Version_5_2/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Lumen/V5_2)
test_web_lumen_56: global_test_run_dependencies tests/Frameworks/Lumen/Version_5_6/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Lumen/V5_6)
test_web_lumen_58: global_test_run_dependencies tests/Frameworks/Lumen/Version_5_8/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Lumen/V5_8)
test_web_lumen_81: global_test_run_dependencies tests/Frameworks/Lumen/Version_8_1/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Lumen/V8_1)
test_web_lumen_90: global_test_run_dependencies tests/Frameworks/Lumen/Version_9_0/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Lumen/V9_0)
test_web_lumen_100: global_test_run_dependencies tests/Frameworks/Lumen/Version_10_0/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Lumen/V10_0)
test_web_slim_312: global_test_run_dependencies tests/Frameworks/Slim/Version_3_12/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,--testsuite=slim-312-test)
test_web_slim_48: global_test_run_dependencies tests/Frameworks/Slim/Version_4_8/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,--testsuite=slim-48-test)
test_web_slim_latest: global_test_run_dependencies tests/Frameworks/Slim/Latest/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,--testsuite=slim-latest-test)
test_web_symfony_23: global_test_run_dependencies tests/Frameworks/Symfony/Version_2_3/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Symfony/V2_3)
test_web_symfony_28: global_test_run_dependencies tests/Frameworks/Symfony/Version_2_8/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Symfony/V2_8)
test_web_symfony_30: global_test_run_dependencies tests/Frameworks/Symfony/Version_3_0/composer.lock-php$(PHP_MAJOR_MINOR)
	php tests/Frameworks/Symfony/Version_3_0/bin/console cache:clear --no-warmup --env=prod
	$(call run_tests_debug,tests/Integrations/Symfony/V3_0)
test_web_symfony_33: global_test_run_dependencies tests/Frameworks/Symfony/Version_3_3/composer.lock-php$(PHP_MAJOR_MINOR)
	php tests/Frameworks/Symfony/Version_3_3/bin/console cache:clear --no-warmup --env=prod
	$(call run_tests_debug,tests/Integrations/Symfony/V3_3)
test_web_symfony_34: global_test_run_dependencies tests/Frameworks/Symfony/Version_3_4/composer.lock-php$(PHP_MAJOR_MINOR)
	php tests/Frameworks/Symfony/Version_3_4/bin/console cache:clear --no-warmup --env=prod
	$(call run_tests_debug,tests/Integrations/Symfony/V3_4)
test_web_symfony_40: global_test_run_dependencies
	# We hit broken updates in this unmaintained version, so we committed a
	# working composer.lock and we composer install instead of composer update
	$(COMPOSER) --working-dir=tests/Frameworks/Symfony/Version_4_0 install --no-dev
	php tests/Frameworks/Symfony/Version_4_0/bin/console cache:clear --no-warmup --env=prod
	$(call run_tests_debug,tests/Integrations/Symfony/V4_0)
test_web_symfony_42: global_test_run_dependencies tests/Frameworks/Symfony/Version_4_2/composer.lock-php$(PHP_MAJOR_MINOR)
	php tests/Frameworks/Symfony/Version_4_2/bin/console cache:clear --no-warmup --env=prod
	$(call run_tests_debug,tests/Integrations/Symfony/V4_2)
test_web_symfony_44: global_test_run_dependencies tests/Frameworks/Symfony/Version_4_4/composer.lock-php$(PHP_MAJOR_MINOR)
	php tests/Frameworks/Symfony/Version_4_4/bin/console cache:clear --no-warmup --env=prod
	$(call run_tests_debug,--testsuite=symfony-44-test)
test_web_symfony_50: global_test_run_dependencies
	$(COMPOSER) --working-dir=tests/Frameworks/Symfony/Version_5_0 install # EOL; install from lock
	php tests/Frameworks/Symfony/Version_5_0/bin/console cache:clear --no-warmup --env=prod
	$(call run_tests_debug,tests/Integrations/Symfony/V5_0)
test_web_symfony_51: global_test_run_dependencies tests/Frameworks/Symfony/Version_5_1/composer.lock-php$(PHP_MAJOR_MINOR)
	php tests/Frameworks/Symfony/Version_5_1/bin/console cache:clear --no-warmup --env=prod
	$(call run_tests_debug,tests/Integrations/Symfony/V5_1)
test_web_symfony_52: global_test_run_dependencies tests/Frameworks/Symfony/Version_5_2/composer.lock-php$(PHP_MAJOR_MINOR)
	php tests/Frameworks/Symfony/Version_5_2/bin/console cache:clear --no-warmup --env=prod
	$(call run_tests_debug,--testsuite=symfony-52-test)
test_web_symfony_62: global_test_run_dependencies tests/Frameworks/Symfony/Version_6_2/composer.lock-php$(PHP_MAJOR_MINOR)
	php tests/Frameworks/Symfony/Version_6_2/bin/console cache:clear --no-warmup --env=prod
	$(call run_tests_debug,--testsuite=symfony-62-test)
test_web_symfony_latest: global_test_run_dependencies tests/Frameworks/Symfony/Latest/composer.lock-php$(PHP_MAJOR_MINOR)
	php tests/Frameworks/Symfony/Latest/bin/console cache:clear --no-warmup --env=prod
	$(call run_tests_debug,--testsuite=symfony-latest-test)
test_web_wordpress_48: global_test_run_dependencies
	$(call run_tests_debug,tests/Integrations/WordPress/V4_8)
test_web_wordpress_55: global_test_run_dependencies
	$(call run_tests_debug,tests/Integrations/WordPress/V5_5)
test_web_wordpress_59: global_test_run_dependencies
	$(call run_tests_debug,tests/Integrations/WordPress/V5_9)
test_web_wordpress_61: global_test_run_dependencies
	$(call run_tests_debug,tests/Integrations/WordPress/V6_1)
test_web_yii_2049: global_test_run_dependencies tests/Frameworks/Yii/Version_2_0_49/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Yii/V2_0_49)
test_web_yii_latest: global_test_run_dependencies tests/Frameworks/Yii/Latest/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Yii/Latest)
test_web_magento_23: global_test_run_dependencies tests/Frameworks/Magento/Version_2_3/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Magento/V2_3)
test_web_magento_24: global_test_run_dependencies tests/Frameworks/Magento/Version_2_4/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Magento/V2_4)
test_web_nette_24: global_test_run_dependencies tests/Frameworks/Nette/Version_2_4/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Nette/V2_4)
test_web_nette_31: global_test_run_dependencies tests/Frameworks/Nette/Version_3_1/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Nette/V3_1)
test_web_nette_latest: global_test_run_dependencies tests/Frameworks/Nette/Latest/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,tests/Integrations/Nette/Latest)
test_web_zend_1: global_test_run_dependencies
	$(call run_tests_debug,tests/Integrations/ZendFramework/V1)
test_web_zend_1_21: global_test_run_dependencies
	$(call run_tests_debug,tests/Integrations/ZendFramework/V1_21)
test_web_custom: global_test_run_dependencies tests/Frameworks/Custom/Version_Autoloaded/composer.lock-php$(PHP_MAJOR_MINOR)
	$(call run_tests_debug,--testsuite=custom-framework-autoloading-test)

tests/Frameworks/Drupal/%/composer.lock-php: tests/Frameworks/Drupal/%/composer.json
	$(call run_composer_with_retry,tests/Frameworks/Drupal/$*,--ignore-platform-reqs --no-dev)
	touch tests/Frameworks/Drupal/$(*)/composer.lock-php

tests/%/composer.lock-php$(PHP_MAJOR_MINOR): tests/%/composer.json
	$(call run_composer_with_lock,tests/$(*))

merge_coverage_reports:
	php -d memory_limit=-1 $(PHPCOV) merge --clover reports/coverage.xml reports/cov

### Api tests ###
API_TESTS_ROOT := ./tests/api

test_api_unit: composer.lock global_test_run_dependencies
	$(ENV_OVERRIDE) php $(TRACER_SOURCES_INI) vendor/bin/phpunit --config=phpunit.xml $(API_TESTS_ROOT)/Unit $(TESTS)

# Just test it does not crash, i.e. the exit code
test_internal_api_randomized: $(SO_FILE)
	$(if $(ASAN), DD_TRACE_DEBUG=1 USE_ZEND_ALLOC=0 USE_TRACKED_ALLOC=1 LSAN_OPTIONS=fast_unwind_on_malloc=0$${LSAN_OPTIONS:+$(,)$${LSAN_OPTIONS}}) php -n -ddatadog.trace.cli_enabled=1 -d extension=$(SO_FILE) tests/internal-api-stress-test.php 2> >(grep -A 1000 ==============)

composer.lock: composer.json
	$(call run_composer_with_retry,,)

.PHONY: dev dist_clean clean cores all clang_format_check clang_format_fix install sudo_install test_c test_c_mem test_extension_ci test_zai test_zai_asan test install_ini install_all \
	.apk .rpm .deb .tar.gz sudo debug prod strict run-tests.php verify_pecl_file_definitions verify_package_xml cbindgen cbindgen_binary
