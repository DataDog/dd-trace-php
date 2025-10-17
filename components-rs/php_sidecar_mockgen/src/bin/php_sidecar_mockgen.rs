// Unless explicitly stated otherwise all files in this repository are licensed under the Apache License Version 2.0.
// This product includes software developed at Datadog (https://www.datadoghq.com/). Copyright 2021-Present Datadog, Inc.

pub use ddcommon::cc_utils::cc;
pub use sidecar_mockgen::generate_mock_symbols;
use std::path::Path;
use std::{env, fs, process};

fn main() {
    // This script is necessary to avoid the linker puking when the sidecar tries to load our ddtrace.so
    // As php itself is not available within the sidecar, it needs to make sure that all symbols ddtrace.so depends on, are available.
    // The mock_generator takes care of generating these symbols.
    let args: Vec<_> = env::args_os().collect();
    if args.len() < 3 {
        eprintln!(
            "Needs at least 3 args: the output file, the shared object file followed by at least one object file"
        );
        process::exit(1);
    }

    let output_path = Path::new(&args[1]);
    let binary_path = Path::new(&args[2]);
    let object_paths: Vec<_> = args.iter().skip(3).map(Path::new).collect();
    let mock_symbols = match generate_mock_symbols(binary_path, object_paths.as_slice()) {
        Ok(symbols) => symbols,
        Err(err) => {
            eprintln!("Failed generating mock_php_syms.c: {}", err);
            process::exit(1);
        }
    };

    if fs::read("mock_php_syms.c")
        .ok()
        .map(|contents| contents == mock_symbols.as_str().as_bytes())
        != Some(true)
    {
        if let Err(err) = fs::write("mock_php_syms.c", mock_symbols) {
            eprintln!("Failed generating mock_php_syms.c: {}", err);
            process::exit(1);
        }
    }

    let source_modified = fs::metadata("mock_php_syms.c").unwrap().modified().unwrap();
    if fs::metadata("mock_php.shared_lib").map_or(true, |m| m.modified().unwrap() < source_modified) {
        env::set_var("OPT_LEVEL", "2");

        let mut cc_build = cc::Build::new();

        // The 'host' and 'target' options are required to compile.
        // They can be provided using the HOST and TARGET environment variables.
        // On Linux, these environment variables can be empty strings, but it's not the case on macOS.
        let host = std::env::var("HOST").unwrap_or("".to_string());
        if host == "" {
            cc_build.host(current_platform::CURRENT_PLATFORM);
        }
        let target = std::env::var("TARGET").unwrap_or("".to_string());
        if target == "" {
            cc_build.target(current_platform::CURRENT_PLATFORM);
        }

        ddcommon::cc_utils::ImprovedBuild::new()
            .set_cc_builder(cc_build)
            .file("mock_php_syms.c")
            .link_dynamically("dl")
            .warnings(true)
            .warnings_into_errors(true)
            .emit_rerun_if_env_changed(false)
            .try_compile_shared_lib("mock_php.shared_lib")
            .unwrap();

        let bin = match fs::read("mock_php.shared_lib") {
            Ok(bin) => bin,
            Err(err) => {
                eprintln!("Failed opening generated mock_php.shared_lib: {}", err);
                process::exit(1);
            }
        };

        let comma_separated = bin.iter().map(|byte| format!("{byte:#X}")).collect::<Vec<String>>().join(",");
        let out = format!(r#"
            const unsigned char DDTRACE_MOCK_PHP[] = {{{comma_separated}}};
            const void *DDTRACE_MOCK_PHP_SIZE = (void *) sizeof(DDTRACE_MOCK_PHP);
            "#);

        if let Err(err) = fs::write(output_path, out) {
            eprintln!("Failed generating {:?}: {}", output_path, err);
            process::exit(1);
        }
    }
}
