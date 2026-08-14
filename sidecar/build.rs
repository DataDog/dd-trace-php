fn main() {
    let glibc_compat_enabled = std::env::var("CARGO_FEATURE_GLIBC_COMPAT").is_ok();
    let target = std::env::var("TARGET").expect("TARGET environment variable not set");

    if glibc_compat_enabled && target.contains("musl") {
        cc::Build::new()
            .file("glibc_compat.c")
            .compile("glibc_compat");
    }

    println!("cargo::rerun-if-changed=glibc_compat.c");
}
