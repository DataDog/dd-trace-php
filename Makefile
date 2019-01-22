BUILD_SUFFIX := extension
BUILD_DIR := tmp/build_$(BUILD_SUFFIX)
SO_FILE := $(BUILD_DIR)/modules/ddtrace.so
WALL_FLAGS := -Wall -Wextra
CFLAGS := -O2 $(WALL_FLAGS)
VERSION:=$(shell cat src/DDTrace/Tracer.php | grep VERSION | awk '{print $$NF}' | cut -d\' -f2)

INI_FILE := /usr/local/etc/php/conf.d/ddtrace.ini

C_FILES := $(shell find src/ext -name '*.c' -o -name '*.h' | awk '{ printf "$(BUILD_DIR)/%s\n", $$1 }' )
TEST_FILES := $(shell find tests/ext -name '*.phpt' | awk '{ printf "$(BUILD_DIR)/%s\n", $$1 }' )

ALL_FILES := $(C_FILES) $(TEST_FILES) $(BUILD_DIR)/config.m4

$(BUILD_DIR)/%: %
	$(Q) echo Copying $* to build dir
	$(Q) mkdir -p $(dir $@)
	$(Q) cp -a $* $@

all: $(BUILD_DIR)/configure $(SO_FILE)
Q := @

src/ext/version.h:
	@echo "Creating [src/ext/version.h]\n"
	@echo "PHP: $(VERSION)"
	@(echo '#ifndef PHP_DDTRACE_VERSION\n#define PHP_DDTRACE_VERSION "$(VERSION)"\n#endif' ) > $@
	@echo "C: "
	@cat $@ #| grep '#define'

$(BUILD_DIR)/configure: $(BUILD_DIR)/config.m4
	$(Q) (cd $(BUILD_DIR); phpize)

$(BUILD_DIR)/Makefile: $(BUILD_DIR)/configure
	$(Q) (cd $(BUILD_DIR); ./configure)

$(SO_FILE): $(ALL_FILES) $(BUILD_DIR)/Makefile src/ext/version.h
	$(Q) $(MAKE) -C $(BUILD_DIR) CFLAGS="$(CFLAGS)"

install: $(SO_FILE)
	$(Q) $(SUDO) $(MAKE) -C $(BUILD_DIR) install

$(INI_FILE):
	$(Q) echo "extension=ddtrace.so" | $(SUDO) tee -a $@

install_ini: $(INI_FILE)

install_all: install install_ini

test_c: $(SO_FILE)
	$(MAKE) -C $(BUILD_DIR) test TESTS="-q --show-all $(TESTS)"

test_c_mem: $(SO_FILE)
	$(MAKE) -C $(BUILD_DIR) test TESTS="-q --show-all -m $(TESTS)"

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

.PHONY: dist_clean clean all install sudo_install test_c test_c_mem test test_integration install_ini install_all .apk .rpm .deb .tar.gz src/ext/version.h sudo debug run-tests.php
