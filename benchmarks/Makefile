PROFILING_DIR := "../profiling/"
EXTENSION := "$(PROFILING_DIR)target/release/libdatadog_php_profiling.so"
RUST_VERSION := $(shell grep "channel" ${PROFILING_DIR}rust-toolchain.toml | sed -E 's/.*"([^"]+)".*/\1/')

# Find all benchmarks
BENCH_FILES := $(wildcard *-bench.json)
# Define the corresponding result files
RESULT_FILES := $(patsubst %-bench.json,%-result.ndjson,$(BENCH_FILES))

all: result.ndjson

%-result.ndjson:%-bench.json $(EXTENSION)
	sirun $< 1>$@

result.ndjson: $(RESULT_FILES)
	cat $^ | sirun --summarize > $@

$(EXTENSION):
	cd $(PROFILING_DIR) && cargo build --release

docker:
	docker build --build-arg RUST_VERSION=${RUST_VERSION} -t dd-trace-php-bench .

clean:
	rm -f $(RESULT_FILES) result.json

.PHONY: docker clean
