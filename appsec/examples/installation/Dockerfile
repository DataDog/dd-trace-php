FROM php:8.5-apache

# Place downloaded artifacts in packages/ before building:
#   ../../helper-rust/scripts/check-ci-jobs.py --download datadog-setup --output-dir packages/
#   ../../helper-rust/scripts/check-ci-jobs.py --download extension-aarch64-gnu --output-dir packages/  # optional, for offline install

COPY packages/ /tmp/dd-packages/

# Use the main (non-debug, non-zts, non-dated) extension package if present, else download.
RUN pkg=$(ls /tmp/dd-packages/dd-library-php-*-linux-gnu.tar.gz 2>/dev/null | \
          grep -Ev '\-(debug|zts|[0-9]{8})(\.tar\.gz$|\.)' | head -1) && \
    if [ -n "$pkg" ]; then \
        php /tmp/dd-packages/datadog-setup.php --php-bin=all --file="$pkg"; \
    else \
        php /tmp/dd-packages/datadog-setup.php --php-bin=all; \
    fi

COPY www/ /var/www/html/
