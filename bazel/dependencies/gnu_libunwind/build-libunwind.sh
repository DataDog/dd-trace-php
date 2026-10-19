#!/bin/sh
# Cross-build the GNU 1.8.3 source embedded in libdd-libunwind-sys 1.0.3.
set -eu

configure= prefix= unwind= ptrace= arch= smoke= smoke_source= shared_smoke= shared_smoke_source= cc= cxx= ar= ranlib= nm= ld= make= shell= objdump= build= target= sysroot= crtbegin= crtend= builtins= runtime_unwind= asan=0 asan_shared= target_resource_dir=
while [ "$#" -gt 0 ]; do
    case "$1" in
        --configure) configure=$2 ;;
        --prefix) prefix=$2 ;;
        --unwind) unwind=$2 ;;
        --ptrace) ptrace=$2 ;;
        --arch) arch=$2 ;;
        --smoke) smoke=$2 ;;
        --smoke-source) smoke_source=$2 ;;
        --shared-smoke) shared_smoke=$2 ;;
        --shared-smoke-source) shared_smoke_source=$2 ;;
        --cc) cc=$2 ;;
        --cxx) cxx=$2 ;;
        --ar) ar=$2 ;;
        --ranlib) ranlib=$2 ;;
        --nm) nm=$2 ;;
        --ld) ld=$2 ;;
        --make) make=$2 ;;
        --shell) shell=$2 ;;
        --objdump) objdump=$2 ;;
        --build) build=$2 ;;
        --target) target=$2 ;;
        --sysroot) sysroot=$2 ;;
        --crtbegin) crtbegin=$2 ;;
        --crtend) crtend=$2 ;;
        --builtins) builtins=$2 ;;
        --runtime-unwind) runtime_unwind=$2 ;;
        --asan) asan=1; shift; continue ;;
        --asan-shared) asan_shared=$2 ;;
        --target-resource-dir) target_resource_dir=$2 ;;
        *) echo "unknown GNU libunwind build argument: $1" >&2; exit 2 ;;
    esac
    shift 2
done
for required in configure prefix unwind ptrace arch smoke smoke_source shared_smoke shared_smoke_source cc cxx ar ranlib nm ld make shell objdump build target sysroot crtbegin crtend builtins runtime_unwind; do
    eval "value=\${$required}"
    [ -n "$value" ] || { echo "missing GNU libunwind build argument: $required" >&2; exit 2; }
done

action_root=$(pwd -P)
absolute_path() {
    case "$1" in /*) printf '%s\n' "$1" ;; *) printf '%s/%s\n' "$action_root" "$1" ;; esac
}
for variable in configure prefix unwind ptrace arch smoke smoke_source shared_smoke shared_smoke_source cc cxx ar ranlib nm ld make shell objdump sysroot crtbegin crtend builtins runtime_unwind asan_shared target_resource_dir; do
    eval "value=\${$variable}"
    test -z "$value" || eval "$variable=\$(absolute_path \"\$value\")"
done

# The toolchain records these roots relative to the action execroot. Resolve
# them before configure enters either out-of-tree build directory; otherwise
# the compiler launcher would reinterpret them relative to that directory.
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

# Make every declared tool location absolute before entering an out-of-tree
# configure directory. No configure test is allowed to find a host program.
normalized_path=
old_ifs=$IFS
IFS=:
for entry in $PATH; do
    normalized_path="${normalized_path:+$normalized_path:}$(absolute_path "${entry:-.}")"
done
IFS=$old_ifs
PATH=$normalized_path
export PATH

case "$target" in
    x86_64-*) arch_name=x86_64; elf_format='elf64-x86-64' ;;
    aarch64-*) arch_name=aarch64; elf_format='elf64-littleaarch64' ;;
    *) echo "unsupported GNU libunwind target: $target" >&2; exit 2 ;;
esac

case "$target" in
    *-musl) crt_dir="$sysroot/usr/lib"; dynamic_linker="/lib/ld-musl-$arch_name.so.1" ;;
    x86_64-*) crt_dir="$sysroot/usr/lib64"; dynamic_linker="/lib64/ld-linux-x86-64.so.2" ;;
    aarch64-*) crt_dir="$sysroot/usr/lib64"; dynamic_linker="/lib/ld-linux-aarch64.so.1" ;;
esac
test -f "$crt_dir/crt1.o" && test -f "$crt_dir/crti.o" && test -f "$crt_dir/crtn.o"
resource_dir="$HERMETIC_LLVM_ROOT/lib/clang/20"
test -d "$resource_dir/include"
asan_compile_flags=
asan_link_input=
asan_probe_link_flags=
if [ "$asan" = 1 ]; then
    test -n "$asan_shared" && test -f "$asan_shared" && test -n "$target_resource_dir" && test -d "$target_resource_dir"
    asan_compile_flags="-fsanitize=address -fno-omit-frame-pointer -resource-dir=$target_resource_dir"
    asan_link_input=$asan_shared
    asan_probe_link_flags="$asan_compile_flags -shared-libasan $asan_shared"
fi

# Autoconf executes a link probe when --build and --host are equal. Retain the
# execution tuple's CPU/OS but use a synthetic vendor so all probes stay
# cross-only, including execution and target triples that otherwise coincide.
case "$build" in
    x86_64-*-linux-gnu) configure_build=x86_64-bazel-linux-gnu ;;
    aarch64-*-linux-gnu) configure_build=aarch64-bazel-linux-gnu ;;
    *) echo "unsupported GNU libunwind execution tuple: $build" >&2; exit 2 ;;
esac

rm -rf "$prefix" "${prefix}.build-one"
mkdir -p "$prefix"
build_one="${prefix}.build-one"

configure_one() {
    build_dir=$1
    mkdir -p "$build_dir"
    (
        cd "$build_dir"
        # The unequal --build/--host pair keeps feature tests compile-only,
        # including the nominally native x86_64 glibc configuration.
        CC="$cc --target=$target --sysroot=$sysroot -fuse-ld=lld --ld-path=$ld"
        CXX="$cxx --target=$target --sysroot=$sysroot -fuse-ld=lld --ld-path=$ld"
        AR="$ar" ARFLAGS=rcD RANLIB="$ranlib" NM="$nm" STRIP=:
        LD="$ld"
        CONFIG_SHELL="$shell"
        CFLAGS="-fPIC -O3 -g -nostdinc -isystem $resource_dir/include -isystem $sysroot/usr/include -resource-dir=$resource_dir -ffile-prefix-map=$action_root=/usr/src/libunwind -fdebug-prefix-map=$action_root=/usr/src/libunwind $asan_compile_flags"
        CXXFLAGS="-fPIC -D_GLIBCXX_USE_CXX11_ABI=0 -O3 -g -nostdinc -isystem $resource_dir/include -isystem $sysroot/usr/include -resource-dir=$resource_dir -ffile-prefix-map=$action_root=/usr/src/libunwind -fdebug-prefix-map=$action_root=/usr/src/libunwind $asan_compile_flags"
        LDFLAGS="-nostdlib -no-pie -L$crt_dir -Wl,--build-id=sha1 -Wl,--dynamic-linker=$dynamic_linker $crt_dir/crt1.o $crt_dir/crti.o $crtbegin $asan_probe_link_flags"
        LIBS="-lc $builtins $crtend $crt_dir/crtn.o"
        export CC CXX AR ARFLAGS RANLIB NM LD STRIP CONFIG_SHELL CFLAGS CXXFLAGS LDFLAGS LIBS
        if ! "$shell" "$configure" \
            "--build=$configure_build" "--host=$target" \
            --disable-shared --enable-static \
            --disable-minidebuginfo --disable-zlibdebuginfo --disable-tests; then
            tail -n 120 config.log >&2
            exit 1
        fi
        # LDFLAGS and LIBS close configure's executable probes. They must not
        # be inherited by libtool archive creation, where they would embed
        # compiler runtime archives inside the published GNU archives.
        "$make" -j1 LDFLAGS= LIBS=
    )
}

configure_one "$build_one"
for library in libunwind.a libunwind-ptrace.a "libunwind-$arch_name.a"; do
    first="$build_one/src/.libs/$library"
    [ -f "$first" ] || { echo "missing GNU libunwind archive: $library" >&2; exit 1; }
done

# Publish generated configuration headers with the CcInfo and preserve the
# exact Cargo build-script order in the three explicit archive outputs.
mkdir -p "$prefix/include"
source_include=${configure%/configure}/include
cp -RL "$source_include/." "$prefix/include/"
cp -RL "$build_one/include/." "$prefix/include/"
find "$prefix/include" -type f \( -name Makefile -o -name config.status -o -name config.log -o -name 'stamp-*' -o -name '*.in' \) -delete
cp "$build_one/src/.libs/libunwind.a" "$unwind"
cp "$build_one/src/.libs/libunwind-ptrace.a" "$ptrace"
cp "$build_one/src/.libs/libunwind-$arch_name.a" "$arch"
for library in "$unwind" "$ptrace" "$arch"; do
    if "$ar" t "$library" | grep -E '(^|/)([^/]*\.a|crt[^/]*\.o|[^/]*builtins[^/]*\.a|libc[^/]*\.(a|o))$' >/dev/null; then
        echo "embedded runtime payload in GNU libunwind output: $library" >&2
        exit 1
    fi
done

if [ "$asan" = 1 ]; then
    "$cc" --target="$target" --sysroot="$sysroot" -fuse-ld=lld --ld-path="$ld" \
        -fsanitize=address -fno-omit-frame-pointer -resource-dir="$target_resource_dir" -shared-libasan -no-pie -nostdinc -isystem "$resource_dir/include" -isystem "$sysroot/usr/include" -nostdlib \
        "$crt_dir/crt1.o" "$crt_dir/crti.o" "$crtbegin" -I"$prefix/include" "$smoke_source" -o "$smoke" -L"$crt_dir" \
        -Wl,--start-group "$unwind" "$ptrace" "$arch" "$runtime_unwind" -lpthread -ldl -lc "$builtins" -Wl,--end-group "$crtend" "$crt_dir/crtn.o" "$asan_shared"
else
    "$cc" --target="$target" --sysroot="$sysroot" -fuse-ld=lld --ld-path="$ld" \
        -no-pie -nostdinc -isystem "$resource_dir/include" -isystem "$sysroot/usr/include" -resource-dir="$resource_dir" -nostdlib -static \
        "$crt_dir/crt1.o" "$crt_dir/crti.o" "$crtbegin" -I"$prefix/include" "$smoke_source" -o "$smoke" -L"$crt_dir" \
        -Wl,--start-group "$unwind" "$ptrace" "$arch" "$runtime_unwind" -lpthread -ldl -lc "$builtins" -Wl,--end-group "$crtend" "$crt_dir/crtn.o"
fi
"$objdump" -f "$smoke" | grep "$elf_format" >/dev/null

"$cc" --target="$target" --sysroot="$sysroot" -fuse-ld=lld --ld-path="$ld" \
    -fPIC -nostdinc -isystem "$resource_dir/include" -isystem "$sysroot/usr/include" -resource-dir="$resource_dir" $asan_compile_flags -nostdlib -shared -Wl,-z,defs -Wl,--build-id=sha1 \
    -I"$prefix/include" "$shared_smoke_source" -o "$shared_smoke" -L"$crt_dir" \
    -Wl,--start-group "$unwind" "$ptrace" "$arch" "$runtime_unwind" -lpthread -ldl -lc "$builtins" -Wl,--end-group $asan_link_input
if "$objdump" -p "$shared_smoke" | grep TEXTREL >/dev/null; then
    echo "text relocations in GNU libunwind shared-link gate" >&2
    exit 1
fi

find "$prefix" -type d -exec chmod 0755 {} ';'
find "$prefix" -type f -exec chmod 0644 {} ';'
find "$prefix" -exec touch -h -d @0 {} ';'
touch -d @0 "$unwind" "$ptrace" "$arch" "$smoke" "$shared_smoke"
chmod 0755 "$smoke"
rm -rf "$build_one"
