#[path = "../profiling/build.rs"]
mod profiling_build;

fn main() {
    // This entry point belongs only to the common/tracer cdylib used by SSI.
    // The standalone profiler must remain an ordinary PHP shared library.
    if std::env::var_os("CARGO_FEATURE_TRACER").is_some()
        && std::env::var("CARGO_CFG_TARGET_OS").as_deref() == Ok("linux")
    {
        println!("cargo:rustc-cdylib-link-arg=-Wl,-e,ddog_spawn_direct_entry");
    }

    if std::env::var_os("CARGO_FEATURE_PROFILING").is_some() {
        profiling_build::build();
    }
}
