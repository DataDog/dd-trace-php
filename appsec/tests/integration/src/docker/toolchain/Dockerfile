FROM debian@sha256:e11072c1614c08bf88b543fcfe09d75a0426d90896408e926454e88078274fcb AS toolchain

ARG LLVM_VERSION=16
ARG ARCH

COPY . /build

RUN echo "Building LLVM ${LLVM_VERSION} on ${ARCH}"

RUN apt-get update -y && \
  apt-get install -y git wget lsb-release software-properties-common gnupg curl xz-utils make file lld patchelf gcc libgcc-s1 sed autoconf libssl-dev libxml2

RUN wget "https://github.com/Kitware/CMake/releases/download/v3.28.0/cmake-3.28.0-linux-$(arch | sed s/arm/aarch/).tar.gz" && \
  grep -F "cmake-3.28.0-linux-$(arch | sed s/arm/aarch/).tar.gz" ./build/CHECKSUMS | sha512sum --check && \
  tar --strip-components=1 -C /usr/local -xvzf "cmake-3.28.0-linux-$(arch | sed s/arm/aarch/).tar.gz" && \
  rm "cmake-3.28.0-linux-$(arch | sed s/arm/aarch/).tar.gz"

RUN wget https://apt.llvm.org/llvm.sh && \
  chmod +x llvm.sh && \
  ./llvm.sh ${LLVM_VERSION} all
