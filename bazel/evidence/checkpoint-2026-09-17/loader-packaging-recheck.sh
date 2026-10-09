#!/bin/sh
set -eu
set -x
repo=/home/bits/Documents/work/dd-trace-php/pawel_massive-bazel-exepriment
work=/tmp/dd-loader-packaging-final-review-20260917
execroot="$repo/build/sol-full-output/b954cc910a69d598fa2d07102528c35f/external/+execution_tools+exec_tools_alpine322_x86_64/root"
mkdir -p "$work/bin" "$work/inputs" "$work/payloads" "$work/archives"
cat > "$work/bin/pinned-tar" <<EOF2
#!/bin/sh
exec "$execroot/lib/ld-musl-x86_64.so.1" --library-path "$execroot/lib:$execroot/usr/lib" "$execroot/bin/tar" "\$@"
EOF2
cat > "$work/bin/gzip" <<EOF2
#!/bin/sh
exec "$execroot/lib/ld-musl-x86_64.so.1" --library-path "$execroot/lib:$execroot/usr/lib" "$execroot/bin/gzip" "\$@"
EOF2
chmod 0755 "$work/bin/pinned-tar" "$work/bin/gzip"
for record in \
  amd64_glibc:/tmp/dd-loader-elf-audit.5bPZmJ/dd-library-php-loader-stage:linux-gnu \
  arm64_glibc:/tmp/dd-loader-elf-audit.U2txIJ/dd-library-php-loader-stage:linux-gnu \
  amd64_musl:/tmp/dd-loader-elf-audit.kuCbUk/dd-library-php-loader-stage:linux-musl \
  arm64_musl:/tmp/dd-loader-elf-audit.BcyfCU/dd-library-php-loader-stage:linux-musl
do
  variant=${record%%:*}
  rest=${record#*:}
  source=${rest%:*}
  os_path=${rest##*:}
  input="$work/inputs/$variant"
  mkdir -p "$input"
  cp "$source/$os_path/loader/dd_library_loader.so" "$input/dd_library_loader.so"
  cp "$source/$os_path/loader/dd_library_loader.so.debug" "$input/dd_library_loader.so.debug"
  cp "$source/$os_path/loader/dd_library_loader.ini" "$input/dd_library_loader.ini"
  cp "$source/$os_path/loader/metadata.json" "$input/metadata.json"
  cp "$source/version" "$input/version"
  "$repo/tools/bazel/assemble-ssi-payload.sh" \
    --output "$work/payloads/$variant" \
    --version-file "$input/version" \
    --copy "$input/dd_library_loader.ini" "$os_path/loader/dd_library_loader.ini" \
    --copy "$input/dd_library_loader.so" "$os_path/loader/dd_library_loader.so" \
    --copy "$input/dd_library_loader.so.debug" "$os_path/loader/dd_library_loader.so.debug" \
    --copy "$input/metadata.json" "$os_path/loader/metadata.json" \
    --executable "$os_path/loader/dd_library_loader.so" \
    --require "$os_path/loader/dd_library_loader.ini" \
    --require "$os_path/loader/dd_library_loader.so" \
    --require "$os_path/loader/dd_library_loader.so.debug" \
    --require "$os_path/loader/metadata.json" \
    --require version
  PATH="$work/bin:$PATH" "$repo/tools/bazel/deterministic-tar.sh" \
    --tar "$work/bin/pinned-tar" \
    --root "$work/payloads/$variant" \
    --output "$work/archives/loader_stage_$variant.tar.gz" \
    --prefix dd-library-php-loader-stage \
    --executable "$os_path/loader/dd_library_loader.so"
done
sha256sum "$work"/archives/*.tar.gz
