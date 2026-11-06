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
RUN sudo gem install fpm -v 1.17.0
RUN (cd /usr/local/lib/ruby/gems/4.0.0/gems/fpm-1.17.0 && \
     patch -p1 < /tmp/fpm_apk_pax_header.patch && \
     patch -p0 < /tmp/fpm_apk_alpine323.patch) && \
    rm -f /tmp/fpm_apk_pax_header.patch /tmp/fpm_apk_alpine323.patch

COPY --from=compile-tar /usr/bin/tar /usr/bin/tar


# Pin the internal client independently of the parent image's Docker packages.
ARG BUILDX_VERSION=0.38.1-dd.1
ARG BUILDX_SHA256_amd64=3a7b0c660da5bdb9df799d5ce339bdfceeafc98b2fa7f56cf2cccbca4691a112
ARG BUILDX_SHA256_arm64=9d47a29f85bfa7b4998a4969bcb8c51102ecea1f1db19217df7b2b68977e029a
RUN set -eu; \
    buildx_arch="$(dpkg --print-architecture)"; \
    case "$buildx_arch" in \
      amd64) buildx_sha256="$BUILDX_SHA256_amd64" ;; \
      arm64) buildx_sha256="$BUILDX_SHA256_arm64" ;; \
      *) echo "Unsupported Buildx architecture: $buildx_arch" >&2; exit 1 ;; \
    esac; \
    buildx_tmp="$(mktemp -d)"; \
    trap 'rm -rf "$buildx_tmp"' EXIT; \
    curl -fsSL -o "$buildx_tmp/buildx.tar.gz" \
      "https://depot-read-api-github-releases.us1.ddbuild.io/internal/mirror/github-releases/DataDog/buildx/releases/download/v${BUILDX_VERSION}/buildx_${BUILDX_VERSION}_linux_${buildx_arch}.tar.gz"; \
    printf '%s  %s\n' "$buildx_sha256" "$buildx_tmp/buildx.tar.gz" | sha256sum -c -; \
    tar -xzf "$buildx_tmp/buildx.tar.gz" -C "$buildx_tmp" buildx; \
    install -D -m 0755 "$buildx_tmp/buildx" /usr/local/libexec/docker/cli-plugins/docker-buildx; \
    buildx_selected="$(docker buildx version)"; \
    case "$buildx_selected" in \
      "github.com/docker/buildx v${BUILDX_VERSION} "*) printf '%s\n' "$buildx_selected" ;; \
      *) echo "Unexpected Buildx client: $buildx_selected" >&2; exit 1 ;; \
    esac

USER circleci
RUN buildx_selected="$(docker buildx version)" \
    && case "$buildx_selected" in \
      "github.com/docker/buildx v${BUILDX_VERSION} "*) printf '%s\n' "$buildx_selected" ;; \
      *) echo "Unexpected Buildx client for circleci: $buildx_selected" >&2; exit 1 ;; \
    esac
ENTRYPOINT [ "bash" ]
