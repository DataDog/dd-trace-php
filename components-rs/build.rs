#[path = "../profiling/build.rs"]
mod profiling_build;
#[path = "config_codegen.rs"]
mod config_codegen;

fn main() {
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
    }

    if std::env::var_os("CARGO_FEATURE_PROFILING").is_some() {
        profiling_build::build();
    }

    if std::env::var_os("CARGO_FEATURE_TRACER").is_some() {
        config_codegen::build();
    }
}
