//! Builds the localized parser archive, then links and runs isolation_test.rs
//! with its own runtime under both panic strategies.
//!
//! The tracer uses panic=abort, but the parser must unwind to recover from
//! OOM. With an abort host, every injected OOM proves the parser still uses
//! its own unwinder; binding to the host's runtime would abort instead. With
//! an unwind host, the test proves that two unwinding runtimes coexist: parser
//! panics never reach the host's hook, and host panics remain catchable.
use std::env;
use std::path::{Path, PathBuf};
use std::process::Command;

use serial_test::{file_serial, serial};

#[path = "../tools/isolate.rs"]
mod isolate;

#[test]
#[serial]
#[file_serial]
fn host_panic_unwind() {
    run("unwind");
}

#[test]
#[serial]
#[file_serial]
fn host_panic_abort() {
    run("abort");
}

fn run(panic: &str) {
    let exe = compile(panic);
    let status = Command::new(&exe).status().unwrap();
    assert!(status.success(), "{panic} host failed");
}

fn compile(panic: &str) -> PathBuf {
    let source = Path::new(env!("CARGO_MANIFEST_DIR"));
    let out = PathBuf::from(env!("CARGO_TARGET_TMPDIR")).join("isolation");
    std::fs::create_dir_all(&out).unwrap();

    let target = host_triple();
    isolate::build(
        source,
        &out,
        &target,
        Command::new(env::var_os("CC").unwrap_or_else(|| "cc".into())),
        Command::new(env::var_os("AR").unwrap_or_else(|| "ar".into())),
    );

    let exe = out.join(format!("test-{panic}"));
    let status = Command::new(env::var_os("RUSTC").unwrap_or_else(|| "rustc".into()))
        .arg("--edition=2021")
        .args([
            "-C",
            "opt-level=2",
            "-C",
            &format!("panic={panic}"),
            "-C",
            "lto=fat",
        ])
        .arg(source.join("tests/isolation_test.rs"))
        .arg("-L")
        .arg(format!("native={}", out.display()))
        .args(["-l", "static=ddtrace_appsec_body_private"])
        .arg("-o")
        .arg(&exe)
        .status()
        .unwrap();
    assert!(status.success(), "compiling the {panic} host failed");
    exe
}

fn host_triple() -> String {
    let rustc = env::var_os("RUSTC").unwrap_or_else(|| "rustc".into());
    let output = Command::new(rustc).arg("-vV").output().unwrap();
    assert!(output.status.success(), "rustc -vV failed");
    String::from_utf8(output.stdout)
        .unwrap()
        .lines()
        .find_map(|line| line.strip_prefix("host: "))
        .expect("rustc -vV did not report a host")
        .to_owned()
}
