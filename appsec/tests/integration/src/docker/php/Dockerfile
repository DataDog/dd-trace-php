FROM datadog/dd-appsec-php-ci:php-deps

RUN apt-get update && apt-get install -y \
	pkg-config \
	libxml2-dev \
	zlib1g-dev \
	libzip-dev \
	libcurl4-gnutls-dev \
	libgmp-dev \
	libonig-dev \
	libpq-dev \
	apache2-dev \
	libsqlite3-dev \
  gdb \
  rust-gdb \
  vim \
  && rm -rf /var/lib/apt/lists/*
ADD build_dev_php.sh /build/php/
ADD php_patches/ /build/php/php_patches/
RUN mkdir -p /etc/php && ln -s /root/php/php.ini /etc/php/
ARG PHP_VERSION
ARG VARIANT

ENV NO_APX_WRAPPER=1

# PHP 8.4 checks if /usr/sbin/apache2 exists
RUN touch /usr/sbin/apache2
RUN chmod +x /usr/sbin/apache2
RUN USER=root /build/php/build_dev_php.sh "$PHP_VERSION" "$VARIANT"
RUN rm /usr/sbin/apache2

ADD php.ini /root/php/php.ini
ENV PHPRC=/root/php/php.ini

RUN chmod +x /root

ENV PATH=/root/php/$PHP_VERSION-$VARIANT/bin/:/root/php/$PHP_VERSION-$VARIANT/sbin/:$PATH
ENV HUNTER_ROOT=/root/.hunter
ENV CARGO_HOME=/root/.cargo
ENV RUSTUP_HOME=/root/.rustup
ENV LD_LIBRARY_PATH=/root/php/icu-60/lib

RUN groupadd -g 1000 linux_users && \
    useradd -G linux_users -u 1000 linux_user

ENTRYPOINT ["php"]
