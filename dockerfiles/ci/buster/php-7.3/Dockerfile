FROM --platform=$BUILDPLATFORM datadog/dd-trace-ci:buster AS src
ARG phpTarGzUrl
ARG phpSha256Hash
RUN set -eux; \
    curl -fsSL -o /tmp/php.tar.gz "${phpTarGzUrl}"; \
    (echo "${phpSha256Hash} /tmp/php.tar.gz" | sha256sum -c -); \
    tar xf /tmp/php.tar.gz -C "${PHP_SRC_DIR}" --strip-components=1; \
    rm -f /tmp/php.tar.gz; \
    cd ${PHP_SRC_DIR}; \
    ./buildconf --force;

FROM --platform=$BUILDPLATFORM datadog/dd-trace-ci:buster AS build
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

FROM build AS php-debug-zts
ARG TARGETPLATFORM
RUN /home/circleci/build-php.sh $TARGETPLATFORM $PHP_INSTALL_DIR debug-zts $PHP_VERSION
COPY build-extensions.sh /home/circleci
RUN /home/circleci/build-extensions.sh

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

FROM datadog/dd-trace-ci:buster AS final
COPY --chown=circleci:circleci --from=src $PHP_SRC_DIR $PHP_SRC_DIR
COPY --chown=circleci:circleci --from=php-debug-zts $PHP_INSTALL_DIR/debug-zts $PHP_INSTALL_DIR/debug-zts
COPY --chown=circleci:circleci --from=php-debug $PHP_INSTALL_DIR/debug $PHP_INSTALL_DIR/debug
COPY --chown=circleci:circleci --from=php-nts $PHP_INSTALL_DIR/nts $PHP_INSTALL_DIR/nts

USER root

RUN set -eux; \
    # Enable the apache config \
    echo "LoadModule php7_module /usr/lib/apache2/modules/libphp.so" | tee /etc/apache2/mods-enabled/php.load; \
    # Set the default PHP version
    switch-php debug;

# Install Composer
COPY --from=composer:2.2 /usr/bin/composer /usr/local/bin/composer

COPY welcome /etc/motd
RUN sudo sed -i "s/VERSIONS/$(ls $PHP_INSTALL_DIR | xargs)/" /etc/motd

USER circleci

CMD ["php-fpm", "-F"]
