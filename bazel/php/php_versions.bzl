"""Pinned PHP releases, hashes, and ABI metadata from existing Linux CI."""

PHP_RELEASES = {
    "7.0": struct(version = "7.0.33", api = 20151012, sha256 = "d71a6ecb6b13dc53fed7532a7f8f949c4044806f067502f8fb6f9facbb40452a"),
    "7.1": struct(version = "7.1.33", api = 20160303, sha256 = "0055f368ffefe51d5a4483755bd17475e88e74302c08b727952831c5b2682ea2"),
    "7.2": struct(version = "7.2.34", api = 20170718, sha256 = "8b2777c741e83f188d3ca6d8e98ece7264acafee86787298fae57e05d0dddc78"),
    "7.3": struct(version = "7.3.33", api = 20180731, sha256 = "9a369c32c6f52036b0a890f290327f148a1904ee66aa56e2c9a7546da6525ec8"),
    "7.4": struct(version = "7.4.33", api = 20190902, sha256 = "5a2337996f07c8a097e03d46263b5c98d2c8e355227756351421003bea8f463e"),
    "8.0": struct(version = "8.0.30", api = 20200930, sha256 = "449d2048fcb20a314d8c218097c6d1047a9f1c5bb72aa54d5d3eba0a27a4c80c"),
    "8.1": struct(version = "8.1.32", api = 20210902, sha256 = "4846836d1de27dbd28e89180f073531087029a77e98e8e019b7b2eddbdb1baff"),
    "8.2": struct(version = "8.2.31", api = 20220829, sha256 = "083c2f61cc5f527eb293c4c468a91af46a9678785957e023b2796a9db290d870"),
    "8.3": struct(version = "8.3.31", api = 20230831, sha256 = "4e7baaf0a690e954a20e7ced3dd633ce8cb8094e2b6b612a55e703ecbbdcbf4f"),
    "8.4": struct(version = "8.4.22", api = 20240924, sha256 = "a012c2c9724baf214a70b41b40a7e130906b8855e54268afa5bc4ae17bc9d823"),
    "8.5": struct(version = "8.5.7", api = 20250925, sha256 = "e5eba93fd6dd3241d0e61e932eb99a3783b40568553fb0e511b660ecd863a049"),
}

NORMAL_PHP_VERSIONS = tuple(PHP_RELEASES.keys())
ASAN_PHP_VERSIONS = tuple([version for version in PHP_RELEASES.keys() if version not in ["7.0", "7.1", "7.2", "7.3"]])
PROFILER_PHP_VERSIONS = tuple([version for version in PHP_RELEASES.keys() if version != "7.0"])
