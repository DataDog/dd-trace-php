FROM datadog/dd-appsec-php-ci:toolchain

RUN apt-get update && apt-get install -y \
	pkg-config \
	libxml2-dev \
	libssl-dev \
	zlib1g-dev \
	libzip-dev \
	libcurl4-openssl-dev \
	libgmp-dev \
	libonig-dev \
	libpq-dev \
	apache2-dev \
	libicu-dev \
	libsqlite3-dev \
	&& rm -rf /var/lib/apt/lists/*
ADD build_dev_php.sh /build/php/
ADD php_patches/ /build/php/php_patches/
ARG PHP_VERSION
ARG VARIANT

ENV NO_APX_WRAPPER=1


RUN USER=root /build/php/build_dev_php.sh "$PHP_VERSION" "$VARIANT" && \
	rm -rf /root/php/sources

ENV PATH=/root/php/$PHP_VERSION-$VARIANT/bin/:/root/php/$PHP_VERSION-$VARIANT/sbin/:$PATH

ENTRYPOINT ["php"]
