FROM debian@sha256:08db48d59c0a91afb802ebafc921be3154e200c452e4d0b19634b426b03e0e25 AS toolchain
RUN apt-get update && \
	apt-get install -y curl xz-utils make file lld clang git patchelf gcc libgcc-s1 sed autoconf libcurl4-openssl-dev wget libssl-dev wget libxml2

RUN ln -s /bin/sed /usr/bin/sed
RUN mkdir /build
ADD . /build/

RUN wget https://github.com/Kitware/CMake/releases/download/v3.24.2/cmake-3.24.2.tar.gz && \
    grep -F "cmake-3.24.2.tar.gz" ./build/CHECKSUMS | sha512sum --check && \
    tar xvzf cmake-3.24.2.tar.gz && rm cmake-3.24.2.tar.gz && cd cmake-3.24.2 && \
    ./bootstrap && make -j $(nproc) && make install && make clean

RUN cd /build && make install && make clean
