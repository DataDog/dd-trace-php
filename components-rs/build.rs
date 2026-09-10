#[path = "../profiling/build.rs"]
mod profiling_build;

// aws-lc-sys vendors and compiles a copy of AWS-LC (including jitterentropy's
// C sources), which fails to build under some distros' hardened compiler
// flags (see DataDog/dd-trace-php#4162).
fn assert_no_aws_lc_sys() {
    let manifest_dir = std::env::var("CARGO_MANIFEST_DIR").expect("CARGO_MANIFEST_DIR not set");
    let cargo = std::env::var("CARGO").unwrap_or_else(|_| "cargo".to_string());
    let output = std::process::Command::new(&cargo)
        .args(["tree", "--quiet", "-i", "aws-lc-sys"])
        .current_dir(&manifest_dir)
        .output();
    let output = match output {
        Ok(o) => o,
        Err(e) => {
            println!("cargo:warning=skipping aws-lc-sys guard: failed to run `cargo tree`: {e}");
            return;
        }
    };

    if output.status.success() {
        panic!(
            "aws-lc-sys is in the resolved dependency graph. This crate compiles \
             vendored C sources (including jitterentropy) that break under some \
             distros' hardened build flags (see DataDog/dd-trace-php#4162), and \
             we don't need it: this workspace uses `ring` as its crypto backend. \
             Find the dependency edge pulling it in \
             (`cargo tree -e features -i aws-lc-sys`) and disable its default \
             features / select the `ring` alternative instead of aws-lc-rs."
        );
    }

    let stderr = String::from_utf8_lossy(&output.stderr);
    if !stderr.contains("did not match any packages") {
        println!(
            "cargo:warning=skipping aws-lc-sys guard: `cargo tree -i aws-lc-sys` \
             failed unexpectedly: {stderr}"
        );
    }
}

fn main() {
    assert_no_aws_lc_sys();

    println!("cargo:rustc-check-cfg=cfg(standalone_profiler)");
    if std::env::var_os("CARGO_FEATURE_PROFILING").is_some()
        && std::env::var_os("CARGO_FEATURE_TRACER").is_none()
    {
        println!("cargo:rustc-cfg=standalone_profiler");
    }

    // This entry point belongs only to the common/tracer cdylib used by SSI.
    // The standalone profiler must remain an ordinary PHP shared library.
    if std::env::var_os("CARGO_FEATURE_TRACER").is_some()
        && std::env::var("CARGO_CFG_TARGET_OS").as_deref() == Ok("linux")
    {
        println!("cargo:rustc-cdylib-link-arg=-Wl,-e,ddog_spawn_direct_entry");
        println!("cargo:rustc-cdylib-link-arg=-Wl,-soname,libdatadog_php.so");
    }

    if std::env::var_os("CARGO_FEATURE_PROFILING").is_some() {
        profiling_build::build();
    }
}
