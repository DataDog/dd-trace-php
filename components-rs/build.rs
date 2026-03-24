fn main() {
    let target_os = std::env::var("CARGO_CFG_TARGET_OS").unwrap_or_default();
    // SHARED=1 is set by config.m4 when building in rust-library-split (SSI) mode.
    // In that mode this crate is the cdylib that carries the Rust code.
    // We compile ssi_entry.c into it to make libddtrace_php.so directly executable
    // via the dynamic loader (ld.so).
    let shared_build = std::env::var("SHARED").as_deref() == Ok("1");

    if target_os == "linux" && shared_build {
        let manifest_dir = std::env::var("CARGO_MANIFEST_DIR").unwrap();
        cc::Build::new()
            .file(format!("{manifest_dir}/ssi_entry.c"))
            .flag("-fvisibility=hidden")
            .compile("ssi_entry");

        println!("cargo:rustc-link-arg=-Wl,-e,_dd_ssi_entry");
    }
}
