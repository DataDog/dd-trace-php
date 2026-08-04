fn main() {
    println!("cargo:rerun-if-changed=src/cxa_thread_atexit.c");

    // Only glibc has __cxa_thread_atexit_impl semantics worth overriding.
    if std::env::var("CARGO_CFG_TARGET_OS").as_deref() != Ok("linux")
        || std::env::var("CARGO_CFG_TARGET_ENV").as_deref() != Ok("gnu")
    {
        return;
    }

    cc::Build::new()
        .file("src/cxa_thread_atexit.c")
        // The whole point: an exported definition would lose to glibc's at load time.
        .flag("-fvisibility=hidden")
        .compile("dd_cxa_thread_atexit");
}
