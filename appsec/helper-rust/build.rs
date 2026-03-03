use std::env;
use std::path::PathBuf;

fn main() {
    let target = env::var("TARGET").expect("TARGET environment variable not set");
    let has_coverage = env::var("CARGO_FEATURE_COVERAGE").is_ok();

    // Compile and link glibc compatibility shim for musl targets
    if target.contains("musl") {
        cc::Build::new()
            .file("glibc_compat.c")
            .compile("glibc_compat");

        println!("cargo::rerun-if-changed=glibc_compat.c");
    }

    // When building with coverage instrumentation, compile coverage initialization code
    // that configures LLVM profiling runtime at library load time
    if has_coverage {
        let out_dir = env::var("OUT_DIR").expect("OUT_DIR not set");

        cc::Build::new()
            .file("coverage_init.c")
            .define("COVERAGE_BUILD", None)
            .compile("coverage_init");

        // On aarch64, the LLVM profiler runtime requires outline-atomic functions
        // that aren't available in older glibc versions
        let needs_outline_atomics = target.contains("aarch64");
        if needs_outline_atomics {
            cc::Build::new()
                .file("outline_atomics.c")
                .compile("outline_atomics");
            println!("cargo::rerun-if-changed=outline_atomics.c");
        }

        // Force the linker to include coverage_init.a and outline_atomics.a in their
        // entirety, even though nothing in Rust references their symbols directly.
        // coverage_init.c has constructor/destructor functions for flushing coverage.
        // outline_atomics.c provides atomic helpers needed by the profiler runtime.
        println!("cargo::rustc-link-arg=-Wl,--whole-archive");
        println!("cargo::rustc-link-arg={}/libcoverage_init.a", out_dir);
        if needs_outline_atomics {
            println!("cargo::rustc-link-arg={}/liboutline_atomics.a", out_dir);
        }
        println!("cargo::rustc-link-arg=-Wl,--no-whole-archive");

        println!("cargo::rerun-if-changed=coverage_init.c");
    }

    // Add $ORIGIN (Linux) or @loader_path (macOS) to allow finding libraries
    // in the same directory as the binary/library
    if target.contains("linux") {
        println!("cargo::rustc-link-arg=-Wl,-rpath,$ORIGIN");
    } else if target.contains("darwin") || target.contains("apple") {
        println!("cargo::rustc-link-arg=-Wl,-rpath,@loader_path");
    }

    // If LIBDDWAF_PREFIX is set, add that library path to rpath as well
    // This matches the behavior in libddwaf-sys build.rs
    if let Ok(prefix) = env::var("LIBDDWAF_PREFIX") {
        let lib_dir = PathBuf::from(prefix).join("lib");
        println!("cargo::rustc-link-arg=-Wl,-rpath,{}", lib_dir.display());
    }

    println!("cargo::rerun-if-env-changed=LIBDDWAF_PREFIX");

    set_ddappsec_version();
    build_test_sidecar_lib();
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
    println!(
        "cargo::rerun-if-changed={}",
        version_path.display()
    );
}

fn build_test_sidecar_lib() {
    let out_dir = env::var("OUT_DIR").expect("OUT_DIR not set");
    let target = env::var("TARGET").expect("TARGET not set");
    let host = env::var("HOST").expect("HOST not set");

    let (lib_name, shared_flag) = if target.contains("darwin") || target.contains("apple") {
        ("libtest_sidecar.dylib", "-dynamiclib")
    } else {
        ("libtest_sidecar.so", "-shared")
    };

    let src = "test_sidecar_lib.c";
    let out = PathBuf::from(&out_dir).join(lib_name);

    let status = cc::Build::new()
        .target(&target)
        .host(&host)
        .get_compiler()
        .to_command()
        .args([shared_flag, "-fPIC", "-o"])
        .arg(&out)
        .arg(src)
        .status()
        .expect("Failed to run C compiler for test fixture");

    assert!(status.success(), "Failed to compile test sidecar library");
    println!("cargo::rerun-if-changed={}", src);
}
