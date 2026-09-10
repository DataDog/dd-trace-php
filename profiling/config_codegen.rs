//! Generates Rust configuration IDs and storage-typed accessors from the same
//! X-macro list used by C.
//!
//! The C preprocessor reduces each CONFIG/CALIAS declaration to a type/name
//! record. Keeping the records in preprocessor order makes the generated Rust
//! discriminants match `datadog_config_id` without maintaining a second list.

use std::env;
use std::fs;
use std::path::Path;

const MARKER: &str = "RUST_CONFIG_MARKER";

const PROBE_SOURCE: &str = r#"
#if defined(PROFILING) && !defined(TRACER)
#include "profiling/configuration.h"
#define RUST_CONFIGURATIONS DDTRACE_PROFILING_ALL_CONFIGURATIONS
#else
#include "ext/configuration.h"
#define RUST_CONFIGURATIONS DD_ALL_CONFIGURATIONS
#endif

#define CUSTOM(type) type
#define SET MAP
#define SET_LOWERCASE MAP
#define SET_OR_MAP_LOWERCASE MAP
#define JSON MAP
#define CALIAS CONFIG
#define CONFIG(type, name, ...) type, name |NEXT_CONFIG|

RUST_CONFIG_MARKER
RUST_CONFIGURATIONS
"#;

pub fn build() {
    let out_dir = env::var("OUT_DIR").expect("OUT_DIR not set");
    let probe_path = Path::new(&out_dir).join("config_probe.c");
    fs::write(&probe_path, PROBE_SOURCE).expect("failed to write config_probe.c");

    let mut cc_build = cc::Build::new();
    cc_build
        .file(&probe_path)
        .include(".")
        .include("ext")
        .include("zend_abstract_interface")
        .include("src/dogstatsd")
        .include("components-rs");
    if env::var_os("CARGO_FEATURE_TRACER").is_some() {
        cc_build.define("TRACER", None);
    }
    if env::var_os("CARGO_FEATURE_PROFILING").is_some() {
        cc_build.define("PROFILING", None);
    }
    configure_php_build(&mut cc_build);

    let expanded = String::from_utf8(cc_build.expand())
        .expect("config preprocessor output was not valid UTF-8");
    let start = expanded
        .find(MARKER)
        .unwrap_or_else(|| panic!("marker `{MARKER}` not found in preprocessor output"))
        + MARKER.len();

    let mut variants = String::new();
    let mut accessors = String::new();
    let mut count = 0;
    for record in expanded[start..].split("|NEXT_CONFIG|") {
        let record = record.trim();
        if record.is_empty() {
            continue;
        }
        let (ty, name) = record
            .split_once(',')
            .unwrap_or_else(|| panic!("malformed config record: {record:?}"));
        let (ty, name) = (ty.trim(), name.trim());
        variants.push_str(&format!("    {name},\n"));
        accessors.push_str(&render_accessors(ty, name));
        count += 1;
    }

    fs::write(
        Path::new(&out_dir).join("generated_config.rs"),
        format!(
            "pub const CONFIG_COUNT: usize = {count};\n\n#[repr(u16)]\n#[derive(Clone, Copy, Debug)]\n#[allow(non_camel_case_types)]\npub enum ConfigId {{\n{variants}}}\n\n{accessors}"
        ),
    )
    .expect("failed to write generated_config.rs");

    for path in [
        "ext/configuration.h",
        "ext/configuration_helpers.h",
        "ext/configuration_shared.h",
        "tracer/configuration.h",
        "profiling/configuration.h",
    ] {
        println!("cargo:rerun-if-changed={path}");
    }
}

fn render_accessors(ty: &str, name: &str) -> String {
    let (return_type, current, memoized) = match ty {
        "BOOL" => ("bool", "config_bool", "memoized_config_bool"),
        "INT" => ("i64", "config_int", "memoized_config_int"),
        "DOUBLE" => ("f64", "config_double", "memoized_config_double"),
        "STRING" => ("String", "config_string", "memoized_config_string"),
        "MAP" => (
            "crate::profiling::config::ConfigMap",
            "config_map",
            "memoized_config_map",
        ),
        other => panic!("unrecognized configuration type {other:?} for {name}"),
    };

    format!(
        "#[cfg(feature = \"profiling\")]\n#[allow(dead_code, non_snake_case)]\npub(crate) unsafe fn get_{name}() -> {return_type} {{\n    crate::profiling::config::{current}(ConfigId::{name})\n}}\n\
         #[cfg(feature = \"profiling\")]\n#[allow(dead_code, non_snake_case)]\npub(crate) unsafe fn get_global_{name}() -> {return_type} {{\n    crate::profiling::config::{memoized}(ConfigId::{name})\n}}\n\n"
    )
}

// This probe only ever runs the C preprocessor (never a real compile), purely to
// expand the CONFIG()/CALIAS() X-macro list in ext/configuration.h into a flat,
// textual `type, name` record per entry. That expansion never depends on the
// *content* of any PHP header -- only on `#include` directives resolving to *some*
// file on disk (ext/configuration.h transitively includes real PHP headers for
// declarations this codegen step never looks at). So rather than require a real
// PHP installation -- which loadable extensions have via Make's INCLUDES, but
// which some build contexts genuinely don't have one "correct" instance of (e.g.
// buildPortableLibdatadogPhp in appsec/tests/integration/build.gradle, which
// builds one Rust artifact shared across every PHP version in the test matrix) --
// point the preprocessor at a small set of bundled empty stub headers instead.
// See config_codegen_php_stubs/README.md for what to do if this ever needs a new
// stub (it will fail loudly with a `file not found` naming the missing path).
fn configure_php_build(build: &mut cc::Build) {
    build.include("profiling/config_codegen_php_stubs");
    build.include("profiling/config_codegen_php_stubs/main");
    build.include("profiling/config_codegen_php_stubs/TSRM");
    build.include("profiling/config_codegen_php_stubs/Zend");
    build.include("profiling/config_codegen_php_stubs/ext");
}
