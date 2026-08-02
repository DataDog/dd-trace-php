fn main() {
    // On Linux, set ddog_spawn_direct_entry as the ELF entry point for the
    // cdylib build (libdatadog_php.so in SSI deployments).  This allows ld.so
    // to exec the library directly without a trampoline binary.
    if std::env::var("CARGO_CFG_TARGET_OS").as_deref() == Ok("linux") {
        println!("cargo:rustc-cdylib-link-arg=-Wl,-e,ddog_spawn_direct_entry");
    }

    println!("cargo:rerun-if-env-changed=DDTRACE_LLVM_LIBUNWIND_PATH");

    // Rebuilt std emits `-lunwind`, but libdd-libunwind-sys puts nongnu
    // libunwind's archive earlier on the search path. Link the patched LLVM
    // archive explicitly so the portable cdylib's `_Unwind_*` references use it.
    if std::env::var_os("CARGO_FEATURE_GLIBC_COMPAT").is_some()
        && std::env::var("CARGO_CFG_TARGET_ENV").as_deref() == Ok("musl")
    {
        let archive = std::env::var("DDTRACE_LLVM_LIBUNWIND_PATH")
            .expect("DDTRACE_LLVM_LIBUNWIND_PATH is not set");
        println!("cargo:rustc-cdylib-link-arg={archive}");
    }
}
