//! Build-time only: extracts the `(type, name)` pairs out of the
//! `CONFIG`/`CALIAS` macro tables in `ext/configuration.h` and
//! `tracer/configuration.h` by running them through the C preprocessor with
//! those macros locally redefined — the same technique
//! `ext/configuration_helpers.h` itself uses to generate the C accessors,
//! and the same "run cpp, then trim around a marker" strategy
//! `tooling/generate-supported-configurations.sh` uses. The only
//! post-processing done on the preprocessor's output is splitting on the
//! `|NEXT_CONFIG|` delimiter and the first comma of each record — there's
//! never a quoted value or embedded comma to worry about, since (unlike the
//! JSON generator) this only needs the type and name, not defaults/aliases.
//!
//! From that list it writes `$OUT_DIR/generated_config.rs`: a `ConfigId`
//! enum (in the same order as the real C `datadog_config_id` enum, since
//! both are generated from the same macro list — so the discriminants
//! match by construction) plus a `get_<NAME>()`/`get_global_<NAME>()` pair
//! per config, calling into the small hand-written zval helpers in
//! `components-rs/config.rs`. `include!`d from there.

use std::env;
use std::fs;
use std::path::Path;

const MARKER: &str = "RUST_CONFIG_MARKER";

const PROBE_SOURCE: &str = r#"
#define DDTRACE
#include "ext/configuration.h"

#define CUSTOM(type) type
#define SET MAP
#define SET_LOWERCASE MAP
#define SET_OR_MAP_LOWERCASE MAP
#define JSON MAP
#define CALIAS CONFIG
#define CONFIG(type, name, ...) type, name |NEXT_CONFIG|

RUST_CONFIG_MARKER
DD_ALL_CONFIGURATIONS
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
    for inc in php_config_includes() {
        cc_build.include(inc);
    }

    let expanded = cc_build.expand();
    let expanded =
        String::from_utf8(expanded).expect("config preprocessor output was not valid UTF-8");

    let start = expanded
        .find(MARKER)
        .unwrap_or_else(|| panic!("marker `{MARKER}` not found in preprocessor output"))
        + MARKER.len();

    let mut variants = String::new();
    let mut accessors = String::new();
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
        accessors.push_str(&render_accessor(ty, name));
    }

    let generated = format!(
        "#[repr(u16)]\n#[allow(non_camel_case_types)]\npub enum ConfigId {{\n{variants}}}\n\n{accessors}"
    );
    fs::write(Path::new(&out_dir).join("generated_config.rs"), generated)
        .expect("failed to write generated_config.rs");

    println!("cargo:rerun-if-changed=ext/configuration.h");
    println!("cargo:rerun-if-changed=ext/configuration_helpers.h");
    println!("cargo:rerun-if-changed=tracer/configuration.h");
}

fn render_accessor(ty: &str, name: &str) -> String {
    let (ret_ty, extract) = match ty {
        "BOOL" => ("bool", "zval_is_true"),
        "INT" => ("i64", "zval_lval"),
        "DOUBLE" => ("f64", "zval_dval"),
        "STRING" => ("String", "zval_string"),
        "MAP" => ("*mut std::ffi::c_void", "zval_arr"),
        other => panic!("config_codegen: unrecognized config type token `{other}` for `{name}`"),
    };
    format!(
        "#[allow(non_snake_case)]\npub fn get_{name}() -> {ret_ty} {{ unsafe {{ {extract}(config_value(ConfigId::{name} as u16)) }} }}\n\
         #[allow(non_snake_case)]\npub fn get_global_{name}() -> {ret_ty} {{ unsafe {{ {extract}(config_global_value(ConfigId::{name} as u16)) }} }}\n\n"
    )
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
