"""Mandatory invariants for generated production Cargo graphs."""

load(":product_graphs.bzl", "PRODUCT_GRAPHS", "PRODUCT_REGISTRY_PACKAGES", "PRODUCT_UNIT_FEATURES")

_TRACER_COMMON_FEATURES = [
    "cgroup_testing",
    "default",
    "http-client",
    "https",
    "hyper-proxy",
    "hyper-rustls",
    "require-regex-full",
    "rustls",
    "rustls-native-certs",
    "rustls-platform-verifier",
    "tls-core",
    "tokio-rustls",
]

_PROFILER_COMMON_FEATURES = _TRACER_COMMON_FEATURES + ["reqwest", "test-utils"]

def _assert_features(product, package, expected):
    for triple, features in PRODUCT_GRAPHS[product][package]["features"].items():
        if sorted(features) != sorted(expected):
            fail("%s %s features drifted on %s: got %s, want %s" % (product, package, triple, sorted(features), sorted(expected)))

def assert_product_feature_resolution():
    """Fails package loading when production resolution gains test/crypto edges."""
    _assert_features("tracer", "libdatadog/libdd-common", _TRACER_COMMON_FEATURES)
    _assert_features("tracer", "", ["tracer"])
    _assert_features("profiler", "libdatadog/libdd-common", _PROFILER_COMMON_FEATURES)
    _assert_features("profiler", "", ["io_profiling", "profiling"])
    _assert_features("profiler", "libdatadog/libdd-alloc", [])
    _assert_features("profiler", "libdatadog/libdd-profiling", ["default"])
    _assert_features("profiler", "libdatadog/libdd-profiling-protobuf", ["prost_impls"])

    tracer_tokio = PRODUCT_UNIT_FEATURES["tracer"]["tokio-1.49.0"]
    if "taskdump" not in tracer_tokio["target"] or "backtrace-0.3.74" not in tracer_tokio["target_deps"]:
        fail("tracer target Tokio unit lost tokio_unstable taskdump/backtrace resolution")
    if "taskdump" in tracer_tokio["exec"] or "backtrace-0.3.74" in tracer_tokio["exec_deps"]:
        fail("tracer exec Tokio unit gained target-only tokio_unstable resolution")

    for product, by_triple in PRODUCT_REGISTRY_PACKAGES.items():
        for triple, packages in by_triple.items():
            for package in packages:
                if package.startswith("criterion-") or package.startswith("aws-lc-sys-"):
                    fail("%s production graph leaked %s on %s" % (product, package, triple))
