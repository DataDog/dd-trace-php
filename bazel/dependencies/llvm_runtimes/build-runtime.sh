#!/bin/sh
set -eu

source_root=
output=
cmake=
make=
cc=
cxx=
ar=
ranlib=
ld=
nm=
objcopy=
objdump=
strip=
python=
target=
libc=
sysroot=
compiler_rt_root=
jobs=
libcxx_static=
libcxx_shared=
libcxxabi_static=
libcxxabi_shared=
libunwind_static=
libunwind_shared=
builtins_static=
crtbegin_output=
crtend_output=
cxx20_smoke=
asan_shared_output=
asan_static_output=
asan_cxx_static_output=
asan_preinit_static_output=

while [ "$#" -gt 0 ]; do
    case "$1" in
        --source) source_root=$2 ;;
        --output) output=$2 ;;
        --cmake) cmake=$2 ;;
        --make) make=$2 ;;
        --cc) cc=$2 ;;
        --cxx) cxx=$2 ;;
        --ar) ar=$2 ;;
        --ranlib) ranlib=$2 ;;
        --ld) ld=$2 ;;
        --nm) nm=$2 ;;
        --objcopy) objcopy=$2 ;;
        --objdump) objdump=$2 ;;
        --strip) strip=$2 ;;
        --python) python=$2 ;;
        --target) target=$2 ;;
        --libc) libc=$2 ;;
        --sysroot) sysroot=$2 ;;
        --compiler-rt-root) compiler_rt_root=$2 ;;
        --jobs) jobs=$2 ;;
        --libcxx-static) libcxx_static=$2 ;;
        --libcxx-shared) libcxx_shared=$2 ;;
        --libcxxabi-static) libcxxabi_static=$2 ;;
        --libcxxabi-shared) libcxxabi_shared=$2 ;;
        --libunwind-static) libunwind_static=$2 ;;
        --libunwind-shared) libunwind_shared=$2 ;;
        --builtins-static) builtins_static=$2 ;;
        --crtbegin) crtbegin_output=$2 ;;
        --crtend) crtend_output=$2 ;;
        --cxx20-smoke) cxx20_smoke=$2 ;;
        --asan-shared) asan_shared_output=$2 ;;
        --asan-static) asan_static_output=$2 ;;
        --asan-cxx-static) asan_cxx_static_output=$2 ;;
        --asan-preinit-static) asan_preinit_static_output=$2 ;;
        *) echo "unknown LLVM runtime build argument: $1" >&2; exit 2 ;;
    esac
    shift 2
done

for required in source_root output cmake make cc cxx ar ranlib ld nm objcopy objdump strip python target libc sysroot compiler_rt_root jobs \
    libcxx_static libcxx_shared libcxxabi_static libcxxabi_shared \
    libunwind_static libunwind_shared builtins_static crtbegin_output crtend_output cxx20_smoke; do
    eval "value=\${$required}"
    if [ -z "$value" ]; then
        echo "missing LLVM runtime build argument: $required" >&2
        exit 2
    fi
done

umask 022
action_root=$(pwd -P)

absolute_path() {
    case "$1" in
        /*) printf '%s\n' "$1" ;;
        *) printf '%s/%s\n' "$action_root" "$1" ;;
    esac
}

source_root=$(absolute_path "$source_root")
output=$(absolute_path "$output")
cmake=$(absolute_path "$cmake")
make=$(absolute_path "$make")
cc=$(absolute_path "$cc")
cxx=$(absolute_path "$cxx")
ar=$(absolute_path "$ar")
ranlib=$(absolute_path "$ranlib")
ld=$(absolute_path "$ld")
nm=$(absolute_path "$nm")
objcopy=$(absolute_path "$objcopy")
objdump=$(absolute_path "$objdump")
strip=$(absolute_path "$strip")
python=$(absolute_path "$python")
sysroot=$(absolute_path "$sysroot")
compiler_rt_root=$(absolute_path "$compiler_rt_root")
libcxx_static=$(absolute_path "$libcxx_static")
libcxx_shared=$(absolute_path "$libcxx_shared")
libcxxabi_static=$(absolute_path "$libcxxabi_static")
libcxxabi_shared=$(absolute_path "$libcxxabi_shared")
libunwind_static=$(absolute_path "$libunwind_static")
libunwind_shared=$(absolute_path "$libunwind_shared")
builtins_static=$(absolute_path "$builtins_static")
crtbegin_output=$(absolute_path "$crtbegin_output")
crtend_output=$(absolute_path "$crtend_output")
cxx20_smoke=$(absolute_path "$cxx20_smoke")
if [ -n "$asan_shared_output" ]; then
    for required in asan_static_output asan_cxx_static_output asan_preinit_static_output; do
        eval "value=\${$required}"
        [ -n "$value" ] || { echo "missing LLVM runtime build argument: $required" >&2; exit 2; }
    done
    asan_shared_output=$(absolute_path "$asan_shared_output")
    asan_static_output=$(absolute_path "$asan_static_output")
    asan_cxx_static_output=$(absolute_path "$asan_cxx_static_output")
    asan_preinit_static_output=$(absolute_path "$asan_preinit_static_output")
fi

normalize_env_root() {
    name=$1
    eval "value=\${$name-}"
    if [ -n "$value" ]; then
        value=$(absolute_path "$value")
        export "$name=$value"
    fi
}
normalize_env_root HERMETIC_EXEC_RUNTIME_ROOT
normalize_env_root HERMETIC_LLVM_ROOT
normalize_env_root HERMETIC_TOOLS_ROOT

normalized_path=
old_ifs=$IFS
IFS=:
for entry in $PATH; do
    entry=$(absolute_path "${entry:-.}")
    normalized_path="${normalized_path:+$normalized_path:}$entry"
done
IFS=$old_ifs
PATH=$normalized_path
export PATH

case "$target" in
    x86_64-*) target_processor=x86_64; runtime_arch=x86_64 ;;
    aarch64-*) target_processor=aarch64; runtime_arch=aarch64 ;;
    *) echo "unsupported LLVM runtime target: $target" >&2; exit 2 ;;
esac

libcxx_platform_options=
enabled_runtimes='libcxx;libcxxabi;libunwind'
compiler_rt_options=
case "$libc" in
    musl)
        # Cross probes are compile-only by design.  Musl does not export the
        # glibc-specific __cxa_thread_atexit_impl symbol, so pin that result
        # alongside libc++'s explicit musl configuration.
        libcxx_platform_options='-DLIBCXX_HAS_MUSL_LIBC=ON -DLIBCXXABI_HAS_CXA_THREAD_ATEXIT_IMPL=OFF -DLIBCXX_HAS_ATOMIC_LIB=OFF'
        [ -z "$asan_shared_output" ] || { echo "musl runtime does not publish ASan outputs" >&2; exit 2; }
        target_lib_dir="$sysroot/usr/lib"
        ;;
    glibc)
        # The supported glibc target floor is 2.17.  That libc predates
        # __cxa_thread_atexit_impl, and the CentOS closure has no libatomic;
        # Clang implements the required x86_64/aarch64 atomics directly.
        libcxx_platform_options='-DLIBCXXABI_HAS_CXA_THREAD_ATEXIT_IMPL=OFF -DLIBCXX_HAS_ATOMIC_LIB=OFF'
        [ -n "$asan_shared_output" ] || { echo "glibc runtime requires ASan outputs" >&2; exit 2; }
        enabled_runtimes='libcxx;libcxxabi;libunwind;compiler-rt'
        compiler_rt_options='-DCOMPILER_RT_BUILD_BUILTINS=OFF -DCOMPILER_RT_BUILD_CRT=OFF -DCOMPILER_RT_BUILD_CTX_PROFILE=OFF -DCOMPILER_RT_BUILD_SANITIZERS=ON -DCOMPILER_RT_SANITIZERS_TO_BUILD=asan -DCOMPILER_RT_DEFAULT_TARGET_ONLY=ON -DCOMPILER_RT_BUILD_GWP_ASAN=OFF -DCOMPILER_RT_BUILD_LIBFUZZER=OFF -DCOMPILER_RT_BUILD_MEMPROF=OFF -DCOMPILER_RT_BUILD_ORC=OFF -DCOMPILER_RT_BUILD_PROFILE=OFF -DCOMPILER_RT_BUILD_XRAY=OFF -DCOMPILER_RT_INCLUDE_TESTS=OFF -DCOMPILER_RT_USE_BUILTINS_LIBRARY=ON -DCOMPILER_RT_USE_LLVM_UNWINDER=ON -DCOMPILER_RT_ENABLE_STATIC_UNWINDER=ON -DCOMPILER_RT_CXX_LIBRARY=libcxx -DCOMPILER_RT_STATIC_CXX_LIBRARY=ON -DSANITIZER_CXX_ABI=libc++ -DSANITIZER_CXX_ABI_INTREE=ON -DSANITIZER_USE_STATIC_CXX_ABI=ON -DSANITIZER_TEST_CXX=none -DSANITIZER_NO_UNDEFINED_SYMBOLS=ON'
        target_lib_dir="$sysroot/usr/lib64"
        ;;
    *) echo "unsupported target libc: $libc" >&2; exit 2 ;;
esac

build_dir="${output}.build"
rm -rf "$build_dir" "$output"
mkdir -p "$build_dir" "$output"

export CMAKE_BUILD_PARALLEL_LEVEL=$jobs
export MAKEFLAGS="-j$jobs"
export ZERO_AR_DATE=1

"$cmake" -G "Unix Makefiles" \
    -S "$source_root/runtimes" \
    -B "$build_dir" \
    "-DCMAKE_AR=$ar" \
    "-DCMAKE_ASM_COMPILER=$cc" \
    "-DCMAKE_ASM_COMPILER_TARGET=$target" \
    "-DCMAKE_ASM_FLAGS=-resource-dir=$compiler_rt_root -fdebug-prefix-map=$action_root=. -ffile-prefix-map=$action_root=." \
    "-DCMAKE_C_COMPILER=$cc" \
    "-DCMAKE_COMMAND=$cmake" \
    "-DCMAKE_C_COMPILER_TARGET=$target" \
    "-DCMAKE_C_FLAGS=-resource-dir=$compiler_rt_root -fdebug-prefix-map=$action_root=. -ffile-prefix-map=$action_root=." \
    "-DCMAKE_CXX_COMPILER=$cxx" \
    "-DCMAKE_CXX_COMPILER_TARGET=$target" \
    "-DCMAKE_CXX_FLAGS=-resource-dir=$compiler_rt_root -fdebug-prefix-map=$action_root=. -ffile-prefix-map=$action_root=." \
    "-DCMAKE_EXE_LINKER_FLAGS=-fuse-ld=lld --ld-path=$ld -L$target_lib_dir -rtlib=compiler-rt -unwindlib=none -Wl,--build-id=sha1" \
    "-DCMAKE_SHARED_LINKER_FLAGS=-fuse-ld=lld --ld-path=$ld -L$target_lib_dir -nostartfiles -rtlib=compiler-rt -unwindlib=none -Wl,--build-id=sha1" \
    "-DCMAKE_INSTALL_PREFIX=/" \
    "-DCMAKE_INSTALL_LIBDIR=lib" \
    "-DCMAKE_LINKER=$ld" \
    "-DCMAKE_MAKE_PROGRAM=$make" \
    "-DCMAKE_NM=$nm" \
    "-DCMAKE_OBJCOPY=$objcopy" \
    "-DCMAKE_OBJDUMP=$objdump" \
    "-DCMAKE_RANLIB=$ranlib" \
    "-DCMAKE_STRIP=$strip" \
    "-DCMAKE_SYSROOT=$sysroot" \
    "-DCMAKE_FIND_ROOT_PATH=$sysroot" \
    -DCMAKE_FIND_ROOT_PATH_MODE_INCLUDE=ONLY \
    -DCMAKE_FIND_ROOT_PATH_MODE_LIBRARY=ONLY \
    -DCMAKE_FIND_ROOT_PATH_MODE_PACKAGE=ONLY \
    -DCMAKE_FIND_ROOT_PATH_MODE_PROGRAM=NEVER \
    -DCMAKE_FIND_PACKAGE_NO_PACKAGE_REGISTRY=ON \
    -DCMAKE_FIND_USE_CMAKE_ENVIRONMENT_PATH=OFF \
    -DCMAKE_FIND_USE_CMAKE_SYSTEM_PATH=OFF \
    -DCMAKE_FIND_USE_PACKAGE_REGISTRY=OFF \
    -DCMAKE_FIND_USE_SYSTEM_ENVIRONMENT_PATH=OFF \
    -DCMAKE_FIND_USE_SYSTEM_PACKAGE_REGISTRY=OFF \
    -DCMAKE_SYSTEM_NAME=Linux \
    "-DCMAKE_SYSTEM_PROCESSOR=$target_processor" \
    -DCMAKE_TRY_COMPILE_TARGET_TYPE=STATIC_LIBRARY \
    -DCMAKE_BUILD_TYPE=Release \
    -DCMAKE_POSITION_INDEPENDENT_CODE=ON \
    -DCMAKE_SKIP_RPATH=ON \
    "-DLLVM_DEFAULT_TARGET_TRIPLE=$target" \
    -DLLVM_INCLUDE_DOCS=OFF \
    -DLLVM_INCLUDE_TESTS=OFF \
    "-DPython3_EXECUTABLE=$python" \
    "-DLLVM_ENABLE_RUNTIMES=$enabled_runtimes" \
    -DLIBCXX_ENABLE_ABI_LINKER_SCRIPT=OFF \
    -DLIBCXX_ENABLE_SHARED=ON \
    -DLIBCXX_ENABLE_STATIC=ON \
    -DLIBCXX_INSTALL_INCLUDE_DIR=include/c++/v1 \
    -DLIBCXX_INCLUDE_BENCHMARKS=OFF \
    -DLIBCXX_INCLUDE_TESTS=OFF \
    -DLIBCXX_USE_COMPILER_RT=ON \
    $libcxx_platform_options \
    -DLIBCXXABI_ENABLE_SHARED=ON \
    -DLIBCXXABI_ENABLE_STATIC=ON \
    -DLIBCXXABI_ENABLE_STATIC_UNWINDER=OFF \
    -DLIBCXXABI_INCLUDE_TESTS=OFF \
    -DLIBCXXABI_INSTALL_INCLUDE_DIR=include/c++/v1 \
    -DLIBCXXABI_USE_COMPILER_RT=ON \
    -DLIBCXXABI_USE_LLVM_UNWINDER=ON \
    -DLIBUNWIND_ENABLE_SHARED=ON \
    -DLIBUNWIND_ENABLE_STATIC=ON \
    -DLIBUNWIND_INCLUDE_TESTS=OFF \
    -DLIBUNWIND_INSTALL_INCLUDE_DIR=include \
    -DLIBUNWIND_USE_COMPILER_RT=ON \
    $compiler_rt_options

"$cmake" --build "$build_dir" --target install --parallel "$jobs" -- DESTDIR="$output"

# LLVM's install rules may preserve source-tree symlinks for headers and
# modules.  Materialize published data so its contents do not refer back to an
# execroot.  The library SONAME chains below remain intentional relative links.
materialize_directory() {
    directory=$1
    [ -d "$directory" ] || return 0
    materialized="${directory}.materialized"
    rm -rf "$materialized"
    mkdir -p "$materialized"
    cp -RL "$directory/." "$materialized/"
    rm -rf "$directory"
    mv "$materialized" "$directory"
}
materialize_directory "$output/include"
materialize_directory "$output/share"
materialize_directory "$output/usr/include"
materialize_directory "$output/usr/share"

# The final provider root is also Clang's complete resource directory.  Merge
# the first action's pinned headers, builtins, and CRT objects into this
# action's distinct output only after materializing its installed headers.
mkdir -p "$output/include" "$output/lib/linux"
cp -RL "$compiler_rt_root/include/." "$output/include/"
cp -RL "$compiler_rt_root/lib/linux/." "$output/lib/linux/"

test -f "$output/lib/libc++.a"
test -f "$output/lib/libc++.so.1"
test -f "$output/lib/libc++abi.a"
test -f "$output/lib/libc++abi.so.1"
test -f "$output/lib/libunwind.a"
test -f "$output/lib/libunwind.so.1"
test -L "$output/lib/libc++.so"
test -L "$output/lib/libc++.so.1"
test -L "$output/lib/libc++abi.so"
test -L "$output/lib/libc++abi.so.1"
test -L "$output/lib/libunwind.so"
test -L "$output/lib/libunwind.so.1"

for link in $(find "$output" -type l -print); do
    link_target=$(readlink "$link")
    case "$link:$link_target" in
        "$output/lib/libc++.so:libc++.so.1"|\
        "$output/lib/libc++.so.1:libc++.so.1.0"|\
        "$output/lib/libc++abi.so:libc++abi.so.1"|\
        "$output/lib/libc++abi.so.1:libc++abi.so.1.0"|\
        "$output/lib/libunwind.so:libunwind.so.1"|\
        "$output/lib/libunwind.so.1:libunwind.so.1.0") ;;
        *) echo "unexpected LLVM runtime symlink: $link -> $link_target" >&2; exit 1 ;;
    esac
    test -e "$link" || { echo "broken LLVM runtime symlink: $link -> $link_target" >&2; exit 1; }
done

check_shared_library() {
    library=$1
    soname=$2
    metadata=$($objdump -p "$library")
    printf '%s\n' "$metadata" | grep "SONAME[[:space:]]*$soname" >/dev/null
    if printf '%s\n' "$metadata" | grep -E '(RPATH|RUNPATH|TEXTREL)' >/dev/null; then
        echo "unexpected dynamic metadata in $library" >&2
        exit 1
    fi
}
check_shared_library "$output/lib/libc++.so.1" 'libc++.so.1'
check_shared_library "$output/lib/libc++abi.so.1" 'libc++abi.so.1'
check_shared_library "$output/lib/libunwind.so.1" 'libunwind.so.1'

if [ -n "$asan_shared_output" ]; then
    asan_shared="$output/lib/linux/libclang_rt.asan-$runtime_arch.so"
    asan_static="$output/lib/linux/libclang_rt.asan-$runtime_arch.a"
    asan_cxx_static="$output/lib/linux/libclang_rt.asan_cxx-$runtime_arch.a"
    asan_preinit_static="$output/lib/linux/libclang_rt.asan-preinit-$runtime_arch.a"
    for artifact in "$asan_shared" "$asan_static" "$asan_cxx_static" "$asan_preinit_static"; do
        [ -f "$artifact" ] || { echo "missing compiler-rt ASan artifact: $artifact" >&2; exit 1; }
    done
    check_shared_library "$asan_shared" "libclang_rt.asan-$runtime_arch.so"
    cp "$asan_shared" "$asan_shared_output"
    cp "$asan_static" "$asan_static_output"
    cp "$asan_cxx_static" "$asan_cxx_static_output"
    cp "$asan_preinit_static" "$asan_preinit_static_output"
fi

cat >"$build_dir/cxx20-smoke.cc" <<'EOF'
#include <atomic>
#include <concepts>
#include <stdexcept>
#include <thread>
#include <type_traits>
#include <vector>

template <std::integral T>
constexpr T twice(T value) { return value + value; }

static_assert(twice(21) == 42);
static_assert(std::is_same_v<std::vector<int>::value_type, int>);

std::atomic<int> tls_destructors{0};

struct ThreadLocalGuard {
    ~ThreadLocalGuard() { tls_destructors.fetch_add(1, std::memory_order_relaxed); }
};

thread_local ThreadLocalGuard thread_guard;

int main() {
    std::vector<int> values{20, 22};
    if (values[0] + values[1] != twice(21)) return 1;

    bool caught = false;
    try {
        throw std::runtime_error("runtime smoke exception");
    } catch (const std::runtime_error&) {
        caught = true;
    }
    if (!caught) return 2;

    std::thread worker([] { (void)&thread_guard; });
    worker.join();
    return tls_destructors.load(std::memory_order_relaxed) == 1 ? 0 : 3;
}
EOF
"$cxx" --target="$target" --sysroot="$sysroot" -std=c++20 -nostdinc++ \
    -fdebug-prefix-map="$action_root"=. -ffile-prefix-map="$action_root"=. \
    -isystem "$output/include/c++/v1" -c "$build_dir/cxx20-smoke.cc" \
    -o "$build_dir/cxx20-smoke.o"

test -f "$builtins_static"
test -f "$crtbegin_output"
test -f "$crtend_output"

crt_dir=$target_lib_dir
test -f "$crt_dir/crt1.o"
test -f "$crt_dir/crti.o"
test -f "$crt_dir/crtn.o"
"$cxx" -v --target="$target" --sysroot="$sysroot" -fuse-ld=lld --ld-path="$ld" -nostdlib \
    -L"$target_lib_dir" \
    -static -Wl,--build-id=sha1 -o "$cxx20_smoke" \
    "$crt_dir/crt1.o" "$crt_dir/crti.o" "$crtbegin_output" \
    "$build_dir/cxx20-smoke.o" -Wl,--start-group \
    "$output/lib/libc++.a" "$output/lib/libc++abi.a" \
    "$output/lib/libunwind.a" "$builtins_static" -lpthread -ldl -lrt -lc -lm \
    -Wl,--end-group "$crtend_output" "$crt_dir/crtn.o"

case "$target" in
    x86_64-*) object_format='file format elf64-x86-64' ;;
    aarch64-*) object_format='file format elf64-littleaarch64' ;;
esac
"$objdump" -f "$cxx20_smoke" | grep "$object_format" >/dev/null
if "$objdump" -p "$cxx20_smoke" | grep -E '(RPATH|RUNPATH)' >/dev/null; then
    echo "unexpected runtime search path in C++20 smoke executable" >&2
    exit 1
fi

cp "$output/lib/libc++.a" "$libcxx_static"
cp -L "$output/lib/libc++.so.1" "$libcxx_shared"
cp "$output/lib/libc++abi.a" "$libcxxabi_static"
cp -L "$output/lib/libc++abi.so.1" "$libcxxabi_shared"
cp "$output/lib/libunwind.a" "$libunwind_static"
cp -L "$output/lib/libunwind.so.1" "$libunwind_shared"

# Build directories are scratch state. Published files receive stable modes
# and timestamps; static archives are produced by deterministic llvm-ar.
find "$output" -type d -exec chmod 0755 {} ';'
find "$output" -type f -exec chmod 0644 {} ';'
find "$output" -exec touch -h -d '@0' {} ';'
touch -d '@0' "$libcxx_static" "$libcxx_shared" "$libcxxabi_static" \
    "$libcxxabi_shared" "$libunwind_static" "$libunwind_shared" "$cxx20_smoke"
chmod 0755 "$cxx20_smoke"
if [ -n "$asan_shared_output" ]; then
    chmod 0644 "$asan_shared_output" "$asan_static_output" "$asan_cxx_static_output" "$asan_preinit_static_output"
    touch -d '@0' "$asan_shared_output" "$asan_static_output" "$asan_cxx_static_output" "$asan_preinit_static_output"
fi

rm -rf "$build_dir"
