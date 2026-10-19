#!/bin/sh
set -eu

execroot=$PWD
absolute() {
    case "$1" in
        /*) printf '%s\n' "$1" ;;
        *) printf '%s/%s\n' "$execroot" "$1" ;;
    esac
}

out=$(absolute "$1")

{
    make --version
    cmake --version
    cmake -E env true
    autoconf --version
    clang --version
    resource_dir=$(clang -print-resource-dir)
    [ -f "$resource_dir/include/stddef.h" ]
    printf 'Clang resource directory: %s\n' "$resource_dir"
    perl -Mstrict -MConfig -e '
        my $root = $ENV{HERMETIC_TOOLS_ROOT};
        die "tools root is not absolute\n" unless defined($root) && substr($root, 0, 1) eq "/";
        for my $path (@INC, values %INC) {
            next unless defined($path) && length($path);
            die "non-hermetic Perl path: $path\n" unless index($path, $root . "/") == 0;
        }
    '
    sh -c 'cd "$(dirname "$SHELL")"; "$SHELL" -c true'
    python3 -c 'import subprocess; subprocess.run(["tar", "--version"], check=True); subprocess.run(["autoconf", "--version"], check=True)'
} > "$out"
