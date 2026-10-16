# PHP header SDK snapshots from OCI images

This repository adapter imports installed PHP profiles from immutable OCI image
layers. A lock identifies the image index, selected platform manifest, config,
and the exact compressed layer digest and size. Tags are retained only as
provenance because the CI image tags are mutable.

The importer accepts explicit one-layer profile snapshots. Most CI images use a
self-contained `COPY /opt/php/<profile>` layer (`profile_copy_snapshot`). The
Alpine extension compiler creates both installed PHP prefixes in one
`install-php` layer (`profile_layer_snapshot`). Neither mode claims to reproduce
the final image root filesystem: later layers can modify the profile. These
imports deliberately publish headers only, so later runtime-extension changes
do not affect their contract.

Repository setup downloads the layer with its SHA-256 digest. On Docker Hub, a
checksum-keyed cache lookup is attempted before anonymous authentication, so a
prefetched repository cache works offline. Extraction uses the pinned Python
selected by the module extension. Build actions receive only normalized files.

Every import temporarily extracts `bin/php` to verify the embedded PHP version,
numeric version, PHP module API, Zend module/extension APIs, debug/ZTS mode,
ASan linkage, and ELF architecture.
The binary is then deleted. Extraction rejects path traversal,
special files, duplicate selected paths, links outside the selected profile,
and whiteouts that affect that profile. Modes and timestamps are normalized.

Each generated repository exports:

* `:all`, `:runtime`, `:headers`, `:libs`, `:extensions`, and `:config`
* `:sdk_root`, a marker at `normalized/sdk/.root`
* `:lock`, `:provenance`, and `:observed`

Headers are normalized under `normalized/sdk/include/php`. Header-only imports
leave all runtime groups empty. The lock records the exact embedded patch
version while accepting any version in the requested minor with the same PHP
API and build ABI. Target compilation still uses the separately locked CentOS 7
glibc 2.17 or Alpine 3.22 sysroot; libraries from these discovery images are not
imported.

`generate_locks.py` is a maintainer tool. It reads registry metadata through an
explicit `--crane` executable and checks in the exact image/index, platform
manifest, and config bytes. Bazel does not run the generator or trust mutable
tags. It also writes `records.bzl`, the analysis-time view consumed by matrix
rules; `images.json` remains the source of truth. The 223 distinct imports
reference 201 unique compressed profile layers
(15,759,556,331 bytes). A compatible current Alpine PHP 8.0 arm64 NTS SDK is
reused for the legacy NTS matrix entry, so imported SDKs directly cover 224 of
226 matrix entries. The public legacy Alpine image has no arm64 manifest; its
arm64 debug and debug-ZTS SDKs are derived from the matching current arm64
NTS/ZTS header sets by a declared `ZEND_DEBUG=0` to `1` transform. Native ARM
musl compile and ABI layout checks pass, but no matching legacy ARM debug
runtime exists in the locked inventory, so extension-load coverage is not
claimed for those two profiles.

To prove that repository setup itself works offline, first run one online query
with a dedicated `--repository_cache` and `--repo_contents_cache=`. Then point
`validate_offline.sh` at a fresh output root, the populated archive cache, `-`
for its contents-cache argument, and the external SDK target. The script creates
a network-disabled bubblewrap namespace. Using `-` ensures the check reconstructs
the repository from transitive archives rather than reusing a normalized
repository tree.
