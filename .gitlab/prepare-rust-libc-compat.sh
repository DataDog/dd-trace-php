#!/usr/bin/env bash
set -euo pipefail

project_dir="${CI_PROJECT_DIR:-$(pwd)}"
cargo_home="${CARGO_HOME:-${HOME}/.cargo}"

if [ "$(uname -m)" != aarch64 ]; then
    exit 0
fi

libc_version_from_lock() {
    awk '
    $0 == "name = \"libc\"" { in_libc = 1; next }
    in_libc && $1 == "version" && $3 ~ /^\"0\.2\./ {
        gsub(/\"/, "", $3)
        print $3
        exit
    }
    /^\[\[package\]\]/ { in_libc = 0 }
    ' "$1"
}

patch_layout() {
    local layout_file="$1"

    python3 - "${layout_file}" <<'PY'
from pathlib import Path
import sys

path = Path(sys.argv[1])
source = path.read_text()
old = """    pub struct pthread_attr_t {
        __size: [u64; 7],
    }
"""
new = """    pub struct pthread_attr_t {
        #[cfg(target_arch = \"aarch64\")]
        __size: [u64; 8],
        #[cfg(not(target_arch = \"aarch64\"))]
        __size: [u64; 7],
    }
"""
if old in source:
    path.write_text(source.replace(old, new, 1))
elif new not in source:
    raise SystemExit(f"unexpected pthread_attr_t definition in {path}")
PY
}

prepare_libc_version() {
    local libc_version="$1"
    local libc_source
    local libc_compat
    local layout_file

    libc_compat="${project_dir}/tmp/rust-libc-glibc-compat-${libc_version}"
    if [ -d "${libc_compat}" ]; then
        layout_file="${libc_compat}/src/unix/linux_like/linux/musl/b64/mod.rs"
        patch_layout "${layout_file}"
        printf '%s\t%s\n' "${libc_version}" "${libc_compat}"
        return
    fi

    (cd / && cargo info "libc@${libc_version}" >/dev/null)
    libc_source="$(find "${cargo_home}/registry/src" -mindepth 2 -maxdepth 2 \
        -type d -name "libc-${libc_version}" -print -quit)"
    if [ -z "${libc_source}" ]; then
        echo "Cargo did not fetch libc ${libc_version}" >&2
        exit 1
    fi

    cp -a "${libc_source}" "${libc_compat}"

    layout_file="${libc_compat}/src/unix/linux_like/linux/musl/b64/mod.rs"
    patch_layout "${layout_file}"
    printf '%s\t%s\n' "${libc_version}" "${libc_compat}"
}

project_libc_version="$(libc_version_from_lock "${project_dir}/Cargo.lock")"
rust_src_lock="$(rustc --print sysroot)/lib/rustlib/src/rust/library/Cargo.lock"
stdlib_libc_version="$(libc_version_from_lock "${rust_src_lock}")"

if [ -z "${project_libc_version}" ] || [ -z "${stdlib_libc_version}" ]; then
    echo "Unable to find the Rust libc versions in Cargo.lock files" >&2
    exit 1
fi

prepare_libc_version "${project_libc_version}"
if [ "${stdlib_libc_version}" != "${project_libc_version}" ]; then
    prepare_libc_version "${stdlib_libc_version}"
fi
