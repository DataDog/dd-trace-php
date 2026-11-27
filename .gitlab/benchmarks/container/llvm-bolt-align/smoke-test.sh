#!/bin/sh
set -eu

bolt_align=$1
test_dir=$(mktemp -d)
trap 'rm -rf "$test_dir"' EXIT
cd "$test_dir"

cat > input.c <<'EOF'
__attribute__((noinline)) int first(int value) {
#if VARIATION
    __asm__ volatile(".rept 32\nnop\n.endr");
#endif
    return value + 1;
}
__attribute__((noinline)) int second(int value) {
    return first(value) + 1;
}
int main(void) {
    return second(40) != 42;
}
EOF

cat > load.c <<'EOF'
#include <dlfcn.h>
int main(int argc, char **argv) {
    if (argc != 2) return 1;
    void *handle = dlopen(argv[1], RTLD_NOW);
    if (!handle) return 2;
    int (*second)(int) = (int (*)(int))dlsym(handle, "second");
    int result = !second || second(40) != 42;
    dlclose(handle);
    return result;
}
EOF
cc load.c -o load -ldl

# Cover both executables and PHP-style dynamically loaded shared libraries.
for kind in executable shared; do
    link_flags=
    if [ "$kind" = shared ]; then
        link_flags='-fPIC -shared'
    fi
    for variation in 0 1; do
        cc -O2 -fno-reorder-blocks-and-partition -fno-toplevel-reorder \
            -DVARIATION="$variation" $link_flags -Wl,--emit-relocs \
            input.c -o "$kind.$variation"
    done
    "$bolt_align" "$kind.0" "$kind.1" \
        -o-a="$kind.0.aligned" -o-b="$kind.1.aligned" > "$kind.log" 2>&1 \
        || { cat "$kind.log"; exit 1; }
    cat "$kind.log"
    if grep -q 'missed offset' "$kind.log"; then
        exit 1
    fi
    for variation in 0 1; do
        nm -n --defined-only "$kind.$variation.aligned" \
            | awk '$3 ~ /^(first|second|main)$/ { print $1, $3 }' \
            > "$kind.$variation.symbols"
        test "$(wc -l < "$kind.$variation.symbols")" -eq 3
        if [ "$kind" = shared ]; then
            ./load "./$kind.$variation.aligned"
        else
            "./$kind.$variation.aligned"
        fi
    done
    diff -u "$kind.0.symbols" "$kind.1.symbols"
done

# Exercise the backported PLT fix with mixed JUMP_SLOT/IRELATIVE relocations.
if [ "$(uname -m)" = x86_64 ]; then
    cat > plt-library.c <<'EOF'
int long_name(void) { return 0; }
EOF
    cat > plt-main.c <<'EOF'
extern int long_name(void);
__attribute__((target_clones("default,avx2"))) int foo(int x) { return x + 1; }
int main(void) { return foo(1) != 2 || long_name() != 0; }
EOF
    cc -fPIC -shared plt-library.c -o libexample.so
    cc -O2 -fno-pie -no-pie -fuse-ld=bfd -Wl,--emit-relocs \
        '-Wl,-rpath,$ORIGIN' plt-main.c -L. -lexample -o plt-main
    "$bolt_align" plt-main plt-main -o-a=plt-main.a -o-b=plt-main.b \
        > plt.log 2>&1 || { cat plt.log; exit 1; }
    cat plt.log
    for binary in plt-main plt-main.a plt-main.b; do
        readelf -Wr "$binary" | awk '
            /^Relocation section/ { in_plt = $3 ~ /[.]rela[.]plt/ }
            in_plt && $3 ~ /^R_X86_64_/ {
                print $3, ($3 == "R_X86_64_JUMP_SLOT" ? $5 : "")
            }
        ' > "$binary.plt"
        grep -q R_X86_64_JUMP_SLOT "$binary.plt"
        grep -q R_X86_64_IRELATIVE "$binary.plt"
        "./$binary"
    done
    diff -u plt-main.plt plt-main.a.plt
    diff -u plt-main.plt plt-main.b.plt
fi
