use std::env;

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
}
