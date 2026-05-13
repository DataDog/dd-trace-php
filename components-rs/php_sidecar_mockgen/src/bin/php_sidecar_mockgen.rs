// Unless explicitly stated otherwise all files in this repository are licensed under the Apache License Version 2.0.
// This product includes software developed at Datadog (https://www.datadoghq.com/). Copyright 2021-Present Datadog, Inc.

pub use sidecar_mockgen::weaken_object_symbols;
use std::path::Path;
use std::{env, process};

fn main() {
    let args: Vec<_> = env::args_os().collect();

    if args.get(1).and_then(|a| a.to_str()) != Some("weaken-dynsym") || args.len() < 4 {
        eprintln!("Usage: php_sidecar_mockgen weaken-dynsym <target.o ...> <php_binary>");
        process::exit(1);
    }

    let php_binary = Path::new(args.last().unwrap());
    let targets: Vec<_> = args[2..args.len() - 1]
        .iter()
        .map(|a| Path::new(a.as_os_str()))
        .collect();

    for target in targets {
        if let Err(e) = weaken_object_symbols(target, php_binary) {
            eprintln!("Warning: weaken-dynsym {}: {e}", target.display());
            process::exit(1);
        }
    }
}
