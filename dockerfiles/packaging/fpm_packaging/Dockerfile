FROM cimg/ruby:3.3.4 AS base

USER root
WORKDIR /home/circleci

RUN apt-get update && apt-get -y install vim less build-essential rpm lintian jq binutils-aarch64-linux-gnu

# Need at least tar 1.35 to work around concurrency problems with atime and inode count checks
# See also: https://stackoverflow.com/a/77765876
FROM base as compile-tar
RUN curl -LO https://ftpmirror.gnu.org/gnu/tar/tar-1.35.tar.gz
RUN tar xfz tar-1.35.tar.gz
RUN (cd tar-1.35 && FORCE_UNSAFE_CONFIGURE=1 ./configure --prefix=/usr && make install)

FROM base as final
ADD fpm_apk_pax_header.patch /tmp
ADD fpm_apk_alpine323.patch /tmp
RUN sudo gem install fpm -v 1.17.0
RUN (cd /usr/local/lib/ruby/gems/3.3.0/gems/fpm-1.17.0 && \
     patch -p1 < /tmp/fpm_apk_pax_header.patch && \
     patch -p0 < /tmp/fpm_apk_alpine323.patch) && \
    rm -f /tmp/fpm_apk_pax_header.patch /tmp/fpm_apk_alpine323.patch

COPY --from=compile-tar /usr/bin/tar /usr/bin/tar

USER circleci
ENTRYPOINT [ "bash" ]
