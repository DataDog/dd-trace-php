ARG DOCKER_REGISTRY
FROM ${DOCKER_REGISTRY}/library/debian@sha256:08db48d59c0a91afb802ebafc921be3154e200c452e4d0b19634b426b03e0e25 AS toolchain

RUN apt-get update && apt-get install -y \
  binutils \
  build-essential \
  curl \
  git \
  gnupg \
  libncurses5-dev \
  lld \
  lsb-release \
  patchelf \
  software-properties-common \
  wget \
  wget \
  xz-utils \
  && rm -rf /var/lib/apt/lists/*

RUN ln -s /bin/sed /usr/bin/sed
COPY . /build/

RUN wget https://github.com/Kitware/CMake/releases/download/v3.28.0/cmake-3.28.0-linux-$(arch | sed s/arm/aarch/).tar.gz && \
  grep -F "cmake-3.28.0-linux-$(arch | sed s/arm/aarch/).tar.gz" ./build/CHECKSUMS | sha512sum --check && \
  tar --strip-components=1 -C /usr/local -xvzf cmake-3.28.0-linux-$(arch | sed s/arm/aarch/).tar.gz && \
  rm cmake-3.28.0-linux-$(arch | sed s/arm/aarch/).tar.gz
