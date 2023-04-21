FROM --platform=linux/amd64 php:8.2-cli

ARG RUST_VERSION=1.64

# Install sirun to run performance tests
RUN curl -o sirun.tar.gz -L https://github.com/DataDog/sirun/releases/download/v0.1.10/sirun-v0.1.10-x86_64-unknown-linux-musl.tar.gz \
    && tar -xzf sirun.tar.gz \
	  && rm sirun.tar.gz \
  	&& mv sirun /usr/bin/sirun

RUN apt-get update \
    && apt-get install --no-install-recommends -y \
    valgrind git hwinfo jq libclang-dev \
    && curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --default-toolchain ${RUST_VERSION} --profile minimal

ENV DD_PROFILING_OUTPUT_PPROF=/tmp/foo.pprof
