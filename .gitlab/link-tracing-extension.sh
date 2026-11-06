#!/usr/bin/env bash
set -e -o pipefail

suffix="${1:-}"

pids=()
for archive in extensions_$(uname -m)/*.a; do
  # PHP 7.0 NTS/ZTS on both libc targets have a complete ThinLTO .so. Only link
  # variants without a prebuilt extension (notably the non-LTO debug ABI).
  if [ -f "${archive%.a}.so" ]; then
    continue
  fi
  (
    # Debug C objects stay native, but the shared release Rust archive now
    # contains LLVM IR. Link it with matched LLD rather than the system ld.
    ./tooling/bin/xlang-cc -shared -Wl,-whole-archive $archive -Wl,-no-whole-archive \
      $(cat "ddtrace_$(uname -m)${suffix}-fat.ldflags") \
      -Wl,--retain-symbols-file="ddtrace_$(uname -m)${suffix}-fat.sym" \
      "libdatadog_php_$(uname -m)${suffix}.a" \
      -Wl,-soname -Wl,ddtrace.so -o ${archive%.a}.so
    objcopy --compress-debug-sections ${archive%.a}.so
  ) &
  pids+=($!)
done
for pid in "${pids[@]}"; do
  wait $pid
done

# The archives are intermediate inputs. Keeping them in extensions_* causes
# the legacy RPM, DEB, and tarball targets to ship them to users.
rm -f extensions_$(uname -m)/*.a
