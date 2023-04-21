FROM --platform=linux/amd64 registry.ddbuild.io/images/benchmarking-platform-tools-ubuntu:latest

ARG RUST_VERSION=1.64

# Install sirun to run performance tests
RUN curl -o sirun.tar.gz -L https://github.com/DataDog/sirun/releases/download/v0.1.10/sirun-v0.1.10-x86_64-unknown-linux-musl.tar.gz \
    && tar -xzf sirun.tar.gz \
    && rm sirun.tar.gz \
    && mv sirun /usr/bin/sirun

RUN apt-get update \
    && apt-get install --no-install-recommends -y \
    valgrind git hwinfo jq libclang-dev php-cli php-dev \
    && curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --default-toolchain ${RUST_VERSION} --profile minimal

ENV DD_PROFILING_OUTPUT_PPROF=/tmp/foo.pprof

WORKDIR /app
