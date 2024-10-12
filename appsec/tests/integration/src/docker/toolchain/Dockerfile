FROM debian@sha256:08db48d59c0a91afb802ebafc921be3154e200c452e4d0b19634b426b03e0e25 AS toolchain
RUN apt-get update && \
	apt-get install -y curl xz-utils make file lld clang git patchelf gcc libgcc-s1 sed autoconf wget libssl-dev wget libxml2

RUN ln -s /bin/sed /usr/bin/sed
RUN mkdir /build
ADD . /build/

RUN wget https://github.com/Kitware/CMake/releases/download/v3.28.0/cmake-3.28.0-linux-$(arch | sed s/arm/aarch/).tar.gz && \
    grep -F "cmake-3.28.0-linux-$(arch | sed s/arm/aarch/).tar.gz" ./build/CHECKSUMS | sha512sum --check && \
    tar --strip-components=1 -C /usr/local -xvzf cmake-3.28.0-linux-$(arch | sed s/arm/aarch/).tar.gz && \
    rm cmake-3.28.0-linux-$(arch | sed s/arm/aarch/).tar.gz

RUN cd /build && make install && make clean
