#!/bin/sh
set -eu

manifest=${1:?usage: check-matrix.sh path/to/matrix.json}

jq -e '
  .schema_version == 1 and
  (.records | length == 226) and
  ([.records[].name] | unique | length == 226) and
  (all(.records[] | select(.supported); (.expected_artifacts | length > 0))) and
  (all(.records[]; (.expected_artifacts | all(test("^sdk/"))))) and
  (all(.records[]; [
    "sdk/bin/php-config",
    "sdk/include/php/Zend/zend.h",
    "sdk/include/php/main/php.h",
    "sdk/include/php/main/php_config.h",
    "sdk/include/php/main/php_version.h",
    "sdk/metadata/import.lock.json",
    "sdk/metadata/observed.json",
    "sdk/metadata/provenance.json"
  ] - .expected_artifacts | length == 0)) and
  (all(.records[]; (.source_sha256 | test("^[0-9a-f]{64}$")) and (.source_url | test("^https://(www.php.net/distributions|downloads.php.net/~daniels)/php-")))) and
  (all(.records[]; .api == ({"7.0":20151012,"7.1":20160303,"7.2":20170718,"7.3":20180731,"7.4":20190902,"8.0":20200930,"8.1":20210902,"8.2":20220829,"8.3":20230831,"8.4":20240924,"8.5":20250925}[.minor]))) and
  (any(.records[]; (.source_key == "php_8_0_alpine_legacy") and (.version == "8.0.15"))) and
  (any(.records[]; (.source_key == "php_8_1_alpine") and (.version == "8.1.31"))) and
  (any(.records[]; (.source_key == "php_8_5_release") and (.version == "8.5.7"))) and
  (any(.records[]; (.source_key == "php_8_5_bookworm") and (.version == "8.5.8RC1"))) and
  (all(.records[]; (.sapis | length == 0) and (.bundled_extensions | length == 0) and (.shared_extensions | length == 0))) and
  (all(.records[] | select((.runtime_profile == "bookworm") and (.minor == "7.4") and (.name | endswith("_shared"))); (.historical_shared_extensions | sort) == ["ffi", "json", "mbstring", "pcntl"])) and
  (all(.records[] | select((.runtime_profile == "bookworm") and (.minor == "8.0") and (.name | endswith("_shared"))); (.historical_shared_extensions | sort) == ["ffi", "mbstring", "pcntl"]) ) and
  (all(.records[] | select((.runtime_profile == "alpine") and (.minor != "7.0") and (.minor != "7.1")); (.historical_shared_extensions | index("sodium")) != null)) and
  (all(.records[] | select(.runtime_profile == "alpine"); ((.historical_sapis | index("php-cgi")) == null) and ((.historical_sapis | index("cgi")) != null) and ((.configure_args | index("--without-openssl")) != null))) and
  (all(.records[] | select((.runtime_profile == "bookworm") and (.minor == "7.0")); (.compiler_flags | index("-DHAVE_POSIX_READDIR_R=1")) != null)) and
  (all(.records[] | select((.runtime_profile == "alpine") and (.minor | tonumber < 7.4)); (.compiler_flags | index("-D__off64_t=ssize_t")) != null)) and
  (all(.records[] | select((.runtime_profile == "alpine") and (.minor | tonumber >= 7.4) and (.minor | tonumber <= 8.1)); (.compiler_flags | index("-Doff64_t=ssize_t")) != null)) and
  (all(.records[] | select(.runtime_profile == "alpine"); (.source_edits | length == 1) and (.source_edits[0].kind == "replace_if_present"))) and
  (all(.records[] | select((.runtime_profile == "alpine") and (.minor == "7.0")); (.patches[0] | endswith("0001-Backport-0a39890c-Fix-libxml2-2.12-build-due-to-API-.patch")))) and
  (all(.records[] | select(.sanitizer == "asan"); (.historical_sapis | index("apache2handler")) == null)) and
  (all(.records[]; all(.products[]; (.supported or (.reason | length > 0)))))
' "$manifest" >/dev/null

expect_profiles() {
  runtime=$1 minor=$2 arch=$3 libc=$4 shared=$5 expected=$6
  jq -e --arg runtime "$runtime" --arg minor "$minor" --arg arch "$arch" --arg libc "$libc" --arg shared "$shared" --arg expected "$expected" '
    [.records[] | select(
      .runtime_profile == $runtime and .minor == $minor and .arch == $arch and .libc == $libc and
      (($shared == "yes") == (.name | endswith("_shared")))
    ) | .profile] | sort == ($expected | split(" ") | sort)
  ' "$manifest" >/dev/null
}

for minor in 7.0 7.1 7.2 7.3 7.4 8.0 8.1 8.2 8.3 8.4 8.5; do
  case "$minor" in
    7.0|7.1|7.2|7.3) bookworm='debug-zts debug nts zts' ;;
    7.4|8.0|8.1|8.2) bookworm='debug nts zts debug-zts-asan' ;;
    *) bookworm='debug nts zts debug-zts-asan nts-asan' ;;
  esac
  for arch in amd64 arm64; do
    expect_profiles bookworm "$minor" "$arch" glibc no "$bookworm"
    expect_profiles release "$minor" "$arch" glibc no 'debug nts zts'
    expect_profiles alpine "$minor" "$arch" musl no 'nts zts'
    if [ "$minor" = 7.4 ] || [ "$minor" = 8.0 ]; then
      expect_profiles bookworm "$minor" "$arch" glibc yes "$bookworm"
    fi
  done
done
for arch in amd64 arm64; do
  expect_profiles alpine-legacy 8.0 "$arch" musl no 'debug-zts debug nts'
done

printf '%s\n' "matrix inventory is complete: 226 PHP header SDK records; CI runtime settings retained as provenance"
