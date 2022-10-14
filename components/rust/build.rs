use std::{env, path::Path};


fn main() {
    let crate_dir = env::var("CARGO_MANIFEST_DIR").unwrap();

    let out_dir = env::var("OUT_DIR").unwrap();
    let out_dir = Path::new(&out_dir);

    cbindgen::Builder::new()
        .with_crate(crate_dir)
        .with_language(cbindgen::Language::C)
        .with_header("// Unless explicitly stated otherwise all files in this repository are licensed under the Apache License Version 2.0. \n\
// This product includes software developed at Datadog (https://www.datadoghq.com/). Copyright 2021-Present Datadog, Inc. \n\n")
        .with_item_prefix("ddog_")
        .with_no_includes()
        .with_sys_include("stdbool.h")
        .with_sys_include("stddef.h")
        .with_sys_include("stdint.h")
        .with_tab_width(2)
        .with_include_guard("DD_TRACE_PHP_RUST_H")
        .with_style(cbindgen::Style::Both)
        .with_parse_deps(true)
        .with_parse_include(&["ddcommon-ffi"])
        .generate()
        .expect("Unable to generate bindings")
        .write_to_file(out_dir.join("dd_trace_php_rust.h"));
}

