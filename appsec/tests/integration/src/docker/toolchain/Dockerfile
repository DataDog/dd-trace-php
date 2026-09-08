ARG DOCKER_REGISTRY
FROM ${DOCKER_REGISTRY}/library/debian@sha256:6ebd97fa83deb272194a2cf015b3d26a4d538e9ad3a7a79d544c8af5b0a01443 AS toolchain

RUN apt-get update && apt-get install -y \
  binutils \
  build-essential \
  curl \
  git \
  patchelf \
  wget \
  xz-utils \
  && rm -rf /var/lib/apt/lists/*

COPY . /build/

RUN wget https://github.com/Kitware/CMake/releases/download/v3.28.0/cmake-3.28.0-linux-$(arch | sed s/arm/aarch/).tar.gz && \
  grep -F "cmake-3.28.0-linux-$(arch | sed s/arm/aarch/).tar.gz" ./build/CHECKSUMS | sha512sum --check && \
  tar --strip-components=1 -C /usr/local -xvzf cmake-3.28.0-linux-$(arch | sed s/arm/aarch/).tar.gz && \
  rm cmake-3.28.0-linux-$(arch | sed s/arm/aarch/).tar.gz
