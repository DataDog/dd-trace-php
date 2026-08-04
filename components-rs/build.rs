use build_common::find_rust_lld_dir;
use std::{env, fs};

fn main() {
    // On Linux, set ddog_spawn_direct_entry as the ELF entry point for the
    // cdylib build (libdatadog_php.so in SSI deployments). This allows ld.so
    // to exec the library directly without a trampoline binary.
    if env::var("CARGO_CFG_TARGET_OS").as_deref() == Ok("linux") {
        println!("cargo:rustc-cdylib-link-arg=-Wl,-e,ddog_spawn_direct_entry");

        // Rust's cdylib version script hides symbols defined by dependencies.
        // Keep the standard OTel TLS symbol in libdatadog_php.so's dynamic
        // symbol table so the split ddtrace.so can resolve and publish it.
        let version_script = std::path::PathBuf::from(env::var_os("OUT_DIR").unwrap())
            .join("otel-thread-context.version");
        fs::write(&version_script, "{\n  global: otel_thread_ctx_v1;\n};\n").unwrap();
        if let Some(gcc_ld_dir) = find_rust_lld_dir() {
            println!("cargo:rustc-cdylib-link-arg=-B{}", gcc_ld_dir.display());
        }
        println!("cargo:rustc-cdylib-link-arg=-fuse-ld=lld");
        println!(
            "cargo:rustc-cdylib-link-arg=-Wl,--version-script={}",
            version_script.display()
        );
    }
}
