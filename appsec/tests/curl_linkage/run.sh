#!/usr/bin/env bash
# Unless explicitly stated otherwise all files in this repository are
# dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
#
# This product includes software developed at Datadog
# (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

# Negative (and positive) coverage of the libcurl linkage detection
# (appsec/src/extension/downstream/curl_linkage_detect.c), independently of
# PHP. Builds a fake libcurl and fake ext/curl objects linked in the ways
# that matter, and checks the verdict for each. Linux (glibc or musl) only.
#
# Usage: run.sh [work dir]   (CC selects the compiler; default: cc)

set -euo pipefail

CC=${CC:-cc}
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
SRC=$HERE/../../src/extension/downstream/curl_linkage_detect.c
W=${1:-$(mktemp -d)}
mkdir -p "$W"

if [[ $(uname -s) != Linux ]]; then
  echo "skip: Linux only"
  exit 0
fi
if [[ -e /lib/ld-musl-$(uname -m).so.1 ]]; then
  LIBC=musl
else
  LIBC=glibc
fi
echo "libc: $LIBC, arch: $(uname -m), work dir: $W"

CFLAGS=(-O1 -g -fPIC -Wall -Werror)
cc_so() { "$CC" "${CFLAGS[@]}" -shared "$@"; }

# dynamic libcurl stand-in, and a different one (other SONAME) to preload
cc_so -Wl,-soname,libfakecurl.so.4 -o "$W/libfakecurl.so.4" "$HERE/fakecurl.c"
ln -sf libfakecurl.so.4 "$W/libfakecurl.so"
cc_so -DFAKECURL_VERSION='"7.99.0-alt"' -Wl,-soname,libfakecurlalt.so.4 \
  -o "$W/libfakecurlalt.so.4" "$HERE/fakecurl.c"
cc_so -Wl,-soname,libsetopt.so -o "$W/libsetopt.so" "$HERE/setopt_only.c"

# ext/curl stand-ins
DYN=(-L"$W" -lfakecurl -Wl,-rpath,"$W")
cc_so -o "$W/ext_dynamic_lazy.so" "$HERE/fake_ext.c" "${DYN[@]}" -Wl,-z,lazy
cc_so -o "$W/ext_dynamic_now.so" "$HERE/fake_ext.c" "${DYN[@]}" -Wl,-z,now
cc_so -fno-plt -o "$W/ext_dynamic_noplt.so" "$HERE/fake_ext.c" "${DYN[@]}"
# libcurl.a linked into curl.so, its symbols exported
cc_so -DFAKECURL_VERSION='"7.99.0-static"' -o "$W/ext_static_exported.so" \
  "$HERE/fake_ext.c" "$HERE/fakecurl.c" -Wl,-z,now
# libcurl linked in with hidden symbols: calls bound at link time
cc_so -fvisibility=hidden -o "$W/ext_static_hidden.so" \
  -DFAKECURL_VERSION='"7.99.0-hidden"' "$HERE/fake_ext.c" "$HERE/fakecurl.c"
# undefined curl_* symbols, but no DT_NEEDED on libcurl
cc_so -o "$W/ext_underlinked.so" "$HERE/fake_ext.c"

# A read-only dynamic section (lld's -z rodynamic): glibc >= 2.35 then leaves
# its d_ptr entries unrelocated, as musl always does. Both the ext/curl and
# the libcurl stand-in, since the dynamic sections of both are parsed.
LLD_FLAGS=()
for lld in ld.lld ld.lld-{22..17}; do
  if command -v "$lld" > /dev/null; then
    if "$CC" --version 2>/dev/null | grep -q clang; then
      LLD_FLAGS=(--ld-path="$(command -v "$lld")")
    elif [[ $lld == ld.lld ]]; then
      LLD_FLAGS=(-fuse-ld=lld)
    fi
    break
  fi
done
if ((${#LLD_FLAGS[@]})); then
  RODYN=("${LLD_FLAGS[@]}" -Wl,-z,rodynamic)
  cc_so "${RODYN[@]}" -DFAKECURL_VERSION='"7.99.0-rodyn"' \
    -Wl,-soname,libfakecurlro.so.4 -o "$W/libfakecurlro.so.4" "$HERE/fakecurl.c"
  cc_so "${RODYN[@]}" -o "$W/ext_rodynamic.so" "$HERE/fake_ext.c" \
    "$W/libfakecurlro.so.4" -Wl,-rpath,"$W"
fi

"$CC" -O1 -g -Wall -Werror -o "$W/harness" "$HERE/harness.c" "$SRC" -ldl

failures=0
run() {
  local name=$1
  shift
  echo "== $name"
  if ! env "$@"; then
    failures=$((failures + 1))
  fi
}
H=$W/harness

run "dynamic libcurl, lazy binding" \
  "$H" "$W/ext_dynamic_lazy.so" on "bound to dynamic libcurl libfakecurl.so.4"
run "dynamic libcurl, BIND_NOW" \
  "$H" "$W/ext_dynamic_now.so" on "bound to dynamic libcurl libfakecurl.so.4"
run "dynamic libcurl, -fno-plt (GLOB_DAT)" \
  "$H" "$W/ext_dynamic_noplt.so" on "bound to dynamic libcurl libfakecurl.so.4"
run "static libcurl in curl.so, exported" \
  "$H" "$W/ext_static_exported.so" off "not bound to another object"
run "static libcurl in curl.so, exported, other libcurl preloaded" \
  LD_PRELOAD="$W/libfakecurl.so.4" \
  "$H" "$W/ext_static_exported.so" off "'libfakecurl.so.4' is not in the DT_NEEDED"
run "static libcurl, hidden (no relocation)" \
  "$H" "$W/ext_static_hidden.so" off "no dynamic reference to curl_global_init"
run "underlinked curl.so, libcurl preloaded" \
  LD_PRELOAD="$W/libfakecurl.so.4" \
  "$H" "$W/ext_underlinked.so" off "is not in the DT_NEEDED"
run "preload interposing all curl_* functions" \
  LD_PRELOAD="$W/libfakecurlalt.so.4" \
  "$H" "$W/ext_dynamic_now.so" off "'libfakecurlalt.so.4' is not in the DT_NEEDED"
# Exception to G3.5: not detected, the verdict stays on
run "preload interposing curl_easy_setopt only (G3.5 exception)" \
  LD_PRELOAD="$W/libsetopt.so" \
  "$H" "$W/ext_dynamic_now.so" on "bound to dynamic libcurl libfakecurl.so.4"
if [[ -e $W/ext_rodynamic.so ]]; then
  run "read-only dynamic sections (unrelocated d_ptr entries)" \
    "$H" "$W/ext_rodynamic.so" on "bound to dynamic libcurl libfakecurlro.so.4"
else
  echo "== read-only dynamic sections: skipped, no lld found"
fi
if [[ $LIBC == glibc ]]; then
  # curl_global_init's slot is only bound when ext/curl's MINIT calls it
  run "detection before MINIT, lazy binding" \
    "$H" --no-minit "$W/ext_dynamic_lazy.so" off "not bound to another object"
else
  # musl binds every slot when loading the object
  run "detection before MINIT (musl: eager binding)" \
    "$H" --no-minit "$W/ext_dynamic_lazy.so" on "bound to dynamic libcurl"
fi

if ((failures)); then
  echo "$failures case(s) failed"
  exit 1
fi
echo "all cases passed"
