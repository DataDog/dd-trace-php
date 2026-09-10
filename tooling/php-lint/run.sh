#!/usr/bin/env bash
# CI entry point for the PHP lint placeholder job.
# See tooling/php-lint/README.md
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

LINT_DIR="$ROOT/tooling/php-lint"
PHPCS="$LINT_DIR/vendor/bin/phpcs"

if [[ ! -x "$PHPCS" ]]; then
    if ! command -v composer >/dev/null 2>&1; then
        echo "error: composer is required to install PHP_CodeSniffer" >&2
        exit 1
    fi
    echo "==> installing PHP_CodeSniffer (tooling/php-lint)"
    composer install --no-interaction --prefer-dist --working-dir="$LINT_DIR"
fi

status=0

echo "==> phpcs --standard=tooling/php-lint/phpcs.xml"
if ! "$PHPCS" -s --standard="$LINT_DIR/phpcs.xml"; then
    status=1
fi

echo "==> custom scripts (tooling/php-lint/scripts)"
shopt -s nullglob
found=0
for script in "$LINT_DIR/scripts"/*; do
    base="$(basename "$script")"
    case "$base" in
        README*|*.md|.gitkeep) continue ;;
    esac
    [[ -f "$script" ]] || continue
    found=1
    echo "--> $base"
    case "$script" in
        *.php)
            if ! php "$script"; then
                status=1
            fi
            ;;
        *.sh)
            if ! bash "$script"; then
                status=1
            fi
            ;;
        *)
            if [[ -x "$script" ]]; then
                if ! "$script"; then
                    status=1
                fi
            else
                echo "skipping non-executable $base (use .php or .sh)" >&2
            fi
            ;;
    esac
done

if [[ "$found" -eq 0 ]]; then
    echo "(no custom scripts)"
fi

if [[ "$status" -ne 0 ]]; then
    echo "PHP lint failed" >&2
fi
exit "$status"
