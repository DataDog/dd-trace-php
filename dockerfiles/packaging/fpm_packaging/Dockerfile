FROM cimg/ruby:4.0.8 AS base

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
ADD fpm_apk_tar_headers.patch /tmp
ADD fpm_apk_ustar.patch /tmp
RUN sudo gem install fpm -v 1.17.0
RUN (cd /usr/local/lib/ruby/gems/4.0.0/gems/fpm-1.17.0 && \
     patch -p1 < /tmp/fpm_apk_pax_header.patch && \
     patch -p0 < /tmp/fpm_apk_alpine323.patch && \
     patch -p1 < /tmp/fpm_apk_tar_headers.patch && \
     patch -p1 < /tmp/fpm_apk_ustar.patch) && \
    rm -f /tmp/fpm_apk_pax_header.patch /tmp/fpm_apk_alpine323.patch /tmp/fpm_apk_tar_headers.patch /tmp/fpm_apk_ustar.patch

COPY --from=compile-tar /usr/bin/tar /usr/bin/tar

# Follow the published internal client without introducing a Buildx version pin.
COPY --from=registry.ddbuild.io/images/docker:27.3.1 /usr/local/libexec/docker/cli-plugins/docker-buildx /usr/local/libexec/docker/cli-plugins/docker-buildx
RUN selected_buildx="$(docker buildx version)" \
    && case "$selected_buildx" in \
      "github.com/docker/buildx v"*-dd.*" "*) printf '%s\n' "$selected_buildx" ;; \
      *) echo "Expected internal Buildx: $selected_buildx" >&2; exit 1 ;; \
    esac

USER circleci
RUN selected_buildx="$(docker buildx version)" \
    && case "$selected_buildx" in \
      "github.com/docker/buildx v"*-dd.*" "*) printf '%s\n' "$selected_buildx" ;; \
      *) echo "Expected internal Buildx: $selected_buildx" >&2; exit 1 ;; \
    esac
ENTRYPOINT [ "bash" ]
