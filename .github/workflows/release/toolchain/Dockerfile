FROM debian@sha256:08db48d59c0a91afb802ebafc921be3154e200c452e4d0b19634b426b03e0e25 AS toolchain
RUN apt-get update && \
	apt-get install -y curl xz-utils make file cmake lld clang git patchelf gcc libgcc-s1 sed autoconf libcurl4-openssl-dev
RUN ln -s /bin/sed /usr/bin/sed
RUN mkdir /build
ADD . /build/
RUN cd /build && make install && make clean
