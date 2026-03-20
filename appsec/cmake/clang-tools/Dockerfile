# Minimal Docker image with clang-format and clang-tidy built from LLVM source
# Uses static linking for smallest possible image size
# Based on Alpine Linux 3.21

FROM alpine:3.21 AS builder

RUN apk add --no-cache \
    build-base \
    cmake \
    ninja \
    python3 \
    git \
    linux-headers \
    wget \
    clang \
    clang-dev

# Download and extract LLVM source
ARG LLVM_VERSION=17.0.6
WORKDIR /src
RUN wget -q https://github.com/llvm/llvm-project/releases/download/llvmorg-${LLVM_VERSION}/llvm-project-${LLVM_VERSION}.src.tar.xz && \
    tar -xf llvm-project-${LLVM_VERSION}.src.tar.xz && \
    mv llvm-project-${LLVM_VERSION}.src llvm-project && \
    rm llvm-project-${LLVM_VERSION}.src.tar.xz

# Configure LLVM build with minimal size optimizations
WORKDIR /src/llvm-project/build
RUN cmake -G Ninja ../llvm \
    -DCMAKE_BUILD_TYPE=MinSizeRel \
    -DCMAKE_INSTALL_PREFIX=/usr/local \
    -DCMAKE_C_COMPILER=clang \
    -DCMAKE_CXX_COMPILER=clang++ \
    -DCMAKE_CXX_STANDARD=17 \
    -DLLVM_ENABLE_PROJECTS="clang;clang-tools-extra" \
    -DLLVM_TARGETS_TO_BUILD="" \
    -DLLVM_INCLUDE_TESTS=OFF \
    -DLLVM_INCLUDE_EXAMPLES=OFF \
    -DLLVM_INCLUDE_BENCHMARKS=OFF \
    -DLLVM_INCLUDE_DOCS=OFF \
    -DLLVM_ENABLE_BINDINGS=OFF \
    -DLLVM_ENABLE_OCAMLDOC=OFF \
    -DLLVM_ENABLE_Z3_SOLVER=OFF \
    -DLLVM_ENABLE_LIBXML2=OFF \
    -DLLVM_ENABLE_ZLIB=OFF \
    -DLLVM_ENABLE_ZSTD=OFF \
    -DLLVM_ENABLE_TERMINFO=OFF \
    -DLLVM_BUILD_STATIC=ON \
    -DLLVM_LINK_LLVM_DYLIB=OFF \
    -DLLVM_BUILD_LLVM_DYLIB=OFF \
    -DBUILD_SHARED_LIBS=OFF \
    -DLLVM_STATIC_LINK_CXX_STDLIB=ON \
    -DCMAKE_EXE_LINKER_FLAGS="-static" \
    -DCLANG_ENABLE_STATIC_ANALYZER=OFF \
    -DCLANG_ENABLE_ARCMT=OFF \
    -DCLANG_BUILD_EXAMPLES=OFF

# Build only the required tools
RUN ninja clang-format clang-tidy clang-apply-replacements

# Install binaries
RUN ninja install-clang-format install-clang-tidy install-clang-apply-replacements install-clang-resource-headers

# Copy run-clang-tidy helper script
RUN cp /src/llvm-project/clang-tools-extra/clang-tidy/tool/run-clang-tidy.py /usr/local/bin/run-clang-tidy && \
    chmod +x /usr/local/bin/run-clang-tidy

# Strip binaries to reduce size
RUN strip /usr/local/bin/clang-format \
    /usr/local/bin/clang-tidy \
    /usr/local/bin/clang-apply-replacements

# Final minimal runtime image
FROM alpine:3.21

# Install only Python runtime for run-clang-tidy script
RUN apk add --no-cache python3

# Copy static binaries from builder
COPY --from=builder /usr/local/bin/clang-format /usr/local/bin/
COPY --from=builder /usr/local/bin/clang-tidy /usr/local/bin/
COPY --from=builder /usr/local/bin/clang-apply-replacements /usr/local/bin/
COPY --from=builder /usr/local/bin/run-clang-tidy /usr/local/bin/

# Copy clang resource headers so clang-tidy uses its own headers
COPY --from=builder /usr/local/lib/clang/ /usr/local/lib/clang/

# Verify installations
RUN clang-format --version && \
    clang-tidy --version && \
    run-clang-tidy --help > /dev/null

WORKDIR /workspace

CMD ["/bin/sh"]
