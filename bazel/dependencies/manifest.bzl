"""Locked non-PHP inputs for hermetic Linux actions.

Only records with an upstream digest are exposed as fetchable inputs. The RPM
and APK sysroot closure is defined by `bazel/dependencies/sysroots`; actions
must consume that repository's extracted files and never invoke a package
manager.
"""

HERMETIC_TOOL_INPUTS = {
    "composer_2_2_25": struct(
        version = "2.2.25",
        url = "https://getcomposer.org/download/2.2.25/composer.phar",
        sha256 = "8b3f41253363f0645402d1951d6e7f02adedeef29c16de6074763e463e25c23f",
        provides = ("composer",),
    ),
    "llvm_20_1_4_linux_x64": struct(
        version = "20.1.4",
        url = "https://github.com/llvm/llvm-project/releases/download/llvmorg-20.1.4/LLVM-20.1.4-Linux-X64.tar.xz",
        sha256 = "113b54c397adb2039fa45e38dc8107b9ec5a0baead3a3bac8ccfbb65b2340caa",
        provides = ("clang", "clang++", "lld", "llvm-ar", "llvm-ranlib", "llvm-objcopy", "libclang", "compiler-rt"),
    ),
    "llvm_20_1_4_linux_arm64": struct(
        version = "20.1.4",
        url = "https://github.com/llvm/llvm-project/releases/download/llvmorg-20.1.4/LLVM-20.1.4-Linux-ARM64.tar.xz",
        sha256 = "4de80a332eecb06bf55097fd3280e1c69ed80f222e5bdd556221a6ceee02721a",
        provides = ("clang", "clang++", "lld", "llvm-ar", "llvm-ranlib", "llvm-objcopy", "libclang", "compiler-rt"),
    ),
    "cmake_3_24_4_linux_x86_64": struct(
        version = "3.24.4",
        url = "https://github.com/Kitware/CMake/releases/download/v3.24.4/cmake-3.24.4-linux-x86_64.tar.gz",
        sha256 = "cac77d28fb8668c179ac02c283b058aeb846fe2133a57d40b503711281ed9f19",
        provides = ("cmake", "ctest", "cpack"),
    ),
    "cmake_3_24_4_linux_aarch64": struct(
        version = "3.24.4",
        url = "https://github.com/Kitware/CMake/releases/download/v3.24.4/cmake-3.24.4-linux-aarch64.tar.gz",
        sha256 = "86f823f2636bf715af89da10e04daa476755a799d451baee66247846e95d7bee",
        provides = ("cmake", "ctest", "cpack"),
    ),
}

# Upstream archives used to build target PHP dependencies.  Each digest was
# calculated from the URL below during lock creation; repository setup is the
# only phase allowed to download these archives.  Platform package closures
# (including Apache/APR where supplied by the target distribution) remain in
# `sysroots`, rather than being silently discovered from an execution host.
PHP_THIRDPARTY_SOURCES = {
    "curl_7_61_1": struct(
        version = "7.61.1",
        url = "https://curl.se/download/curl-7.61.1.tar.gz",
        sha256 = "eaa812e9a871ea10dbe8e1d3f8f12a64a8e3e62aeab18cb23742e2f1727458ae",
        build_system = "configure_make",
        install_subdir = "curl",
        strip_prefix = "curl-7.61.1",
    ),
    "libffi_3_4_2": struct(
        version = "3.4.2",
        url = "https://github.com/libffi/libffi/releases/download/v3.4.2/libffi-3.4.2.tar.gz",
        sha256 = "540fb721619a6aba3bdeef7d940d8e9e0e6d2c193595bc243241b77ff9e93620",
        build_system = "configure_make",
        install_subdir = "libffi",
        strip_prefix = "libffi-3.4.2",
    ),
    "libxml2_2_9_10": struct(
        version = "2.9.10",
        url = "https://download.gnome.org/sources/libxml2/2.9/libxml2-2.9.10.tar.xz",
        sha256 = "593b7b751dd18c2d6abcd0c4bcb29efc203d0b4373a6df98e3a455ea74ae2813",
        build_system = "configure_make",
        install_subdir = "libxml2",
        strip_prefix = "libxml2-2.9.10",
    ),
    "libzip_1_10_1": struct(
        version = "1.10.1",
        url = "https://libzip.org/download/libzip-1.10.1.tar.gz",
        sha256 = "9669ae5dfe3ac5b3897536dc8466a874c8cf2c0e3b1fdd08d75b273884299363",
        build_system = "cmake",
        install_subdir = "libzip",
        strip_prefix = "libzip-1.10.1",
    ),
    "oniguruma_6_9_5_rev1": struct(
        version = "6.9.5_rev1",
        url = "https://github.com/kkos/oniguruma/releases/download/v6.9.5_rev1/onig-6.9.5-rev1.tar.gz",
        sha256 = "d33c849d1672af227944878cefe0a8fcf26fc62bedba32aa517f2f63c314a99e",
        build_system = "configure_make",
        install_subdir = "oniguruma",
        strip_prefix = "onig-6.9.5",
    ),
    "openssl_1_1_1w": struct(
        version = "1.1.1w",
        url = "https://www.openssl.org/source/openssl-1.1.1w.tar.gz",
        sha256 = "cf3098950cb4d853ad95c0841f1f9c6d3dc102dccfcacd521d93925208b76ac8",
        build_system = "openssl_config_make",
        install_subdir = "openssl",
        strip_prefix = "openssl-1.1.1w",
    ),
    "sqlite_3_46_0": struct(
        version = "3.46.0",
        url = "https://www.sqlite.org/2024/sqlite-autoconf-3460000.tar.gz",
        sha256 = "6f8e6a7b335273748816f9b3b62bbdc372a889de8782d7f048c653a447417a7d",
        build_system = "configure_make",
        install_subdir = "sqlite3",
        strip_prefix = "sqlite-autoconf-3460000",
    ),
    "zlib_1_2_11": struct(
        version = "1.2.11",
        url = "https://zlib.net/fossils/zlib-1.2.11.tar.gz",
        sha256 = "c3e5e9fdd5004dcb542feda5ee4f0ff0744628baf8ed2dd5d66f8ca1197cb1a1",
        build_system = "configure_make",
        install_subdir = "zlib",
        strip_prefix = "zlib-1.2.11",
    ),
}

# Exact Composer development graph used only to generate the PHP bridge
# preload files.  The checked-in tooling/generation/composer.lock records the
# same source revisions.  `generation_vendor` fetches these codeload archives
# with their content hashes during repository setup and writes a minimal,
# deterministic Composer-compatible autoloader; no Composer process or
# network access is permitted in a build action.
GENERATION_COMPOSER_PACKAGES = {
    "classpreloader_classpreloader_4_2_0": struct(
        package = "classpreloader/classpreloader",
        version = "4.2.0",
        url = "https://codeload.github.com/ClassPreloader/ClassPreloader/zip/af9284543aedb45ed58359374918141c0ac7ae34",
        sha256 = "aed1d746c35e6d038629404b28f757d5379df986191e9e5cb551362cb8bc4168",
        strip_prefix = "ClassPreloader-af9284543aedb45ed58359374918141c0ac7ae34",
    ),
    "classpreloader_console_3_1_0": struct(
        package = "classpreloader/console",
        version = "3.1.0",
        url = "https://codeload.github.com/ClassPreloader/Console/zip/8475b97e5f69513ff9d68387d4da83a37afb6b71",
        sha256 = "d087914cc5f0306f47fc9543d13c579636b9c66ba6477581607d50afa9e57aba",
        strip_prefix = "Console-8475b97e5f69513ff9d68387d4da83a37afb6b71",
    ),
    "nikic_php_parser_4_19_5": struct(
        package = "nikic/php-parser",
        version = "v4.19.5",
        url = "https://codeload.github.com/nikic/PHP-Parser/zip/51bd93cc741b7fc3d63d20b6bdcd99fdaa359837",
        sha256 = "f8cafd95c1d3d7bf2e40443288e93fa973127f2de7974f7cbe24249e5c18061e",
        strip_prefix = "PHP-Parser-51bd93cc741b7fc3d63d20b6bdcd99fdaa359837",
    ),
    "psr_log_1_1_4": struct(
        package = "psr/log",
        version = "1.1.4",
        url = "https://codeload.github.com/php-fig/log/zip/d49695b909c3b7628b6289db5479a1c204601f11",
        sha256 = "af5583b8b9ff6c17dbb906ad3e2e540bdef8199ddbd98dc8adcc34f41ba4378b",
        strip_prefix = "log-d49695b909c3b7628b6289db5479a1c204601f11",
    ),
    "symfony_console_3_4_47": struct(
        package = "symfony/console",
        version = "v3.4.47",
        url = "https://codeload.github.com/symfony/console/zip/a10b1da6fc93080c180bba7219b5ff5b7518fe81",
        sha256 = "655019ea2192173941d9bb0de82f9f9d3fcbb73a084e5045a43a133733cada91",
        strip_prefix = "console-a10b1da6fc93080c180bba7219b5ff5b7518fe81",
    ),
    "symfony_debug_3_4_47": struct(
        package = "symfony/debug",
        version = "v3.4.47",
        url = "https://codeload.github.com/symfony/debug/zip/ab42889de57fdfcfcc0759ab102e2fd4ea72dcae",
        sha256 = "925d4d2bfbdfb3a66acc6960611f85041e141fb6be23f6f6793f19f21b6509df",
        strip_prefix = "debug-ab42889de57fdfcfcc0759ab102e2fd4ea72dcae",
    ),
    "symfony_polyfill_ctype_1_27_0": struct(
        package = "symfony/polyfill-ctype",
        version = "v1.27.0",
        url = "https://codeload.github.com/symfony/polyfill-ctype/zip/5bbc823adecdae860bb64756d639ecfec17b050a",
        sha256 = "d96a0f61ffd52a62d44d86d07ae7e8e9307b69979f08260012ca682b54e1e972",
        strip_prefix = "polyfill-ctype-5bbc823adecdae860bb64756d639ecfec17b050a",
    ),
    "symfony_polyfill_mbstring_1_27_0": struct(
        package = "symfony/polyfill-mbstring",
        version = "v1.27.0",
        url = "https://codeload.github.com/symfony/polyfill-mbstring/zip/8ad114f6b39e2c98a8b0e3bd907732c207c2b534",
        sha256 = "33c6bdb418c96ade9ec1d75b046123e25a8e8e637bb23e206230632ef630cc3a",
        strip_prefix = "polyfill-mbstring-8ad114f6b39e2c98a8b0e3bd907732c207c2b534",
    ),
}

# Validation only.  This is not a PHP toolchain input and must never satisfy
# PhpToolchainInfo.host_php in a production action.  It allows the locked
# generator closure to be exercised before source-built host PHP is available.
GENERATION_VALIDATION_PHP = struct(
    version = "8.4.21",
    url = "https://github.com/publicala/php-ci-static/releases/download/php-8.4.21/php-linux-x86_64",
    sha256 = "3b8ef945acf305773faf874242353304b8ddc4c595eb5232e9dce80770b25a84",
    provenance = "https://github.com/publicala/php-ci-static/releases/download/php-8.4.21/SHA256SUMS",
)

# These are source identities, not archive downloads.  Repository setup must
# initialize them at exactly these gitlink revisions before Cargo resolves.
GITLINK_INPUTS = {
    "libdatadog": struct(
        revision = "817cf820879a76fdd8eeb3ecb47e04e8f8ecbd6c",
        url = "https://codeload.github.com/DataDog/libdatadog/tar.gz/817cf820879a76fdd8eeb3ecb47e04e8f8ecbd6c",
        sha256 = "2cb686922bf292fb7e25f88d44d0370866f1c0530928e14f97744a07f32b12bb",
        strip_prefix = "libdatadog-817cf820879a76fdd8eeb3ecb47e04e8f8ecbd6c",
        path = "libdatadog",
        nested = (struct(
            path = "libdd-ffe-test-suite/ffe-system-test-data",
            revision = "2c0c8d55f665fe4a847fc0ded3abf59b7e2896fc",
            url = "https://codeload.github.com/DataDog/ffe-system-test-data/tar.gz/2c0c8d55f665fe4a847fc0ded3abf59b7e2896fc",
            sha256 = "697fa01af3ebb126d4f7bd5fa5e1d2dd9c3cae7c419dcab98283072ffa699b23",
            strip_prefix = "ffe-system-test-data-2c0c8d55f665fe4a847fc0ded3abf59b7e2896fc",
        ),),
    ),
    "libddwaf_rust": struct(
        revision = "e9251b149bce81f004d31bc4e1cfc9fa3302a952",
        url = "https://codeload.github.com/DataDog/libddwaf-rust/tar.gz/e9251b149bce81f004d31bc4e1cfc9fa3302a952",
        sha256 = "872b7d29d06e3d84e861c65345543a2dc712f038ab08023a930d943fd30bf35f",
        strip_prefix = "libddwaf-rust-e9251b149bce81f004d31bc4e1cfc9fa3302a952",
        path = "appsec/third_party/libddwaf-rust",
        nested = (),
    ),
}
