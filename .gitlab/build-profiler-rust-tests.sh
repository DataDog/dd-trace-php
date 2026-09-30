#!/usr/bin/env bash
set -euo pipefail

output_root="$1"
project_dir="${CI_PROJECT_DIR:-$(pwd)}"
rust_target="$(uname -m)-unknown-linux-musl"
features="profiling,test,debug_stats,stack_walking_tests,tracing,tracing-subscriber,trigger_time_sample"

case "$(uname -m)" in
    aarch64)
        glibc_loader=/lib/ld-linux-aarch64.so.1
        ;;
    x86_64)
        glibc_loader=/lib64/ld-linux-x86-64.so.2
        ;;
    *)
        echo "Unsupported profiler test architecture: $(uname -m)" >&2
        exit 1
        ;;
esac

for thread_safety in nts zts; do
    cargo_patch_args=()
    if [ "$(uname -m)" = aarch64 ]; then
        libc_compat_sources="$(.gitlab/prepare-rust-libc-compat.sh)"
        while IFS=$'\t' read -r libc_version libc_compat; do
            patch_name="libc_compat_${libc_version//./_}"
            cargo_patch_args+=(
                --config "patch.crates-io.${patch_name}.package=\"libc\""
                --config "patch.crates-io.${patch_name}.path=\"${libc_compat}\""
            )
        done <<< "${libc_compat_sources}"
    fi

    if [ "${thread_safety}" = zts ]; then
        php_sdk_version="${PHP_VERSION}-release-zts"
    else
        php_sdk_version="${PHP_VERSION}"
    fi

    target_dir="${project_dir}/tmp/profiler-rust-tests-${thread_safety}"
    messages="${target_dir}/cargo-messages.json"
    output="${output_root}/${thread_safety}"
    mkdir -p "${target_dir}" "${output}"

    PHPRC='' \
      PATH="/opt/php/${php_sdk_version}/bin:${PATH}" \
      PHP_CONFIG="/opt/php/${php_sdk_version}/bin/php-config" \
      RUSTC_BOOTSTRAP=1 \
      RUSTFLAGS='-C target-feature=-crt-static -C linker=musl-clang -C link-arg=/usr/lib/libunwind.a -C force-unwind-tables=yes' \
      CARGO_TARGET_DIR="${target_dir}" \
      cargo \
        "${cargo_patch_args[@]}" \
        --config target-applies-to-host=false \
        --config 'host.rustflags=["-C", "target-feature=-crt-static"]' \
        -Zhost-config -Ztarget-applies-to-host -Zunstable-options \
        -Zbuild-std=std,panic_abort \
        -Zbuild-std-features=llvm-libunwind,backtrace \
        test --no-run --target "${rust_target}" \
        --no-default-features --features "${features}" \
        --message-format=json-render-diagnostics > "${messages}"

    python3 -c '
import json
import pathlib
import sys

messages = pathlib.Path(sys.argv[1])
for line in messages.read_text().splitlines():
    message = json.loads(line)
    executable = message.get("executable")
    if message.get("reason") == "compiler-artifact" and executable:
        print(executable)
' "${messages}" | while IFS= read -r executable; do
        name="$(basename "${executable}")"
        cp -v "${executable}" "${output}/${name}"
        patchelf --set-interpreter "${glibc_loader}" "${output}/${name}"
    done

    if ! find "${output}" -type f -perm -0100 -print -quit | grep -q .; then
        echo "Cargo did not produce a profiler test executable" >&2
        exit 1
    fi
done
