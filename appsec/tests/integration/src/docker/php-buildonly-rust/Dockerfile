FROM ghcr.io/cataphract/php-buildonly@sha256:148595c871b8a94073126ef463c0dd73e051345d31d905b7546056e8b8d37ccc

ENV CARGO_HOME=/root/.cargo \
    RUSTUP_HOME=/root/.rustup \
    PATH=/root/.cargo/bin:${PATH} \
    LIBCLANG_PATH=/usr/lib/llvm21/lib \
    LDFLAGS=-Wl,-Bdynamic

ARG CARGO_LLVM_COV_VERSION=0.9.0

# Debug SDKs are ordinary Zend debug builds. Sanitizer builds have their own
# test path and are not part of this portable build image.
RUN rm /etc/musl-clang.conf

RUN apk --no-cache add clang21-libclang curl curl-dev && \
    curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | \
        sh -s -- --default-toolchain 1.87.0 --profile minimal -y && \
    rustup component add rustfmt clippy llvm-tools-preview && \
    rust_toolchain="nightly-$(uname -m)-unknown-linux-musl" && \
    rustup toolchain install "$rust_toolchain" \
        --profile minimal --component rust-src && \
    architecture="$(uname -m)" && \
    case "$architecture" in \
        aarch64) cargo_llvm_cov_sha256=3c299780e109d59fd77044e64734421d43c0067b92937a49be302fee66d04727 ;; \
        x86_64) cargo_llvm_cov_sha256=bc2122fd7bd9b6d3eb371690ed085f82e5a8bf1851f4645ef521d12ed04b2616 ;; \
        *) echo "Unsupported architecture: $architecture" >&2; exit 1 ;; \
    esac && \
    cargo_llvm_cov_archive=/tmp/cargo-llvm-cov.tar.gz && \
    curl --proto '=https' --tlsv1.2 -fsSL \
        -o "$cargo_llvm_cov_archive" \
        "https://github.com/taiki-e/cargo-llvm-cov/releases/download/v${CARGO_LLVM_COV_VERSION}/cargo-llvm-cov-${architecture}-unknown-linux-musl.tar.gz" && \
    echo "$cargo_llvm_cov_sha256  $cargo_llvm_cov_archive" | \
        sha256sum -c - && \
    tar xzf "$cargo_llvm_cov_archive" -C "$CARGO_HOME/bin" && \
    rm "$cargo_llvm_cov_archive" && \
    cargo llvm-cov --version && \
    chmod -R a+rX /root /root/.cargo /root/.rustup

# php_sidecar_mockgen only reads the PHP binary's dynamic symbol table. Build
# small, non-runnable ELF files containing the symbols captured from each PHP
# configuration in the integration matrix.
COPY php-symbols.txt.gz /tmp/php-symbols.txt.gz
RUN gzip -dc /tmp/php-symbols.txt.gz > /tmp/php-symbols.txt && \
    awk '/^\[/ { \
        sdk = substr($0, 2, length($0) - 2); \
        output = "/tmp/php-symbols-" sdk ".c"; \
        next \
    } \
    NF { print "extern char *" $0 " = 0;" > output }' \
        /tmp/php-symbols.txt && \
    for source in /tmp/php-symbols-*.c; do \
        sdk=${source#/tmp/php-symbols-}; \
        sdk=${sdk%.c}; \
        "$CC" -shared -nostdlib -fPIC -fno-builtin \
            -Wno-extern-initializer -Wno-unused-command-line-argument \
            -x c "$source" \
            -o "/opt/php/$sdk/bin/php"; \
    done && \
    rm /tmp/php-symbols-*.c /tmp/php-symbols.txt \
        /tmp/php-symbols.txt.gz
