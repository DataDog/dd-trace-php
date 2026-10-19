set -eu

preflight=$1
shift
. "$preflight"

php_loader=$1
php_library_path=$2
php=$3
loader_extension=$4
version_file=$5
expected_arch=$6
marker=$7

case "$loader_extension" in /*) ;; *) loader_extension=$PWD/$loader_extension ;; esac
case "$version_file" in /*) ;; *) version_file=$PWD/$version_file ;; esac

preflight_host_php "$php_loader" "$php_library_path" "$php" "$php_library_path"
preflight_host_php "$php_loader" "$php_library_path" "$loader_extension" "$php_library_path"

package="$marker.package"
mkdir -p "$package"
DD_LOADER_PACKAGE_PATH="$package" \
    "$php_loader" --inhibit-cache --library-path "$php_library_path" \
    "$php" -n -d "zend_extension=$loader_extension" -r '
        $version = trim(file_get_contents($argv[1]));
        if (!in_array("dd_library_loader", get_loaded_extensions(true), true)) {
            fwrite(STDERR, "loader is absent from the Zend extension inventory\n");
            exit(1);
        }
        if (!extension_loaded("dd_library_loader_mod")) {
            fwrite(STDERR, "loader module was not registered\n");
            exit(2);
        }
        if (phpversion("dd_library_loader_mod") !== $version) {
            fwrite(STDERR, "loader module version differs from VERSION\n");
            exit(3);
        }
        foreach (["ddtrace", "ddappsec", "datadog-profiling"] as $product) {
            if (extension_loaded($product)) {
                fwrite(STDERR, "loader smoke unexpectedly loaded $product\n");
                exit(4);
            }
        }
    ' -- "$version_file"
rm -rf "$package"

actual_arch=$(uname -m)
case "$expected_arch:$actual_arch" in
    amd64:x86_64|arm64:aarch64) ;;
    *) echo "loader smoke platform mismatch: expected $expected_arch, got $actual_arch" >&2; exit 1 ;;
esac

printf 'Datadog loader %s loaded into PHP on %s\n' "$(cat "$version_file")" "$expected_arch" > "$marker"
