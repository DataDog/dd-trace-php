"""Reviewed native profiler archives for representative PHP configurations."""

load(":profiler_product.bzl", "configured_rust_profiler_target")

_REVIEWED_PRODUCTS = {
    "rust_profiler_php71_amd64_glibc_nts": struct(
        matrix_platform = "linux-amd64-glibc",
        php = "//bazel/php:php_7_1_amd64_glibc_bookworm_nts",
    ),
    "rust_profiler_php71_amd64_glibc_debug_zts": struct(
        matrix_platform = "linux-amd64-glibc",
        php = "//bazel/php:php_7_1_amd64_glibc_bookworm_debug_zts",
    ),
    "rust_profiler_php85_arm64_glibc_nts": struct(
        matrix_platform = "linux-arm64-glibc",
        php = "//bazel/php:php_8_5_arm64_glibc_bookworm_nts",
    ),
    "rust_profiler_php85_amd64_musl_nts": struct(
        matrix_platform = "linux-amd64-musl",
        php = "//bazel/php:php_8_5_amd64_musl_alpine_nts",
    ),
}

def profiler_matrix_targets():
    """Declares only the representative profiler archives with accepted gates."""
    for name, product in _REVIEWED_PRODUCTS.items():
        configured_rust_profiler_target(
            matrix_platform = product.matrix_platform,
            name = name,
            php = product.php,
        )
