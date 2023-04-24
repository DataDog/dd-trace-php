CANDIDATE_DIR ?= "../../profiling/"
EXTENSION := "$(CANDIDATE_DIR)target/release/libdatadog_php_profiling.so"
RUST_VERSION := $(shell grep "channel" ${CANDIDATE_DIR}rust-toolchain.toml | sed -E 's/.*"([^"]+)".*/\1/')

# Find all benchmarks
BENCH_FILES := $(wildcard *-bench.json)
# Define the corresponding results files
RESULT_FILES := $(patsubst %-bench.json,%-results.ndjson,$(BENCH_FILES))

all: results.ndjson

%-results.ndjson:%-bench.json $(EXTENSION)
	export CANDIDATE_DIR=$(CANDIATE_DIR) && sirun $< 1>$@

results.ndjson: $(RESULT_FILES)
	cat $^ > $@

$(EXTENSION):
	cd $(CANDIDATE_DIR) && cargo build --release

docker:
	docker build --build-arg RUST_VERSION=${RUST_VERSION} -t dd-trace-php-bench .

clean:
	rm -f $(RESULT_FILES) results.json

.PHONY: docker clean
