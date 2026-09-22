#!/bin/sh
set -eu

input=$1
output_dir=$2
materialized=$3
version_file=$4
rustc_env=$5
output="${output_dir}/bindings.rs"

mkdir -p "${output_dir}"

# profiling/build.rs's IntKind callback makes these PHP discriminants u8.
# The hermetic bindgen CLI has no per-macro type callback, so reproduce that
# narrow transformation after generation.
sed -E \
  -e 's/(pub const (IS_UNDEF|IS_NULL|IS_FALSE|IS_TRUE|IS_LONG|IS_DOUBLE|IS_STRING|IS_ARRAY|IS_OBJECT|IS_RESOURCE|IS_REFERENCE|_IS_BOOL|ZEND_INTERNAL_FUNCTION|ZEND_USER_FUNCTION)[[:space:]]*):[[:space:]]*(u32|i32)[[:space:]]*=/\1: u8 =/g' \
  "${input}" >"${materialized}"

cp "${materialized}" "${output}"
chmod 0444 "${materialized}" "${output}"

version=$(cat "${version_file}")
case "${version}" in
  ""|*[!0-9A-Za-z._-]*) echo "VERSION must contain one nonempty token" >&2; exit 1 ;;
esac
printf 'PROFILER_VERSION=%s\n' "${version}" >"${rustc_env}"
chmod 0444 "${rustc_env}"
