ARG BUILD_BASE
FROM $BUILD_BASE AS base

ARG phpVersion
ENV PHP_VERSION=${phpVersion}
ENV LSAN_OPTIONS="suppressions=/home/circleci/suppr.txt,print_suppressions=0"

COPY php-8.4/suppr.txt /home/circleci/suppr.txt

FROM --platform=$BUILDPLATFORM $BUILD_BASE AS src
ARG phpTarGzUrl
ARG phpSha256Hash
RUN set -eux; \
    curl -fsSL -o /tmp/php.tar.gz "${phpTarGzUrl}"; \
    (echo "${phpSha256Hash} /tmp/php.tar.gz" | sha256sum -c -); \
    tar xf /tmp/php.tar.gz -C "${PHP_SRC_DIR}" --strip-components=1; \
    rm -f /tmp/php.tar.gz; \
    cd ${PHP_SRC_DIR}; \
    # We need to remove the dl() calls from run-tests as some extensions don't properly switch interned string storage for INIs. \
    sed -i 's/\bdl(/(bool)(/' run-tests.php; \
    ./buildconf --force;

FROM --platform=$BUILDPLATFORM $BUILD_BASE AS build
ARG phpVersion
ENV PHP_VERSION=${phpVersion}
ENV PHP_INSTALL_DIR=${PHP_INSTALL_DIR}
ARG TARGETPLATFORM
ARG BUILDPLATFORM
# install cross-compiling packages
RUN if [ "$BUILDPLATFORM" != "$TARGETPLATFORM" ]; then \
        sudo dpkg --add-architecture ${TARGETPLATFORM#linux/}; \
        sudo apt update; \
        sudo apt-get install -y --no-install-recommends valgrind:${TARGETPLATFORM#linux/} $(echo $DEVLIBS | sed "s/\(\s\|$\)/:${TARGETPLATFORM#linux/}\1/g"); \
    fi
COPY build-php.sh /home/circleci
COPY --chown=circleci:circleci --from=src $PHP_SRC_DIR $PHP_SRC_DIR

FROM build AS php-debug
ARG TARGETPLATFORM
RUN /home/circleci/build-php.sh $TARGETPLATFORM $PHP_INSTALL_DIR debug $PHP_VERSION
COPY build-extensions.sh /home/circleci
RUN /home/circleci/build-extensions.sh

FROM build AS php-nts
ARG TARGETPLATFORM
RUN /home/circleci/build-php.sh $TARGETPLATFORM $PHP_INSTALL_DIR nts $PHP_VERSION
COPY build-extensions.sh /home/circleci
RUN /home/circleci/build-extensions.sh

FROM build AS php-zts
ARG TARGETPLATFORM
RUN /home/circleci/build-php.sh $TARGETPLATFORM $PHP_INSTALL_DIR zts $PHP_VERSION
COPY build-extensions.sh /home/circleci
RUN /home/circleci/build-extensions.sh

FROM build AS php-debug-zts-asan
ARG TARGETPLATFORM
RUN /home/circleci/build-php.sh $TARGETPLATFORM $PHP_INSTALL_DIR debug-zts-asan $PHP_VERSION \
    && mv /tmp/build-php/sapi/cli/php /tmp/build-php/sapi/cli/php-asan
# for reasons the asan build just ... crashes in qemu (or executed via bytecode interpreter). The binary itself however perfectly works on the target platform
# just put a working php binary there to continue installation and be happy, then copy back later.
COPY --chown=circleci:circleci --from=php-debug $PHP_INSTALL_DIR/debug/bin/php /tmp/build-php/sapi/cli/php
RUN set -eux; ln -s $PHP_INSTALL_DIR/debug-zts-asan $PHP_INSTALL_DIR/debug; cd /tmp/build-php; touch sapi/cli/php; make install; switch-php debug-zts-asan;
COPY build-extensions.sh /home/circleci
RUN /home/circleci/build-extensions.sh
RUN cp /tmp/build-php/sapi/cli/php-asan $PHP_INSTALL_DIR/debug-zts-asan/bin/php

FROM build AS php-nts-asan
  ARG TARGETPLATFORM
  RUN /home/circleci/build-php.sh $TARGETPLATFORM $PHP_INSTALL_DIR nts-asan $PHP_VERSION \
    && mv /tmp/build-php/sapi/cli/php /tmp/build-php/sapi/cli/php-asan
  # For reasons the asan build just ... crashes in qemu (or executed via
  # bytecode interpreter). The binary itself however perfectly works on the
  # target platform. Put a working php binary there to continue installation
  # and be happy, then copy back later.
  COPY --chown=circleci:circleci --from=php-debug $PHP_INSTALL_DIR/debug/bin/php /tmp/build-php/sapi/cli/php
  RUN set -eux; \
    ln -s $PHP_INSTALL_DIR/nts-asan $PHP_INSTALL_DIR/debug; \
    cd /tmp/build-php; \
    touch sapi/cli/php; \
    make install; \
    switch-php nts-asan;
  COPY build-extensions.sh /home/circleci
  RUN /home/circleci/build-extensions.sh
  RUN mv /tmp/build-php/sapi/cli/php-asan $PHP_INSTALL_DIR/nts-asan/bin/php

FROM build AS php-release-nts
ARG TARGETPLATFORM
RUN /home/circleci/build-php.sh $TARGETPLATFORM $PHP_INSTALL_DIR release-nts $PHP_VERSION
COPY build-extensions.sh /home/circleci
RUN /home/circleci/build-extensions.sh

FROM build AS php-release-zts
ARG TARGETPLATFORM
RUN /home/circleci/build-php.sh $TARGETPLATFORM $PHP_INSTALL_DIR release-zts $PHP_VERSION
COPY build-extensions.sh /home/circleci
RUN /home/circleci/build-extensions.sh

FROM base AS final
COPY --chown=circleci:circleci --from=src $PHP_SRC_DIR $PHP_SRC_DIR
COPY --chown=circleci:circleci --from=php-debug-zts-asan $PHP_INSTALL_DIR/debug-zts-asan $PHP_INSTALL_DIR/debug-zts-asan
COPY --chown=circleci:circleci --from=php-nts-asan $PHP_INSTALL_DIR/nts-asan $PHP_INSTALL_DIR/nts-asan
COPY --chown=circleci:circleci --from=php-debug $PHP_INSTALL_DIR/debug $PHP_INSTALL_DIR/debug
COPY --chown=circleci:circleci --from=php-nts $PHP_INSTALL_DIR/nts $PHP_INSTALL_DIR/nts
COPY --chown=circleci:circleci --from=php-zts $PHP_INSTALL_DIR/zts $PHP_INSTALL_DIR/zts
COPY --chown=circleci:circleci --from=php-release-nts $PHP_INSTALL_DIR/release-nts $PHP_INSTALL_DIR/release-nts
COPY --chown=circleci:circleci --from=php-release-zts $PHP_INSTALL_DIR/release-zts $PHP_INSTALL_DIR/release-zts

USER root

RUN set -eux; \
    # Enable the apache config \
    echo "LoadModule php_module /usr/lib/apache2/modules/libphp.so" | tee /etc/apache2/mods-enabled/php.load; \
    # Set the default PHP version
    switch-php debug;

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY welcome /etc/motd
RUN sudo sed -i "s/VERSIONS/$(ls $PHP_INSTALL_DIR | xargs)/" /etc/motd

USER circleci

CMD ["php-fpm", "-F"]
