#!/bin/sh
# Build the AppSec-vendored libxml2 with the configured PHP ABI's thread mode.
set -eu
source= prefix= archive= smoke= shared_smoke= smoke_source= version_script= cmake= make= cc= ar= ranlib= ld= objdump= target= sysroot= threads= crtbegin= crtend= builtins= runtime_unwind= asan=0 asan_shared= target_resource_dir=
while [ "$#" -gt 0 ]; do
    case "$1" in
        --source) source=$2 ;; --prefix) prefix=$2 ;; --archive) archive=$2 ;;
        --smoke) smoke=$2 ;; --shared-smoke) shared_smoke=$2 ;; --smoke-source) smoke_source=$2 ;;
        --version-script) version_script=$2 ;;
        --cmake) cmake=$2 ;; --make) make=$2 ;; --cc) cc=$2 ;; --ar) ar=$2 ;;
        --ranlib) ranlib=$2 ;; --ld) ld=$2 ;; --objdump) objdump=$2 ;;
        --target) target=$2 ;; --sysroot) sysroot=$2 ;; --threads) threads=$2 ;;
        --crtbegin) crtbegin=$2 ;; --crtend) crtend=$2 ;; --builtins) builtins=$2 ;; --runtime-unwind) runtime_unwind=$2 ;;
        --asan) asan=1; shift; continue ;; --asan-shared) asan_shared=$2 ;; --target-resource-dir) target_resource_dir=$2 ;;
        *) echo "unknown AppSec libxml2 argument: $1" >&2; exit 2 ;;
    esac
    shift 2
done
for required in source prefix archive smoke shared_smoke smoke_source version_script cmake make cc ar ranlib ld objdump target sysroot threads crtbegin crtend builtins runtime_unwind; do eval "value=\${$required}"; test -n "$value" || exit 2; done
root=$(pwd -P)
abs() { case "$1" in /*) printf '%s\n' "$1" ;; *) printf '%s/%s\n' "$root" "$1" ;; esac; }
for v in source prefix archive smoke shared_smoke smoke_source version_script cmake make cc ar ranlib ld objdump sysroot crtbegin crtend builtins runtime_unwind asan_shared target_resource_dir; do eval "x=\${$v}"; test -z "$x" || eval "$v=\$(abs \"\$x\")"; done
for v in HERMETIC_EXEC_RUNTIME_ROOT HERMETIC_LLVM_ROOT HERMETIC_TOOLS_ROOT; do eval "x=\${$v-}"; test -z "$x" || eval "export $v=\$(abs \"\$x\")"; done
normalized_path=
old_ifs=$IFS
IFS=:
for entry in $PATH; do
    normalized_path="${normalized_path:+$normalized_path:}$(abs "${entry:-.}")"
done
IFS=$old_ifs
PATH=$normalized_path
export PATH
case "$target" in x86_64-*) processor=x86_64; libdir="$sysroot/usr/lib64" ;; aarch64-*) processor=aarch64; libdir="$sysroot/usr/lib64" ;; *) exit 2 ;; esac
case "$target" in *-musl) libdir="$sysroot/usr/lib" ;; esac
resource="$HERMETIC_LLVM_ROOT/lib/clang/20"
test -d "$resource/include"
rm -rf "$prefix" "${prefix}.build"
mkdir -p "$prefix"
flags="--target=$target --sysroot=$sysroot -fuse-ld=lld --ld-path=$ld -fPIC -nostdinc -isystem $resource/include -isystem $sysroot/usr/include -resource-dir=$resource -ffile-prefix-map=$root=/usr/src/appsec-libxml2 -fdebug-prefix-map=$root=/usr/src/appsec-libxml2"
asan_flags=
asan_link_input=
if [ "$asan" = 1 ]; then
    test -n "$asan_shared" && test -f "$asan_shared" && test -n "$target_resource_dir" && test -d "$target_resource_dir"
    asan_flags="-fsanitize=address -fno-omit-frame-pointer -resource-dir=$target_resource_dir"
    asan_link_input=$asan_shared
fi
flags="$flags $asan_flags"
"$cmake" -G 'Unix Makefiles' -S "$source" -B "${prefix}.build" \
  "-DCMAKE_SYSTEM_NAME=Linux" "-DCMAKE_SYSTEM_PROCESSOR=$processor" \
  "-DCMAKE_C_COMPILER=$cc" "-DCMAKE_C_COMPILER_TARGET=$target" "-DCMAKE_SYSROOT=$sysroot" \
  "-DCMAKE_C_FLAGS=$flags" "-DCMAKE_AR=$ar" "-DCMAKE_RANLIB=$ranlib" "-DCMAKE_MAKE_PROGRAM=$make" \
  -DCMAKE_TRY_COMPILE_TARGET_TYPE=STATIC_LIBRARY -DCMAKE_C_COMPILER_WORKS=1 -DCMAKE_C_COMPILER_FORCED=ON -DCMAKE_BUILD_TYPE=Release -DCMAKE_FIND_USE_SYSTEM_ENVIRONMENT_PATH=FALSE \
  -DCMAKE_FIND_USE_CMAKE_SYSTEM_PATH=FALSE -DCMAKE_FIND_PACKAGE_NO_PACKAGE_REGISTRY=ON -DCMAKE_FIND_PACKAGE_NO_SYSTEM_PACKAGE_REGISTRY=ON \
  "-DCMAKE_FIND_ROOT_PATH=$sysroot" -DCMAKE_FIND_ROOT_PATH_MODE_PROGRAM=NEVER -DCMAKE_FIND_ROOT_PATH_MODE_LIBRARY=ONLY -DCMAKE_FIND_ROOT_PATH_MODE_INCLUDE=ONLY \
  -DCMAKE_POSITION_INDEPENDENT_CODE=ON -DCMAKE_C_VISIBILITY_PRESET=hidden -DBUILD_SHARED_LIBS=OFF \
  "-DLIBXML2_WITH_THREADS=$threads" -DLIBXML2_WITH_THREAD_ALLOC=OFF \
  -DLIBXML2_WITH_ICONV=OFF -DLIBXML2_WITH_ICU=OFF -DLIBXML2_WITH_LZMA=OFF -DLIBXML2_WITH_PYTHON=OFF -DLIBXML2_WITH_ZLIB=OFF \
  -DLIBXML2_WITH_FTP=OFF -DLIBXML2_WITH_HTTP=OFF -DLIBXML2_WITH_C14N=OFF -DLIBXML2_WITH_CATALOG=OFF -DLIBXML2_WITH_DEBUG=OFF -DLIBXML2_WITH_HTML=OFF -DLIBXML2_WITH_LEGACY=OFF -DLIBXML2_WITH_MODULES=OFF -DLIBXML2_WITH_OUTPUT=OFF -DLIBXML2_WITH_PATTERN=OFF -DLIBXML2_WITH_PROGRAMS=OFF -DLIBXML2_WITH_PUSH=ON -DLIBXML2_WITH_READER=OFF -DLIBXML2_WITH_REGEXPS=OFF -DLIBXML2_WITH_SAX1=OFF -DLIBXML2_WITH_SCHEMAS=OFF -DLIBXML2_WITH_SCHEMATRON=OFF -DLIBXML2_WITH_TESTS=OFF -DLIBXML2_WITH_TREE=ON -DLIBXML2_WITH_VALID=OFF -DLIBXML2_WITH_WRITER=OFF -DLIBXML2_WITH_XINCLUDE=OFF -DLIBXML2_WITH_XPATH=OFF -DLIBXML2_WITH_XPTR=OFF \
  "-DCMAKE_INSTALL_PREFIX=$prefix" -DCMAKE_INSTALL_LIBDIR=lib
"$cmake" --build "${prefix}.build" --target LibXml2 -- -j1
"$cmake" --install "${prefix}.build"
cp "$prefix/lib/libxml2.a" "$archive"
rm -rf "$prefix/bin" "$prefix/lib/pkgconfig" "$prefix/lib/cmake"
thread_define=0
test "$threads" != OFF && thread_define=1
"$cc" $flags -DAPPSEC_EXPECT_THREADS=$thread_define -nostdlib -shared -Wl,-z,defs "-Wl,--version-script=$version_script" -I"$prefix/include/libxml2" "$smoke_source" "$archive" -L"$libdir" -lpthread -lc "$runtime_unwind" "$builtins" $asan_link_input -o "$shared_smoke"
if [ "$asan" = 1 ]; then
    # The dynamic test binary keeps the sanitizer DSO outside the archive
    # interface. Its direct-loader runner validates the complete declared
    # ASan/sysroot closure without RPATH or host loader fallback.
    "$cc" $flags -shared-libasan -no-pie -nostdlib "$libdir/crt1.o" "$libdir/crti.o" "$crtbegin" -DAPPSEC_EXPECT_THREADS=$thread_define -I"$prefix/include/libxml2" "$smoke_source" "$archive" -L"$libdir" -lpthread -lc "$runtime_unwind" "$builtins" "$crtend" "$libdir/crtn.o" "$asan_shared" -o "$smoke"
else
    "$cc" $flags -DAPPSEC_EXPECT_THREADS=$thread_define -nostdlib -static "$libdir/crt1.o" "$libdir/crti.o" "$crtbegin" -I"$prefix/include/libxml2" "$smoke_source" "$archive" -L"$libdir" -lpthread -lc "$runtime_unwind" "$builtins" "$crtend" "$libdir/crtn.o" -o "$smoke"
fi
inspect="${prefix}.dynamic"
if ! "$objdump" -p "$shared_smoke" > "$inspect"; then cat "$inspect" >&2; exit 1; fi
if grep TEXTREL "$inspect" >/dev/null; then cat "$inspect" >&2; exit 1; fi
rm -f "$inspect"
find "$prefix" -exec touch -h -d @0 {} ';'
touch -d @0 "$archive" "$smoke" "$shared_smoke"
chmod 0755 "$smoke"
