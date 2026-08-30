//! Generates Rust configuration IDs from the same X-macro list used by C.
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
    for inc in php_config_includes() {
        cc_build.include(inc);
    }

    let expanded = String::from_utf8(cc_build.expand())
        .expect("config preprocessor output was not valid UTF-8");
    let start = expanded
        .find(MARKER)
        .unwrap_or_else(|| panic!("marker `{MARKER}` not found in preprocessor output"))
        + MARKER.len();

    let mut variants = String::new();
    let mut count = 0;
    for record in expanded[start..].split("|NEXT_CONFIG|") {
        let record = record.trim();
        if record.is_empty() {
            continue;
        }
        let (_, name) = record
            .split_once(',')
            .unwrap_or_else(|| panic!("malformed config record: {record:?}"));
        variants.push_str(&format!("    {},\n", name.trim()));
        count += 1;
    }

    fs::write(
        Path::new(&out_dir).join("generated_config.rs"),
        format!(
            "pub const CONFIG_COUNT: usize = {count};\n\n#[repr(u16)]\n#[derive(Clone, Copy, Debug)]\n#[allow(non_camel_case_types)]\npub enum ConfigId {{\n{variants}}}\n"
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

fn php_config_includes() -> Vec<String> {
    let php_config = env::var("PHP_CONFIG").unwrap_or_else(|_| "php-config".to_string());
    let output = std::process::Command::new(&php_config)
        .arg("--includes")
        .output()
        .unwrap_or_else(|e| panic!("failed to run `{php_config} --includes`: {e}"));
    if !output.status.success() {
        panic!(
            "`{php_config} --includes` failed: {}",
            String::from_utf8_lossy(&output.stderr)
        );
    }
    String::from_utf8(output.stdout)
        .expect("php-config output was not valid UTF-8")
        .split_whitespace()
        .filter_map(|flag| flag.strip_prefix("-I"))
        .map(str::to_string)
        .collect()
}
