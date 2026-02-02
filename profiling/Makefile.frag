DD_PROFILING_NAME = datadog-profiling
DD_PROFILING_MODULE = modules/$(DD_PROFILING_NAME).$(SHLIB_DL_SUFFIX_NAME)
DD_PROFILING_MODULE_LA = modules/$(DD_PROFILING_NAME).la

DD_PROFILING_CARGO_TARGET_DIR = $(top_builddir)/target

DD_PROFILING_UNAME_S := $(shell uname -s)
ifeq ($(DD_PROFILING_UNAME_S),Darwin)
DD_PROFILING_RUST_EXT = dylib
else
DD_PROFILING_RUST_EXT = so
endif

DD_PROFILING_RUST_LIB = $(DD_PROFILING_CARGO_TARGET_DIR)/$(DD_PROFILING_CARGO_PROFILE)/libdatadog_php_profiling.$(DD_PROFILING_RUST_EXT)
DD_PROFILING_CARGO_FEATURES_CLEAN = $(shell echo "$(DD_PROFILING_CARGO_FEATURES)" | tr ',' ' ')

.PHONY: cargo-test clean distclean clean-profiling distclean-profiling

PHP_MODULES += $(DD_PROFILING_MODULE_LA)
all: $(DD_PROFILING_MODULE_LA)

$(DD_PROFILING_RUST_LIB): $(top_builddir)/Makefile $(top_builddir)/config.h
	@echo "Building Rust profiler ($(DD_PROFILING_CARGO_PROFILE))"
	@CARGO_TARGET_DIR="$(DD_PROFILING_CARGO_TARGET_DIR)" \
		PHP_INCLUDES="$(INCLUDES)" \
		PHP_INCLUDE_DIR="$(phpincludedir)" \
		PHP_PREFIX="$(prefix)" \
		PHP_VERSION_ID="$(DATADOG_PHP_VERSION_ID)" \
		$(DD_PROFILING_CARGO) build --manifest-path "$(top_srcdir)/Cargo.toml" $(DD_PROFILING_CARGO_ARGS) $(if $(DD_PROFILING_CARGO_FEATURES_CLEAN),--features "$(DD_PROFILING_CARGO_FEATURES_CLEAN)",)

$(DD_PROFILING_MODULE): $(DD_PROFILING_RUST_LIB)
	@mkdir -p modules
	@cp -f "$(DD_PROFILING_RUST_LIB)" "$@"

$(DD_PROFILING_MODULE_LA): $(DD_PROFILING_MODULE)
	@printf "dlname='%s'\n" "$(DD_PROFILING_NAME).$(SHLIB_DL_SUFFIX_NAME)" > "$@"

cargo-test:
	@CARGO_TARGET_DIR="$(DD_PROFILING_CARGO_TARGET_DIR)" \
		PHP_INCLUDES="$(INCLUDES)" \
		PHP_INCLUDE_DIR="$(phpincludedir)" \
		PHP_PREFIX="$(prefix)" \
		PHP_VERSION_ID="$(DATADOG_PHP_VERSION_ID)" \
		$(DD_PROFILING_CARGO) test --manifest-path "$(top_srcdir)/Cargo.toml" $(if $(DD_PROFILING_CARGO_FEATURES_CLEAN),--features "$(DD_PROFILING_CARGO_FEATURES_CLEAN)",)

clean: clean-profiling

clean-profiling:
	@rm -f "$(DD_PROFILING_MODULE)"
	@# Only cleans the profiler crate; dependencies remain cached.
	@$(DD_PROFILING_CARGO) clean -p datadog-php-profiling

distclean: distclean-profiling

distclean-profiling:
	@rm -rf "$(DD_PROFILING_CARGO_TARGET_DIR)"
	@rm -rf build autom4te.cache modules
	@rm -f configure configure.ac config.h config.h.in config.nice run-tests.php
	@rm -f *~ *.orig
