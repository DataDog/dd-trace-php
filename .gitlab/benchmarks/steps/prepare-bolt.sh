#!/usr/bin/env bash

set -euo pipefail

if [ "$#" -ne 3 ]; then
  echo "Usage: $0 <candidate-source> <baseline-source-or-empty> <output-directory>" >&2
  exit 1
fi

CANDIDATE_SRC=$(realpath "$1")
BASELINE_SRC=$2
VARIANTS=(candidate)
if [ -n "$BASELINE_SRC" ]; then
  BASELINE_SRC=$(realpath "$BASELINE_SRC")
  VARIANTS+=(baseline)
fi
BOLT_DIR=$3
mkdir -p "$BOLT_DIR"
BOLT_DIR=$(realpath "$BOLT_DIR")
BOLT_MODE=${BOLT_MODE:-align}
case "$BOLT_MODE" in
  align) BOLT_BIN=${BOLT_ALIGN_BIN:-llvm-bolt-align} ;;
  plain) BOLT_BIN=${BOLT_BIN:-llvm-bolt} ;;
  *) echo "Preparing BOLT modules requires BOLT_MODE=plain or align." >&2; exit 1 ;;
esac
if [ "$BOLT_MODE" = align ] && [ -z "$BASELINE_SRC" ]; then
  echo "BOLT alignment requires a baseline source directory." >&2
  exit 1
fi
unset DD_TRACE_ASSUME_COMPILED

# These flags are required by BOLT; use the same flags for both revisions.
export CFLAGS="${CFLAGS:--O2 -falign-functions=64} -fno-reorder-blocks-and-partition"
export LDFLAGS="${LDFLAGS:+$LDFLAGS }-Wl,--emit-relocs"
"$BOLT_BIN" --version

# Cargo hashes the absolute path of path dependencies that lie outside the
# workspace root (tmp/build_combined depends on ../../libdatadog), and rustc
# derives Rust symbol names from that hash. Building the revisions in their own
# checkouts would give all the Rust functions different names in each binary,
# and llvm-bolt-align matches functions by name: it could align only the C code
# (~60% of the functions). Build both revisions at the same path instead.
BUILD_ROOT="$BOLT_DIR/build"
for VARIANT in "${VARIANTS[@]}"; do
  if [ "$VARIANT" = candidate ]; then SRC=$CANDIDATE_SRC; else SRC=$BASELINE_SRC; fi
  rm -rf "$BUILD_ROOT"
  cp -a "$SRC" "$BUILD_ROOT"
  (cd "$BUILD_ROOT" && make -j"$(nproc)" compile_combined)
  cp "$BUILD_ROOT/tmp/build_combined/modules/ddtrace.so" "$BOLT_DIR/$VARIANT-natural.so"
done
rm -rf "$BUILD_ROOT"

if [ "$BOLT_MODE" = align ]; then
  if ! "$BOLT_BIN" "$BOLT_DIR/baseline-natural.so" "$BOLT_DIR/candidate-natural.so" \
      -o-a="$BOLT_DIR/baseline.so" -o-b="$BOLT_DIR/candidate.so" \
      > "$BOLT_DIR/alignment.log" 2>&1; then
    cat "$BOLT_DIR/alignment.log" >&2
    exit 1
  fi
  cat "$BOLT_DIR/alignment.log"
  if grep -q 'missed offset' "$BOLT_DIR/alignment.log" || ! awk '
    /satisfied [0-9]+ of [0-9]+ layout constraints/ {
      for (i = 1; i <= NF; i++) {
        if ($i == "satisfied") {
          if ($(i + 1) == 0 || $(i + 1) != $(i + 3)) exit 1
          count++
        }
      }
    }
    END { if (count != 2) exit 1 }
  ' "$BOLT_DIR/alignment.log"; then
    echo "BOLT alignment did not satisfy the common-function layout constraints." >&2
    exit 1
  fi
else
  for VARIANT in "${VARIANTS[@]}"; do
    if [ "$VARIANT" = candidate ]; then SRC=$CANDIDATE_SRC; else SRC=$BASELINE_SRC; fi
    if ! "$BOLT_BIN" "$BOLT_DIR/$VARIANT-natural.so" \
        -o="$BOLT_DIR/$VARIANT.so" > "$BOLT_DIR/$VARIANT.log" 2>&1; then
      cat "$BOLT_DIR/$VARIANT.log" >&2
      exit 1
    fi
    cat "$BOLT_DIR/$VARIANT.log"
  done
fi
for VARIANT in "${VARIANTS[@]}"; do
  if [ "$VARIANT" = candidate ]; then SRC=$CANDIDATE_SRC; else SRC=$BASELINE_SRC; fi
  # ASSUME_COMPILED makes the tracer Makefile install this module directly,
  # rather than using libtool's original copy in .libs/.
  mkdir -p "$SRC/tmp/build_combined/modules"
  cp "$BOLT_DIR/$VARIANT.so" "$SRC/tmp/build_combined/modules/ddtrace.so"
done
