"""Pinned PHP source variants and ABI metadata from the Linux CI inputs.

`release` follows CentOS 7. `bookworm` follows the Bookworm development image.
Alpine extension images have their own source pins. These records must never
be collapsed: PHP 8.5 is 8.5.7 versus 8.5.8RC1 and Alpine 8.1 is 8.1.31
versus 8.1.32.
"""

PHP_SOURCES = {
    "php_7_0": struct(version = "7.0.33", api = 20151012, url = "https://www.php.net/distributions/php-7.0.33.tar.gz", sha256 = "d71a6ecb6b13dc53fed7532a7f8f949c4044806f067502f8fb6f9facbb40452a"),
    "php_7_1": struct(version = "7.1.33", api = 20160303, url = "https://www.php.net/distributions/php-7.1.33.tar.gz", sha256 = "0055f368ffefe51d5a4483755bd17475e88e74302c08b727952831c5b2682ea2"),
    "php_7_2": struct(version = "7.2.34", api = 20170718, url = "https://www.php.net/distributions/php-7.2.34.tar.gz", sha256 = "8b2777c741e83f188d3ca6d8e98ece7264acafee86787298fae57e05d0dddc78"),
    "php_7_3": struct(version = "7.3.33", api = 20180731, url = "https://www.php.net/distributions/php-7.3.33.tar.gz", sha256 = "9a369c32c6f52036b0a890f290327f148a1904ee66aa56e2c9a7546da6525ec8"),
    "php_7_4": struct(version = "7.4.33", api = 20190902, url = "https://www.php.net/distributions/php-7.4.33.tar.gz", sha256 = "5a2337996f07c8a097e03d46263b5c98d2c8e355227756351421003bea8f463e"),
    "php_8_0": struct(version = "8.0.30", api = 20200930, url = "https://www.php.net/distributions/php-8.0.30.tar.gz", sha256 = "449d2048fcb20a314d8c218097c6d1047a9f1c5bb72aa54d5d3eba0a27a4c80c"),
    "php_8_1": struct(version = "8.1.32", api = 20210902, url = "https://www.php.net/distributions/php-8.1.32.tar.gz", sha256 = "4846836d1de27dbd28e89180f073531087029a77e98e8e019b7b2eddbdb1baff"),
    "php_8_2": struct(version = "8.2.31", api = 20220829, url = "https://www.php.net/distributions/php-8.2.31.tar.gz", sha256 = "083c2f61cc5f527eb293c4c468a91af46a9678785957e023b2796a9db290d870"),
    "php_8_3": struct(version = "8.3.31", api = 20230831, url = "https://www.php.net/distributions/php-8.3.31.tar.gz", sha256 = "4e7baaf0a690e954a20e7ced3dd633ce8cb8094e2b6b612a55e703ecbbdcbf4f"),
    "php_8_4": struct(version = "8.4.22", api = 20240924, url = "https://www.php.net/distributions/php-8.4.22.tar.gz", sha256 = "a012c2c9724baf214a70b41b40a7e130906b8855e54268afa5bc4ae17bc9d823"),
    "php_8_5_release": struct(version = "8.5.7", api = 20250925, url = "https://www.php.net/distributions/php-8.5.7.tar.gz", sha256 = "e5eba93fd6dd3241d0e61e932eb99a3783b40568553fb0e511b660ecd863a049"),
    "php_8_5_bookworm": struct(version = "8.5.8RC1", api = 20250925, url = "https://downloads.php.net/~daniels/php-8.5.8RC1.tar.gz", sha256 = "57f93d2e0d76a26ac955e30cfab81dd910fc48e8bf78b3a15ff67df63a92ac72"),
    # Source-only CI variants retained for exact inventory equivalence.
    "php_8_0_alpine_legacy": struct(version = "8.0.15", api = 20200930, url = "https://www.php.net/distributions/php-8.0.15.tar.gz", sha256 = "47f0be6188b05390bb457eb1968ea19463acada79650afc35ec763348d5c2370"),
    "php_8_1_alpine": struct(version = "8.1.31", api = 20210902, url = "https://www.php.net/distributions/php-8.1.31.tar.gz", sha256 = "618923b407c4575bfee085f00c4aaa16a5cc86d4b1eb893c0f352d61541bbfb1"),
}

PHP_RELEASES = {
    "7.0": PHP_SOURCES["php_7_0"], "7.1": PHP_SOURCES["php_7_1"], "7.2": PHP_SOURCES["php_7_2"],
    "7.3": PHP_SOURCES["php_7_3"], "7.4": PHP_SOURCES["php_7_4"], "8.0": PHP_SOURCES["php_8_0"],
    "8.1": PHP_SOURCES["php_8_1"], "8.2": PHP_SOURCES["php_8_2"], "8.3": PHP_SOURCES["php_8_3"],
    "8.4": PHP_SOURCES["php_8_4"], "8.5": PHP_SOURCES["php_8_5_release"],
}

NORMAL_PHP_VERSIONS = tuple(PHP_RELEASES.keys())
ASAN_PHP_VERSIONS = ("7.4", "8.0", "8.1", "8.2", "8.3", "8.4", "8.5")
NTS_ASAN_PHP_VERSIONS = ("8.3", "8.4", "8.5")
PROFILER_PHP_VERSIONS = tuple([version for version in PHP_RELEASES.keys() if version != "7.0"])

def source_key(minor, runtime_profile):
    if minor == "8.0" and runtime_profile == "alpine-legacy":
        return "php_8_0_alpine_legacy"
    if minor == "8.1" and runtime_profile == "alpine":
        return "php_8_1_alpine"
    if minor == "8.5":
        return "php_8_5_bookworm" if runtime_profile == "bookworm" else "php_8_5_release"
    return "php_{}".format(minor.replace(".", "_"))
