"""Packaging-compatible SSI payload path declarations."""

load(":bundle.bzl", "ssi_payload")

def ssi_required_paths(api, profiler_supported = True):
    """Returns required paths for one PHP API in both libc payload trees."""
    paths = [
        "linux-gnu/loader/libdatadog_php.so",
        "linux-gnu/loader/dd_library_loader.so",
        "linux-gnu/loader/dd_library_loader.ini",
        "linux-musl/loader/libdatadog_php.so",
        "linux-musl/loader/dd_library_loader.so",
        "linux-musl/loader/dd_library_loader.ini",
        "linux-gnu/trace/ext/%s/ddtrace.so" % api,
        "linux-gnu/trace/ext/%s/ddtrace-zts.so" % api,
        "linux-musl/trace/ext/%s/ddtrace.so" % api,
        "linux-musl/trace/ext/%s/ddtrace-zts.so" % api,
        "linux-gnu/appsec/ext/%s/ddappsec.so" % api,
        "linux-gnu/appsec/ext/%s/ddappsec-zts.so" % api,
        "linux-musl/appsec/ext/%s/ddappsec.so" % api,
        "linux-musl/appsec/ext/%s/ddappsec-zts.so" % api,
        "appsec/etc/recommended.json",
        "requirements.json",
        "LICENSE",
        "LICENSE-3rdparty.csv",
        "trace/src",
        "version",
    ]
    if profiler_supported:
        paths.extend([
            "linux-gnu/profiling/ext/%s/datadog-profiling.so" % api,
            "linux-gnu/profiling/ext/%s/datadog-profiling-zts.so" % api,
            "linux-musl/profiling/ext/%s/datadog-profiling.so" % api,
            "linux-musl/profiling/ext/%s/datadog-profiling-zts.so" % api,
        ])
    debug_paths = []
    for path in paths:
        if path.endswith(".so"):
            debug_paths.append(path + ".debug")
    return tuple(paths + debug_paths)

def ssi_payload_for_api(name, version, api, entries, profiler_supported = True):
    """Assembles one complete API payload from explicit product-file entries.

    Each entry is `struct(src = Label, destination = string)`. The macro keeps
    product labels separate from archive paths, while the rule enforces that
    the full packaging layout is present before it can be archived.
    """
    mappings = {}
    for entry in entries:
        if entry.src in mappings:
            fail("SSI source is mapped more than once: %s" % entry.src)
        mappings[entry.src] = entry.destination
    ssi_payload(
        name = name,
        version = version,
        srcs = mappings,
        required_paths = ssi_required_paths(api, profiler_supported),
    )
