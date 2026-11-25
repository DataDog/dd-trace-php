#!/usr/bin/env bash
# Collect core dumps into an artifact directory, generate GDB backtraces, and
# gzip the raw core files. Compatible with Bash 4.2+ (CentOS 7, Bookworm, Alpine).

set -uo pipefail

out_dir="${CI_PROJECT_DIR:-$PWD}/artifacts/core-dumps"
fallback_exe=""
tag=""
search_dirs=()

while [ "$#" -gt 0 ]; do
    case "$1" in
        --out-dir)
            out_dir="$2"
            shift 2
            ;;
        --search)
            search_dirs+=("$2")
            shift 2
            ;;
        --fallback-exe)
            fallback_exe="$2"
            shift 2
            ;;
        --tag)
            tag="$2"
            shift 2
            ;;
        *)
            echo "Unknown argument: $1" >&2
            exit 2
            ;;
    esac
done

mkdir -p "${out_dir}"
out_dir="$(cd "${out_dir}" && pwd)"

diag_name="core-diagnostics.txt"
if [ -n "${tag}" ]; then
    diag_name="${tag}-${diag_name}"
fi
diag_file="${out_dir}/${diag_name}"

core_pattern=""
if [ -r /proc/sys/kernel/core_pattern ]; then
    core_pattern="$(cat /proc/sys/kernel/core_pattern 2>/dev/null || true)"
fi

{
    echo "core_pattern: ${core_pattern:-<unreadable>}"
    echo "gdb: $(command -v gdb 2>/dev/null || echo '<missing>')"
    echo "file: $(command -v file 2>/dev/null || echo '<missing>')"
} | tee "${diag_file}"

case "${core_pattern}" in
    "|"*)
        echo "core_pattern pipes core dumps to a host handler; cannot collect inside container." | tee -a "${diag_file}"
        exit 0
        ;;
esac

pattern_base="$(basename "${core_pattern:-core}")"
prefix="${pattern_base%%%*}"
rel_prefix="${prefix:-core}"

# Build deduplicated list of (dir, name_glob) pairs to scan.
# For an absolute core_pattern dir (e.g. /var/crash), scan using the exact
# pattern prefix (even if empty). For relative search dirs, always require a
# non-empty prefix so we don't run file(1) on every file in the workspace.
declare -a raw_candidates=()

scan_dir() {
    local dir="$1"
    local glob="$2"
    local maxdepth_args=()
    if [ "${3:-}" != "" ]; then
        maxdepth_args=(-maxdepth "$3")
    fi
    [ -d "${dir}" ] || return 0
    while IFS= read -r -d '' candidate; do
        raw_candidates+=("${candidate}")
    done < <(find "${dir}" ${maxdepth_args[@]+"${maxdepth_args[@]}"} -path "${out_dir}" -prune -o -type f -name "${glob}" -print0 2>/dev/null)
}

if [[ "${core_pattern}" == /* ]]; then
    core_dir="$(dirname "${core_pattern}")"
    if [ -d "${core_dir}" ] && [ -w "${core_dir}" ]; then
        echo "core_dir: ${core_dir} (writable)" | tee -a "${diag_file}"
        scan_dir "${core_dir}" "${prefix}*" 2
    else
        echo "core_dir: ${core_dir} (missing or not writable by $(id -un))" | tee -a "${diag_file}"
    fi
fi

project_root="${CI_PROJECT_DIR:-$PWD}"
scan_dir "${project_root}" "${rel_prefix}*"
for extra_dir in ${search_dirs[@]+"${search_dirs[@]}"}; do
    scan_dir "${extra_dir}" "${rel_prefix}*"
done

# Deduplicate and filter to actual ELF core files.
declare -a cores=()
is_seen() {
    local needle="$1"
    local item
    for item in "${cores[@]+"${cores[@]}"}"; do
        if [ "${item}" = "${needle}" ]; then
            return 0
        fi
    done
    return 1
}

for candidate in "${raw_candidates[@]+"${raw_candidates[@]}"}"; do
    [ -f "${candidate}" ] || continue
    case "$(basename "${candidate}")" in
        *.bt.txt|*.gz|core_conftest_*) continue ;;
    esac
    if file -b "${candidate}" 2>/dev/null | grep -q 'core file'; then
        if ! is_seen "${candidate}"; then
            cores+=("${candidate}")
        fi
    fi
done

echo "core files found: ${#cores[@]}" | tee -a "${diag_file}"

if [ "${#cores[@]}" -eq 0 ]; then
    exit 0
fi

# Move all core files into out_dir first so a slow or failing gdb cannot stop
# the remaining cores from being uploaded as artifacts.
declare -a collected_cores=()
idx=0
for core in "${cores[@]}"; do
    base="$(basename "${core}")"
    if [ -n "${tag}" ]; then
        dest_base="${tag}-${base}"
    else
        dest_base="${base}"
    fi
    dest="${out_dir}/${dest_base}"
    while [ -e "${dest}" ] || [ -e "${dest}.gz" ]; do
        dest="${out_dir}/${dest_base}-${idx}"
        idx=$((idx + 1))
    done
    echo "core: ${core} -> ${dest}" | tee -a "${diag_file}"
    if mv "${core}" "${dest}" 2>/dev/null || cp "${core}" "${dest}" 2>/dev/null; then
        chmod a+r "${dest}" 2>/dev/null || true
        collected_cores+=("${dest}")
    else
        echo "WARNING: failed to copy/move ${core}" | tee -a "${diag_file}"
    fi
done

resolve_exe() {
    local core_file="$1"
    local exe=""

    if command -v gdb >/dev/null 2>&1; then
        exe="$(gdb -batch -ex "core-file ${core_file}" -ex "info auxv" 2>/dev/null \
            | sed -n 's/.*AT_EXECFN.*"\([^"]*\)".*/\1/p' | head -n 1)"
    fi

    if [ -n "${exe}" ] && [ ! -f "${exe}" ]; then
        exe="$(command -v "${exe}" 2>/dev/null || true)"
    fi

    # Follow shebang if AT_EXECFN points to a wrapper script.
    if [ -n "${exe}" ] && [ -f "${exe}" ] && head -c 2 "${exe}" 2>/dev/null | grep -q '^#!'; then
        local interp
        interp="$(head -n 1 "${exe}" | sed 's/^#![[:space:]]*//' | awk '{print $1}')"
        if [ -n "${interp}" ] && [ -f "${interp}" ]; then
            exe="${interp}"
        fi
    fi

    if [ -z "${exe}" ] || [ ! -f "${exe}" ]; then
        if [ -n "${fallback_exe}" ] && [ -f "${fallback_exe}" ]; then
            exe="${fallback_exe}"
        else
            exe="$(command -v php 2>/dev/null || true)"
        fi
    fi

    printf '%s' "${exe}"
}

for core_file in "${collected_cores[@]}"; do
    bt_file="${core_file}.bt.txt"
    if ! command -v gdb >/dev/null 2>&1; then
        echo "gdb is not installed; cannot symbolize ${core_file}" > "${bt_file}"
    else
        exe="$(resolve_exe "${core_file}")"
        echo "symbolizing ${core_file} with exe=${exe:-<none>}" | tee -a "${diag_file}"
        gdb_cmd=(
            gdb -batch
            -ex "set pagination off"
            -ex "set auto-load safe-path /"
        )
        if [ -n "${exe}" ] && [ -f "${exe}" ]; then
            gdb_cmd+=(-ex "file ${exe}")
        fi
        gdb_cmd+=(
            -ex "core-file ${core_file}"
            -ex "bt"
            -ex "info threads"
            -ex "thread apply all bt full"
            -ex "thread apply all info registers"
            -ex "info sharedlibrary"
        )
        if command -v timeout >/dev/null 2>&1; then
            timeout 120 "${gdb_cmd[@]}" > "${bt_file}" 2>&1 || true
        else
            "${gdb_cmd[@]}" > "${bt_file}" 2>&1 || true
        fi
    fi

    gzip -1 -f "${core_file}" 2>/dev/null || true
done
