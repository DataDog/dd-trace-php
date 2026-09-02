FROM dunglas/frankenphp:1-php8.5

ARG INSTALL_DATADOG_PROFILING=0

# Place downloaded artifacts in packages/ before building.
#
#   uv run ../../helper-rust/scripts/check-ci-jobs.py --pipeline <pipeline-id> --download datadog-setup --output-dir packages/
#   uv run ../../helper-rust/scripts/check-ci-jobs.py --pipeline <pipeline-id> --download extension-aarch64-gnu --output-dir packages/
#   uv run ../../helper-rust/scripts/check-ci-jobs.py --pipeline <pipeline-id> --download extension-amd64-gnu --output-dir packages/

COPY packages/ /tmp/dd-packages/

RUN arch="$(uname -m)" && \
    case "$arch" in \
        aarch64|arm64) package_arch="aarch64" ;; \
        x86_64|amd64) package_arch="x86_64" ;; \
        *) echo "unsupported architecture: $arch" >&2; exit 1 ;; \
    esac && \
    api="$(php -i | sed -n 's/^PHP Extension Build => API\([0-9][0-9]*\),.*/\1/p')" && \
    zts="$(php -r 'echo PHP_ZTS ? "-zts" : "";')" && \
    pkg="$(ls /tmp/dd-packages/dd-library-php-*-"${package_arch}"-linux-gnu-"${api}${zts}".tar.gz 2>/dev/null | head -1)" && \
    test -n "$pkg" && \
    php /tmp/dd-packages/datadog-setup.php --php-bin=all --file="$pkg" && \
    if [ "$INSTALL_DATADOG_PROFILING" = "1" ]; then \
        profiler=/tmp/dd-packages/datadog-profiling-zts.so; \
        test -f "$profiler"; \
        extension_dir="$(php -r 'echo ini_get("extension_dir");')"; \
        install -m 0755 "$profiler" "$extension_dir/datadog-profiling.so"; \
        echo 'extension=datadog-profiling.so' > "$PHP_INI_DIR/conf.d/99-datadog-profiling.ini"; \
    fi

COPY Caddyfile /etc/frankenphp/Caddyfile
COPY www/ /app/public/
