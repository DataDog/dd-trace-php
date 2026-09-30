#!/usr/bin/env bash
set -euo pipefail

output_root="$(mkdir -p "$1" && cd "$1" && pwd)"
project_dir="${CI_PROJECT_DIR:-$(pwd)}"
builder="${project_dir}/.gitlab/build-profiler.sh"
rust_target="$(uname -m)-unknown-linux-musl"
git config --global --add safe.directory "${project_dir}"

if [ "$(uname -m)" != x86_64 ]; then
    echo "Profiler benchmarks are supported only on x86_64" >&2
    exit 1
fi

build_checkout()
{
    source_dir="$1"
    revision="$2"
    output="${output_root}/${revision}"
    target_dir="${source_dir}/tmp/profiler-benchmark"
    messages="${target_dir}/cargo-messages.json"
    benchmark_cflags="${CFLAGS:-} -O2 -falign-functions=64"
    mkdir -p "${output}/cargo" "${target_dir}"

    (
        cd "${source_dir}"
        CI_PROJECT_DIR="${source_dir}" \
          PHP_VERSION=8.2 \
          CFLAGS="${benchmark_cflags}" \
          "${builder}" "${output}/release" nts benchmark-release
        CI_PROJECT_DIR="${source_dir}" \
          PHP_VERSION=8.2 \
          CFLAGS="${benchmark_cflags}" \
          PROFILER_FEATURES=trigger_time_sample \
          "${builder}" "${output}/sampling" nts benchmark-sampling

        PHPRC='' \
          PATH="/opt/php/8.2/bin:${PATH}" \
          PHP_CONFIG=/opt/php/8.2/bin/php-config \
          RUSTC_BOOTSTRAP=1 \
          RUSTFLAGS='-C target-feature=-crt-static -C linker=musl-clang -C link-arg=/usr/lib/libunwind.a -C force-unwind-tables=yes' \
          CFLAGS="${benchmark_cflags}" \
          CARGO_TARGET_DIR="${target_dir}" \
          cargo \
            --config target-applies-to-host=false \
            --config 'host.rustflags=["-C", "target-feature=-crt-static"]' \
            -Zhost-config -Ztarget-applies-to-host -Zunstable-options \
            -Zbuild-std=std,panic_abort \
            -Zbuild-std-features=llvm-libunwind,backtrace \
            bench --no-run --target "${rust_target}" \
            --no-default-features \
            --features profiling,test,stack_walking_tests \
            --message-format=json-render-diagnostics > "${messages}"
    )

    benchmark_executable="$(python3 -c '
import json
import pathlib
import sys

for line in pathlib.Path(sys.argv[1]).read_text().splitlines():
    message = json.loads(line)
    target = message.get("target", {})
    if (message.get("reason") == "compiler-artifact"
            and target.get("name") == "stack_walking"
            and message.get("executable")):
        print(message["executable"])
' "${messages}")"

    if [ -z "${benchmark_executable}" ]; then
        echo "Cargo did not produce the stack_walking benchmark" >&2
        exit 1
    fi
    cp -v "${benchmark_executable}" "${output}/cargo/stack-walking"
    patchelf --set-interpreter /lib64/ld-linux-x86-64.so.2 \
        "${output}/cargo/stack-walking"
}

candidate_revision="$(git -C "${project_dir}" rev-parse HEAD)"
build_checkout "${project_dir}" "${candidate_revision}"

baseline_branch="${CI_MERGE_REQUEST_TARGET_BRANCH_NAME:-${CI_DEFAULT_BRANCH:-master}}"
if [ "$(git -C "${project_dir}" rev-parse --is-shallow-repository)" = true ]; then
    git -C "${project_dir}" fetch --unshallow origin "${baseline_branch}"
else
    git -C "${project_dir}" fetch origin "${baseline_branch}"
fi
baseline_revision="$(git -C "${project_dir}" merge-base \
    "${candidate_revision}" "origin/${baseline_branch}")"
printf '%s\n' "${baseline_revision}" > "${output_root}/baseline-revision"

if [ "${baseline_revision}" != "${candidate_revision}" ]; then
    baseline_dir="${project_dir}/tmp/profiler-benchmark-baseline"
    git -C "${project_dir}" fetch origin "${baseline_revision}"
    git -C "${project_dir}" worktree add --detach \
        "${baseline_dir}" "${baseline_revision}"
    git -C "${baseline_dir}" submodule update --init --recursive
    build_checkout "${baseline_dir}" "${baseline_revision}"
fi
