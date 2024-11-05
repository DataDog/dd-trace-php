FROM debian:latest AS toolchain

ARG LLVM_VERSION=16.0.6
ARG ARCH

COPY Toolchain.cmake /build/Toolchain.cmake
COPY CHECKSUMS /CHECKSUMS

RUN echo "Building LLVM ${LLVM_VERSION} on ${ARCH}"

RUN apt-get update && apt-get install -y \
  build-essential wget cmake binutils lld libncurses5-dev git patchelf xz-utils curl lsb-release wget software-properties-common gnupg

RUN wget https://apt.llvm.org/llvm.sh && \
  chmod +x llvm.sh && \
  ./llvm.sh 16 all

RUN ln -s /usr/bin/cmake /usr/local/bin/cmake
