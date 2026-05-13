fn main() {
    // On Linux, set ddog_spawn_direct_entry as the ELF entry point for the
    // cdylib build (libddtrace_php.so in SSI deployments).  This allows ld.so
    // to exec the library directly without a trampoline binary.
    if std::env::var("CARGO_CFG_TARGET_OS").as_deref() == Ok("linux") {
        println!("cargo:rustc-cdylib-link-arg=-Wl,-e,ddog_spawn_direct_entry");
    }
}
