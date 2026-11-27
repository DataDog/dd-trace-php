#!/bin/sh
# Usage: build-libcurl.sh <version> <sha256>
# Builds libcurl <version> (shared, TLS, HTTP/2) into /opt/libcurl/<version>
# and leaves only its lib/ there.
set -eux
version=$1
sha=$2
curl -fsSL -o "curl-$version.tar.xz" "https://curl.se/download/curl-$version.tar.xz"
echo "$sha  curl-$version.tar.xz" | sha256sum -c -
tar xf "curl-$version.tar.xz"
cd "curl-$version"
./configure --prefix="/opt/libcurl/$version" --with-openssl --with-nghttp2 \
    --enable-shared --disable-static --enable-versioned-symbols \
    --without-libpsl --without-brotli \
    --without-zstd --without-libidn2 --without-librtmp --without-libssh2 \
    --disable-ldap --disable-ldaps --disable-rtsp --disable-dict \
    --disable-telnet --disable-tftp --disable-pop3 --disable-imap \
    --disable-smb --disable-smtp --disable-gopher --disable-docs \
    --disable-manual
make -j"$(nproc)"
make install
cd ..
rm -rf "curl-$version" "curl-$version.tar.xz"
# only lib/ is needed at run time
cd "/opt/libcurl/$version"
rm -rf bin include share lib/pkgconfig lib/*.la
