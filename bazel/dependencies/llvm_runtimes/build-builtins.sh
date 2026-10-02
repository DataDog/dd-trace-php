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
sysroot=
jobs=
builtins_static=
crtbegin_output=
crtend_output=

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
        --sysroot) sysroot=$2 ;;
        --jobs) jobs=$2 ;;
        --builtins-static) builtins_static=$2 ;;
        --crtbegin) crtbegin_output=$2 ;;
        --crtend) crtend_output=$2 ;;
        *) echo "unknown compiler-rt build argument: $1" >&2; exit 2 ;;
    esac
    shift 2
done

for required in source_root output cmake make cc cxx ar ranlib ld nm objcopy objdump strip python target sysroot jobs \
    builtins_static crtbegin_output crtend_output; do
    eval "value=\${$required}"
    if [ -z "$value" ]; then
        echo "missing compiler-rt build argument: $required" >&2
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
builtins_static=$(absolute_path "$builtins_static")
crtbegin_output=$(absolute_path "$crtbegin_output")
crtend_output=$(absolute_path "$crtend_output")

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
if [ -z "${HERMETIC_LLVM_ROOT-}" ]; then
    echo "HERMETIC_LLVM_ROOT is required for compiler-rt configuration" >&2
    exit 2
fi

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
    x86_64-*) target_processor=x86_64 ;;
    aarch64-*) target_processor=aarch64 ;;
    *) echo "unsupported compiler-rt target: $target" >&2; exit 2 ;;
esac

build_dir="${output}.build"
rm -rf "$build_dir" "$output"
mkdir -p "$build_dir" "$output"
# Publish a complete Clang resource directory.  compiler-rt installs only its
# target libraries; builtin headers come from the checksum-locked LLVM tool
# distribution declared in foreign.compiler_files.
test -d "$HERMETIC_LLVM_ROOT/lib/clang/20/include"
mkdir -p "$output/include"
cp -RL "$HERMETIC_LLVM_ROOT/lib/clang/20/include/." "$output/include/"
export CMAKE_BUILD_PARALLEL_LEVEL=$jobs
export MAKEFLAGS="-j$jobs"
export ZERO_AR_DATE=1

"$cmake" -G "Unix Makefiles" \
    -S "$source_root/runtimes" \
    -B "$build_dir" \
    "-DCMAKE_AR=$ar" \
    "-DCMAKE_ASM_COMPILER=$cc" \
    "-DCMAKE_ASM_COMPILER_TARGET=$target" \
    "-DCMAKE_ASM_FLAGS=-fdebug-prefix-map=$action_root=. -ffile-prefix-map=$action_root=." \
    "-DCMAKE_C_COMPILER=$cc" \
    "-DCMAKE_COMMAND=$cmake" \
    "-DCMAKE_C_COMPILER_TARGET=$target" \
    "-DCMAKE_C_FLAGS=-fdebug-prefix-map=$action_root=. -ffile-prefix-map=$action_root=." \
    "-DCMAKE_CXX_COMPILER=$cxx" \
    "-DCMAKE_CXX_COMPILER_TARGET=$target" \
    "-DCMAKE_CXX_FLAGS=-fdebug-prefix-map=$action_root=. -ffile-prefix-map=$action_root=." \
    "-DCMAKE_EXE_LINKER_FLAGS=-fuse-ld=lld --ld-path=$ld -Wl,--build-id=sha1" \
    "-DCMAKE_SHARED_LINKER_FLAGS=-fuse-ld=lld --ld-path=$ld -Wl,--build-id=sha1" \
    "-DCMAKE_INSTALL_PREFIX=/" \
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
    '-DLLVM_ENABLE_RUNTIMES=compiler-rt' \
    -DCOMPILER_RT_DEFAULT_TARGET_ONLY=ON \
    -DCOMPILER_RT_BUILD_BUILTINS=ON \
    -DCOMPILER_RT_BUILD_CRT=ON \
    -DCOMPILER_RT_BUILD_CTX_PROFILE=OFF \
    -DCOMPILER_RT_BUILD_GWP_ASAN=OFF \
    -DCOMPILER_RT_BUILD_LIBFUZZER=OFF \
    -DCOMPILER_RT_BUILD_MEMPROF=OFF \
    -DCOMPILER_RT_BUILD_ORC=OFF \
    -DCOMPILER_RT_BUILD_PROFILE=OFF \
    -DCOMPILER_RT_BUILD_SANITIZERS=OFF \
    -DCOMPILER_RT_BUILD_XRAY=OFF \
    -DCOMPILER_RT_INCLUDE_TESTS=OFF \
    "-DLLVM_DEFAULT_TARGET_TRIPLE=$target" \
    -DLLVM_INCLUDE_DOCS=OFF \
    -DLLVM_INCLUDE_TESTS=OFF \
    "-DPython3_EXECUTABLE=$python"

"$cmake" --build "$build_dir" --target install --parallel "$jobs" -- DESTDIR="$output"

builtins=
crtbegin=
crtend=
for candidate in "$output"/lib/linux/libclang_rt.builtins-*.a "$output"/lib/libclang_rt.builtins-*.a; do
    [ -f "$candidate" ] || continue
    [ -z "$builtins" ] || { echo "multiple builtins archives installed" >&2; exit 1; }
    builtins=$candidate
done
for candidate in "$output"/lib/linux/clang_rt.crtbegin-*.o "$output"/lib/clang_rt.crtbegin-*.o; do
    [ -f "$candidate" ] || continue
    [ -z "$crtbegin" ] || { echo "multiple crtbegin objects installed" >&2; exit 1; }
    crtbegin=$candidate
done
for candidate in "$output"/lib/linux/clang_rt.crtend-*.o "$output"/lib/clang_rt.crtend-*.o; do
    [ -f "$candidate" ] || continue
    [ -z "$crtend" ] || { echo "multiple crtend objects installed" >&2; exit 1; }
    crtend=$candidate
done
[ -n "$builtins" ] || { echo "compiler-rt builtins archive was not installed" >&2; exit 1; }
[ -n "$crtbegin" ] || { echo "compiler-rt crtbegin was not installed" >&2; exit 1; }
[ -n "$crtend" ] || { echo "compiler-rt crtend was not installed" >&2; exit 1; }

cp "$builtins" "$builtins_static"
cp "$crtbegin" "$crtbegin_output"
cp "$crtend" "$crtend_output"
if [ -n "$(find "$output" -type l -print)" ]; then
    echo "compiler-rt resource directory contains a symlink" >&2
    find "$output" -type l -print >&2
    exit 1
fi
find "$output" -type d -exec chmod 0755 {} ';'
find "$output" -type f -exec chmod 0644 {} ';'
find "$output" -exec touch -h -d '@0' {} ';'
touch -d '@0' "$builtins_static" "$crtbegin_output" "$crtend_output"
rm -rf "$build_dir"
