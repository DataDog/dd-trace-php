ARG baseImage="datadog/dd-trace-ci:centos-6"
FROM ${baseImage} as base

# Newer protobuf
FROM base as protobuf
# RUN source scl_source enable devtoolset-7
ARG PROTOBUF_VERSION="3.19.4"
ARG PROTOBUF_SHA256="89ac31a93832e204db6d73b1e80f39f142d5747b290f17340adce5be5b122f94"
RUN source scl_source enable devtoolset-7 \
  && FILENAME=protobuf-cpp-${PROTOBUF_VERSION}.tar.gz \
  && curl -L -O "https://github.com/protocolbuffers/protobuf/releases/download/v${PROTOBUF_VERSION}/${FILENAME}" \
  && tar --no-same-owner -xf "$FILENAME" \
  && cd protobuf-${PROTOBUF_VERSION} \
  && ./configure \
    --prefix=/usr/local \
    --libdir=/usr/local/lib64 \
    --with-pic \
    --disable-shared \
    --enable-static \
  && make -j $(nproc) \
  && make install \
  && cd - \
  && rm -fr "$FILENAME" "${FILENAME%.tar.gz}"

FROM protobuf as protoc-c
ARG PROTOBUF_C_VERSION="1.4.0"
ARG PROTOBUF_C_SHA256="26d98ee9bf18a6eba0d3f855ddec31dbe857667d269bc0b6017335572f85bbcb"
RUN source scl_source enable devtoolset-7 \
  && FILENAME=protobuf-c-${PROTOBUF_C_VERSION}.tar.gz \
  && curl -L  -O "https://github.com/protobuf-c/protobuf-c/releases/download/v${PROTOBUF_C_VERSION}/${FILENAME}" \
  && tar --no-same-owner -xf "$FILENAME" \
  && cd ${FILENAME%.tar.gz} \
  && ./configure --with-pic --disable-shared --enable-static --prefix=/usr/local --libdir=/usr/local/lib64 \
  && make -j $(nproc) \
  && make install \
  && cd - \
  && rm -fr "$FILENAME" "${FILENAME%.tar.gz}"

# Caution, takes a very long time! Since we have to build one from source,
# I picked LLVM 14, which matches Rust 1.60.
# Ordinarily we leave sources, but LLVM is 2GiB just for the sources...
FROM base as clang
RUN source scl_source enable devtoolset-7 \
  && yum install -y rh-python36 \
  && source scl_source enable rh-python36 \
  && cd /usr/local/src \
  && git clone --depth 1 -b release/14.x https://github.com/llvm/llvm-project.git \
  && mkdir -vp llvm-project/build \
  && cd llvm-project/build \
  && cmake -DLLVM_ENABLE_PROJECTS=clang -DCMAKE_BUILD_TYPE=Release -DCMAKE_INSTALL_PREFIX=/usr/local ../llvm \
  && make -j 2 clang-libraries install-clang-libraries-stripped \
  && rm -fr /usr/local/lib/*.a \
  && cd - \
  && rm -fr llvm-project \
  && yum remove -y rh-python36 \
  && yum clean all

FROM clang as final
COPY --from=protoc-c /usr/local /usr/local

# rust sha256sum generated locally after verifying it with sha256
ARG RUST_VERSION="1.60.0"
ARG RUST_SHA256_ARM="99c419c2f35d4324446481c39402c7baecd7a8baed7edca9f8d6bbd33c05550c"
ARG RUST_SHA256_X86="b8a4c3959367d053825e31f90a5eb86418eb0d80cacda52bfa80b078e18150d5"
ENV CARGO_HOME=/rust/cargo
ENV RUSTUP_HOME=/rust/rustup
RUN source scl_source enable devtoolset-7 \
    && mkdir -p -v "${CARGO_HOME}" "${RUSTUP_HOME}" \
    && chown -R 777 "${CARGO_HOME}" "${RUSTUP_HOME}" \
    && MARCH=$(uname -m) \
    && if [[ $MARCH == "x86_64" ]]; then RUST_SHA256=${RUST_SHA256_X86};\
     elif [[ $MARCH == "aarch64" ]];then RUST_SHA256=${RUST_SHA256_ARM}; fi && \
    FILENAME=rust-${RUST_VERSION}-${MARCH}-unknown-linux-gnu.tar.gz && \
    curl -L --write-out '%{http_code}' -O https://static.rust-lang.org/dist/${FILENAME} && \
    printf '%s  %s' "$RUST_SHA256" "$FILENAME" | sha256sum --check --status && \
    tar -xf "$FILENAME" \
    && cd ${FILENAME%.tar.gz} \
    && ./install.sh --components="rustc,cargo,clippy-preview,rustfmt-preview,rust-std-${MARCH}-unknown-linux-gnu" \
    && cd - \
    && rm -fr "$FILENAME" "${FILENAME%.tar.gz}"

ENV PATH="/rust/cargo/bin:${PATH}"
