//! Lists the test programs (`src/bin/*.rs`) for the runner, `tests/genmc.rs`:
//! `CARGO_BIN_EXE_<name>` can only be read with `env!` and a literal name.
//! Also checks that every program has its `bin` entry in Cargo.toml (with
//! `test = false`, see there).

use std::fmt::Write as _;
use std::path::Path;
use std::{env, fs};

fn main() {
    println!("cargo::rerun-if-changed=src/bin");
    println!("cargo::rerun-if-changed=Cargo.toml");
    let manifest_dir = env::var("CARGO_MANIFEST_DIR").expect("CARGO_MANIFEST_DIR");
    let mut names: Vec<String> = fs::read_dir(Path::new(&manifest_dir).join("src/bin"))
        .expect("src/bin")
        .map(|entry| entry.expect("src/bin entry").path())
        .filter(|path| path.extension().is_some_and(|ext| ext == "rs"))
        .map(|path| {
            let stem = path.file_stem().and_then(|s| s.to_str());
            stem.expect("UTF-8 file name").to_owned()
        })
        .collect();
    names.sort();
    check_bin_entries(&manifest_dir, &names);

    let mut table = String::from("&[\n");
    for name in &names {
        writeln!(
            table,
            "    Program {{ name: {name:?}, exe: env!(\"CARGO_BIN_EXE_{name}\") }},"
        )
        .unwrap();
    }
    table.push(']');
    let out = Path::new(&env::var("OUT_DIR").expect("OUT_DIR")).join("programs.rs");
    fs::write(out, table).expect("write programs.rs");
}

/// Fails unless Cargo.toml has exactly one
/// `{ name = "<name>", test = false, bench = false }` entry per program.
fn check_bin_entries(manifest_dir: &str, names: &[String]) {
    let manifest =
        fs::read_to_string(Path::new(manifest_dir).join("Cargo.toml")).expect("Cargo.toml");
    let mut entries: Vec<&str> = manifest
        .lines()
        .filter_map(|line| {
            let line = line.trim();
            let rest = line.strip_prefix("{ name = \"")?;
            rest.strip_suffix("\", test = false, bench = false },")
        })
        .collect();
    entries.sort();
    if entries != names {
        panic!(
            "Cargo.toml must list every test program in src/bin exactly once, as\n  \
             {{ name = \"<name>\", test = false, bench = false }},\n\
             in its `bin` array; programs: {names:?}; entries: {entries:?}"
        );
    }
}
