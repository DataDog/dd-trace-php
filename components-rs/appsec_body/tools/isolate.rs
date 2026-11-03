//! Build a complete parser staticlib with LTO, resolve its internal references, then
//! localize every definition except the three private C entry points.
//! Also executable with rustc: rustc isolate.rs && ./isolate SOURCE OUT TARGET.
use std::env;
use std::path::{Path, PathBuf};
use std::process::Command;

const EXPORTS: [&str; 3] = [
    "ddog_appsec_body_private_drop",
    "ddog_appsec_body_private_json",
    "ddog_appsec_body_private_xml",
];

pub fn build(
    source: &Path,
    out: &Path,
    target: &str,
    mut compiler: Command,
    mut archiver: Command,
) {
    std::fs::create_dir_all(out).unwrap();
    let mut rustflags =
        "-Cpanic=unwind\x1f-Crelocation-model=pic\x1f-Ctarget-feature=-crt-static".to_owned();
    if target.contains("-alpine-") {
        // Alpine's std bundles this archive. rustc needs its directory even
        // though the C compiler already searches it when linking.
        let library = Command::new(compiler.get_program())
            .args(compiler.get_args())
            .arg("-print-file-name=libssp_nonshared.a")
            .output()
            .unwrap();
        assert!(
            library.status.success(),
            "could not locate libssp_nonshared"
        );
        let library = String::from_utf8(library.stdout).unwrap();
        let library = Path::new(library.trim());
        assert!(library.is_file(), "libssp_nonshared.a was not found");
        rustflags.push_str(&format!(
            "\x1f-Lnative={}",
            library.parent().unwrap().display()
        ));
    }
    let mut cargo = Command::new(env::var_os("CARGO").unwrap_or_else(|| "cargo".into()));
    cargo
        .args([
            "build",
            "--locked",
            "--release",
            "--lib",
            "--target",
            target,
            // Without std's backtrace feature, the default panic hook cannot
            // pull in the DWARF symbolizer. Unwinding is unaffected.
            "-Zbuild-std=std,panic_unwind",
            "-Zbuild-std-features=panic-unwind",
        ])
        .arg("--manifest-path")
        .arg(source.join("Cargo.toml"))
        .arg("--target-dir")
        .arg(out.join("target"))
        // build-std needs unstable features in cargo and std on our pinned
        // Rust toolchain; it also requires the rust-src component.
        .env("RUSTC_BOOTSTRAP", "1")
        .env("CARGO_ENCODED_RUSTFLAGS", rustflags)
        .env_remove("RUSTFLAGS");
    run(&mut cargo);

    let archive = out
        .join("target")
        .join(target)
        .join("release/libddtrace_appsec_body.a");
    let fused = out.join("parser-fused.o");
    let private = out.join("parser-private.o");
    let apple = target.contains("apple");
    compiler.args(["-r", "-nostdlib"]);
    if apple {
        compiler
            .args(["-Wl,-ld_classic", "-Wl,-all_load"])
            .arg(&archive);
        // With -r, ld turns non-exported definitions into local symbols.
        // Older llvm-objcopy versions cannot localize Mach-O symbols.
        for name in EXPORTS {
            compiler.arg(format!("-Wl,-exported_symbol,_{name}"));
        }
    } else {
        // Compiler wrappers may enable section GC. A relocatable link has no
        // entry point, so retain everything until the final extension link.
        compiler
            .args(["-no-pie", "-Wl,--no-gc-sections", "-Wl,--whole-archive"])
            .arg(&archive)
            .arg("-Wl,--no-whole-archive");
    }
    run(compiler.arg("-o").arg(&fused));

    let mut objcopy = Command::new(tool("OBJCOPY", apple));
    if apple {
        // std's archive includes bitcode. Remove it so a later LTO link cannot
        // bypass the native symbol isolation performed here.
        objcopy.args([
            "--remove-section=__LLVM,__bitcode",
            "--remove-section=__LLVM,__cmdline",
        ]);
    } else {
        objcopy.arg("--remove-section=.llvmbc");
        for name in EXPORTS {
            objcopy.arg(format!("--keep-global-symbol={name}"));
        }
    }
    run(objcopy.arg(&fused).arg(&private));

    // Fail the build if a tool does not actually localize symbols. Visibility
    // alone is insufficient: the final linker could still merge definitions.
    let symbols = Command::new(tool("NM", apple))
        .args(["-g", "--defined-only", "-j"])
        .arg(&private)
        .output()
        .unwrap();
    assert!(symbols.status.success(), "could not audit parser symbols");
    let actual = String::from_utf8(symbols.stdout).unwrap();
    let mut actual: Vec<_> = actual.lines().collect();
    actual.sort_unstable();
    let expected: Vec<_> = EXPORTS
        .iter()
        .map(|name| format!("{}{}", if apple { "_" } else { "" }, name))
        .collect();
    assert_eq!(actual, expected, "parser runtime is not isolated");

    let library = out.join("libddtrace_appsec_body_private.a");
    if library.exists() {
        std::fs::remove_file(&library).unwrap();
    }
    run(archiver.arg("crs").arg(library).arg(private));
}

fn run(command: &mut Command) {
    let status = command
        .status()
        .unwrap_or_else(|e| panic!("{command:?}: {e}"));
    assert!(status.success(), "{command:?} failed: {status}");
}

fn tool(name: &str, apple: bool) -> PathBuf {
    if let Some(path) = env::var_os(format!("DD_APPSEC_{name}")) {
        return path.into();
    }
    let base = name.to_ascii_lowercase();
    let llvm = format!("llvm-{base}");
    for path in [
        PathBuf::from(&llvm),
        // Debian installs versioned names without unversioned aliases.
        PathBuf::from(format!("{llvm}-21")),
        PathBuf::from(format!("llvm21-{base}")),
        PathBuf::from("/opt/homebrew/opt/llvm/bin").join(&llvm),
        PathBuf::from("/usr/local/opt/llvm/bin").join(&llvm),
    ] {
        if Command::new(&path)
            .arg("--version")
            .output()
            .is_ok_and(|o| o.status.success())
        {
            return path;
        }
    }
    if name == "NM" && !apple {
        return base.into();
    }
    // GNU objcopy leaves COMDAT deduplication enabled on localized groups.
    // The final link can then discard the parser's private personality data.
    panic!("install LLVM or set DD_APPSEC_{name} to its llvm-{base} binary");
}

#[allow(dead_code)]
fn main() {
    let args: Vec<_> = env::args().collect();
    assert_eq!(args.len(), 4, "usage: isolate SOURCE OUT TARGET");
    build(
        Path::new(&args[1]),
        Path::new(&args[2]),
        &args[3],
        Command::new(env::var_os("CC").unwrap_or_else(|| "cc".into())),
        Command::new(env::var_os("AR").unwrap_or_else(|| "ar".into())),
    );
}
