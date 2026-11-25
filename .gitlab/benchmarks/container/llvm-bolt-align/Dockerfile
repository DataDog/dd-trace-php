# musl allows the tool to be copied into images with any C/C++ runtime.
FROM alpine:3.24 AS build

RUN apk add --no-cache \
    build-base ca-certificates cmake curl git linux-headers lld ninja python3

# Latest stable LLVM release on 2026-11-17. Keep version and checksum together.
ARG LLVM_VERSION=23.1.6
ARG LLVM_SOURCE_SHA256=66b37a51976c46ad86f49a2304c4dfd64be971f858846dfffaa8a32632474bb8
RUN curl -fL --retry 3 \
        "https://codeload.github.com/llvm/llvm-project/tar.gz/refs/tags/llvmorg-${LLVM_VERSION}" \
        -o /tmp/llvm.tar.gz \
    && echo "${LLVM_SOURCE_SHA256}  /tmp/llvm.tar.gz" | sha256sum -c - \
    && mkdir /src \
    && tar -xzf /tmp/llvm.tar.gz --strip-components=1 -C /src \
        "llvm-project-llvmorg-${LLVM_VERSION}/llvm" \
        "llvm-project-llvmorg-${LLVM_VERSION}/bolt" \
        "llvm-project-llvmorg-${LLVM_VERSION}/libc" \
        "llvm-project-llvmorg-${LLVM_VERSION}/cmake" \
        "llvm-project-llvmorg-${LLVM_VERSION}/third-party" \
    && rm /tmp/llvm.tar.gz

COPY bolt-align.patch /tmp/bolt-align.patch
RUN cd /src && git apply --check /tmp/bolt-align.patch && git apply /tmp/bolt-align.patch

# CI compiles on native BuildKit workers with 60 GiB (amd64) / 118 GiB (arm64).
# Override BUILD_JOBS for smaller local Linux build machines.
# Each platform image rewrites native binaries; instrumentation is not needed.
ARG BUILD_JOBS=16
RUN cmake -G Ninja -S /src/llvm -B /build \
        -DCMAKE_BUILD_TYPE=Release \
        -DCMAKE_EXE_LINKER_FLAGS=-static \
        -DLLVM_ENABLE_PROJECTS=bolt \
        -DLLVM_TARGETS_TO_BUILD=Native \
        -DBOLT_ENABLE_RUNTIME=OFF \
        -DLLVM_ENABLE_LLD=ON \
        -DLLVM_PARALLEL_LINK_JOBS=1 \
        -DLLVM_BUILD_TOOLS=OFF \
        -DLLVM_INCLUDE_UTILS=OFF \
        -DLLVM_INCLUDE_RUNTIMES=OFF \
        -DLLVM_INCLUDE_EXAMPLES=OFF \
        -DLLVM_INCLUDE_DOCS=OFF \
        -DLLVM_INCLUDE_TESTS=OFF \
        -DLLVM_INCLUDE_BENCHMARKS=OFF \
        -DLLVM_ENABLE_ASSERTIONS=OFF \
        -DLLVM_ENABLE_BINDINGS=OFF \
        -DLLVM_ENABLE_PLUGINS=OFF \
        -DLLVM_ENABLE_PIC=OFF \
        -DLLVM_ENABLE_TELEMETRY=OFF \
        -DLLVM_ENABLE_LIBPFM=OFF \
        -DLLVM_ENABLE_ZLIB=OFF \
        -DLLVM_ENABLE_ZSTD=OFF \
        -DLLVM_ENABLE_LIBXML2=OFF \
        -DLLVM_ENABLE_CURL=OFF \
        -DLLVM_ENABLE_LIBEDIT=OFF \
    && cmake --build /build --target llvm-bolt --parallel "${BUILD_JOBS}" \
    && readelf --program-headers /build/bin/llvm-bolt > /tmp/program-headers.txt \
    && ! grep -q INTERP /tmp/program-headers.txt \
    && readelf --dynamic /build/bin/llvm-bolt > /tmp/dynamic.txt \
    && ! grep -q NEEDED /tmp/dynamic.txt \
    && mkdir -p /out/usr/local/bin /out/tmp /out/work \
    && chmod 1777 /out/tmp \
    && strip -o /out/usr/local/bin/llvm-bolt /build/bin/llvm-bolt \
    && ln -s llvm-bolt /out/usr/local/bin/llvm-bolt-align

# Test against glibc binaries, independently of the musl build environment.
FROM ubuntu:24.04 AS test

RUN apt-get update \
    && apt-get install -y --no-install-recommends build-essential \
    && rm -rf /var/lib/apt/lists/*

COPY --from=build /out/ /out/
COPY smoke-test.sh /tmp/smoke-test.sh
# Copy only the executable under the public name to verify standalone use.
RUN cp /out/usr/local/bin/llvm-bolt /tmp/llvm-bolt-align \
    && sh /tmp/smoke-test.sh /tmp/llvm-bolt-align

FROM scratch

COPY --from=test /out/ /
RUN ["/usr/local/bin/llvm-bolt-align", "--help"]

WORKDIR /work
ENTRYPOINT ["/usr/local/bin/llvm-bolt-align"]
CMD ["--help"]
