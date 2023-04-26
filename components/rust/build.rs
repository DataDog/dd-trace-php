// Unless explicitly stated otherwise all files in this repository are licensed under the Apache License Version 2.0.
// This product includes software developed at Datadog (https://www.datadoghq.com/). Copyright 2021-Present Datadog, Inc.

use std::{env, fs, process};
use std::path::Path;
pub use cc_utils::cc;
pub use sidecar_mockgen::generate_mock_symbols;

fn main() {
    match env::var("DD_SIDECAR_MOCK_SOURCES") {
        Ok(mock_sources) => {
            let split_vec: Vec<_> = mock_sources.split_terminator("\n").collect();
            if split_vec.len() < 2 {
                eprintln!("DD_SIDECAR_MOCK_SOURCES must be newline separated, first the shared object file, followed by at least one object file");
                process::exit(1);
            }
            let binary_path = Path::new(split_vec[0]);
            let object_paths: Vec<_> = split_vec.iter().skip(1).map(Path::new).collect();
            match generate_mock_symbols(binary_path, object_paths.as_slice()) {
                Ok(mock_symbols) => {
                    if let Err(err) = fs::write("mock_php.c", mock_symbols) {
                        eprintln!("Failed generating mock_php.c: {}", err);
                        process::exit(1);
                    }
                }
                Err(err) => {
                    eprintln!("Failed generating mock_php.c: {}", err);
                    process::exit(1);
                }
            }
        }
        Err(_) => {}
    }

    cc_utils::ImprovedBuild::new()
        .file("mock_php.c")
        .link_dynamically("dl")
        .warnings(true)
        .warnings_into_errors(true)
        .emit_rerun_if_env_changed(true)
        .try_compile_shared_lib("mock_php.shared_lib")
        .unwrap();
}
