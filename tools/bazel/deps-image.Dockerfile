FROM registry.ddbuild.io/images/bazel:dynamic-22.04

COPY --chmod=0755 bazel /opt/dd-php-bazel/bazel
COPY repository/ /opt/dd-php-bazel/repository/
COPY manifest.json /opt/dd-php-bazel/manifest.json

ENV BAZEL_BINARY=/opt/dd-php-bazel/bazel \
    BAZEL_REPOSITORY_CACHE=/opt/dd-php-bazel/repository
