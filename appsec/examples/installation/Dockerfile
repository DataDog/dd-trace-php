FROM php:8.5-apache

# Place downloaded artifacts in packages/ before building.
#
# Standard install (USE_SSI=false, the default):
#   ../../helper-rust/scripts/check-ci-jobs.py --download datadog-setup --output-dir packages/
#   ../../helper-rust/scripts/check-ci-jobs.py --download extension-aarch64-gnu --output-dir packages/
#
# SSI install (USE_SSI=true):
#   ../../helper-rust/scripts/check-ci-jobs.py --download ssi-aarch64 --output-dir packages/

ARG USE_SSI=false

COPY packages/ /tmp/dd-packages/

# Standard install: use datadog-setup.php with the pre-built extension package if available.
# SSI install: extract the SSI package and configure the universal dd_library_loader Zend
# extension, which finds and loads ddtrace/appsec/profiling at runtime without modifying ini.
RUN if [ "${USE_SSI}" = "true" ]; then \
        ssi_pkg=$(ls /tmp/dd-packages/dd-library-php-ssi-*-linux.tar.gz 2>/dev/null | head -1) && \
        mkdir -p /opt/datadog && \
        tar xzf "$ssi_pkg" -C /opt/datadog && \
        ext_dir=$(php -r "echo ini_get('extension_dir');") && \
        cp /opt/datadog/dd-library-php-ssi/linux-gnu/loader/dd_library_loader.so "$ext_dir/" && \
        echo "zend_extension=dd_library_loader.so" > /usr/local/etc/php/conf.d/99-dd-ssi.ini; \
    else \
        pkg=$(ls /tmp/dd-packages/dd-library-php-*-linux-gnu.tar.gz 2>/dev/null | \
              grep -Ev '\-(debug|zts|[0-9]{8})(\.tar\.gz$|\.)' | head -1) && \
        if [ -n "$pkg" ]; then \
            php /tmp/dd-packages/datadog-setup.php --php-bin=all --file="$pkg"; \
        else \
            php /tmp/dd-packages/datadog-setup.php --php-bin=all; \
        fi; \
    fi

COPY www/ /var/www/html/
