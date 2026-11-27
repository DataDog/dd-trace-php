# Precompiled libcurl builds for the appsec tests of downstream ext/curl
# requests (G10.3). Every CI PHP is linked against the distro's
# libcurl.so.4, so a leg or a developer run selects another libcurl with
#
#   LD_LIBRARY_PATH=/opt/libcurl/<version>/lib
#
# without rebuilding PHP. The distro's libssl3 and libnghttp2-14 are the ones
# of the dd-trace-ci bookworm images, so the SONAMEs resolve there.
#
# The builds carry versioned symbols (CURL_OPENSSL_4, as Debian's libcurl):
# php's references are versioned, and against an unversioned libcurl ld.so
# prints "no version information available" on the stderr of every php
# process, which run-tests merges into the output of each phpt (Q10.1).
#
# The build needs TLS (HTTPS through CONNECT) and libnghttp2-dev, or HTTP/2 is
# silently off. The final image keeps only each lib/ (about 0.5 MB each, a few MB in total).
ARG BOOKWORM_IMAGE=debian:bookworm-slim

FROM ${BOOKWORM_IMAGE} AS build
RUN apt-get update \
    && apt-get install -y --no-install-recommends build-essential ca-certificates \
        curl xz-utils libssl-dev libnghttp2-dev zlib1g-dev \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /build
COPY build-libcurl.sh /build/build-libcurl.sh
# <version> <sha256 of curl-<version>.tar.xz>: 7.79.1 is the pinned build below
# 7.80 (the floor is 7.62: PHP 8.x references curl_easy_upkeep and curl_mime_*)
RUN set -eux; \
    while read -r version sha; do \
        ./build-libcurl.sh "$version" "$sha"; \
    done <<'VERSIONS'
7.79.1 0606f74b1182ab732a17c11613cbbaf7084f2e6cca432642d0e3ad7c224c3689
7.80.0 a132bd93188b938771135ac7c1f3ac1d3ce507c1fcbef8c471397639214ae2ab
7.88.1 1dae31b2a7c1fe269de99c0c31bb488346aab3459b5ffca909d6938249ae415f
8.0.1 0a381cd82f4d00a9a334438b8ca239afea5bfefcfa9a1025f2bf118e79e0b5f0
8.1.0 6bd80ad4f07187015911216ee7185b90d285ac5162aed1bded144f9f93232a3c
8.5.0 42ab8db9e20d8290a3b633e7fbb3cec15db34df65fd1015ef8ac1e4723750eeb
8.18.0 40df79166e74aa20149365e11ee4c798a46ad57c34e4f68fd13100e2c9a91946
VERSIONS

FROM ${BOOKWORM_IMAGE}
# keep only the lib/ of each prefix
COPY --from=build /opt/libcurl /opt/libcurl
CMD ["ls", "/opt/libcurl"]
