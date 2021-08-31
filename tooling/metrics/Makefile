# Use this Makefile from within  a <MAJOR>.<MINOR>-buster development images.

TMP_FOLDER := /tmp/sirun
RESULTS_FILE := $(TMP_FOLDER)/results.txt
SIRUN_FILE := $(TMP_FOLDER)/sirun.yml
PHP_EXTENSION_VERSION := $(shell php -i | awk '/^PHP[ \t]+API[ \t]+=>/ { print $$NF }')

memory: run
	cat $(RESULTS_FILE) | jq '.name , .iterations[]."max.res.size"'

run: prerequisites fresh_tmp_folder download sirun_config
	@echo "Running iterations, this might take a few minutes"
	. $(HOME)/.cargo/env && sirun $(SIRUN_FILE) | tee $(RESULTS_FILE)

prerequisites:
	sudo apt -y update && sudo apt install -y jq
	curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y
	. $(HOME)/.cargo/env && cargo install sirun

fresh_tmp_folder:
	rm -rf $(TMP_FOLDER)
	mkdir -p $(TMP_FOLDER)

download:
# target
	curl -L --output "$(TMP_FOLDER)/target.tar.gz" "$(TARGET_URL)"
	mkdir -p "$(TMP_FOLDER)/target"
	tar -xf "$(TMP_FOLDER)/target.tar.gz" -C "$(TMP_FOLDER)/target"
# rererence
	curl -L --output "$(TMP_FOLDER)/reference.tar.gz" "$(REFERENCE_URL)"
	mkdir -p "$(TMP_FOLDER)/reference"
	tar -xf "$(TMP_FOLDER)/reference.tar.gz" -C "$(TMP_FOLDER)/reference"

sirun_config:
	cp sirun.template.yml $(SIRUN_FILE)
	sed -i 's|{{php-extension-version}}|$(PHP_EXTENSION_VERSION)|g' $(SIRUN_FILE)
