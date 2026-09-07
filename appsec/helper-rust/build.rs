use std::env;
use std::path::PathBuf;

fn main() {
    println!("cargo::rustc-check-cfg=cfg(tokio_unstable)");

    let has_coverage = env::var("CARGO_FEATURE_COVERAGE").is_ok();

    // When building with coverage instrumentation, compile the code used by
    // the sidecar entrypoint to initialize the LLVM profiling runtime.
    if has_coverage {
        cc::Build::new()
            .file("coverage_init.c")
            .define("COVERAGE_BUILD", None)
            .compile("coverage_init");

        println!("cargo::rerun-if-changed=coverage_init.c");
    }

    set_ddappsec_version();
}

fn set_ddappsec_version() {
    // Read the version from the VERSION file at the repository root.
    // CI updates this file to e.g. "1.17.0+<sha>" before building.
    // The PHP extension reads the same file via CMake, so both will agree.
    let manifest_dir = env::var("CARGO_MANIFEST_DIR").expect("CARGO_MANIFEST_DIR not set");
    let version_path = PathBuf::from(&manifest_dir).join("../../VERSION");
    let version = std::fs::read_to_string(&version_path)
        .unwrap_or_else(|_| env::var("CARGO_PKG_VERSION").unwrap_or_else(|_| "0.0.0".to_string()));
    let version = version.trim().to_string();
    println!("cargo::rustc-env=DDAPPSEC_VERSION={}", version);
    println!("cargo::rerun-if-changed={}", version_path.display());
}
