// Copyright 2021-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

/// Symbols the AppSec helper resolves via dlsym(RTLD_DEFAULT, …).
/// These are the only symbols that need to be in the binary's dynamic symbol table.
const EXPORTED_SYMBOLS: &[&str] = &[
    "ddog_Error_drop",
    "ddog_Error_message",
    "ddog_remote_config_path",
    "ddog_remote_config_path_free",
    "ddog_remote_config_read",
    "ddog_remote_config_reader_drop",
    "ddog_remote_config_reader_for_path",
    "ddog_set_rc_notify_fn",
    "ddog_sidecar_connect",
    "ddog_sidecar_enqueue_telemetry_log",
    "ddog_sidecar_enqueue_telemetry_metric",
    "ddog_sidecar_enqueue_telemetry_point",
    "ddog_sidecar_ping",
    "ddog_sidecar_transport_drop",
];

fn main() {
    let target_os = std::env::var("CARGO_CFG_TARGET_OS").unwrap_or_default();
    let out_dir = std::env::var("OUT_DIR").expect("OUT_DIR not set");

    match target_os.as_str() {
        "linux" => {
            // GNU ld --dynamic-list: { sym; }; syntax, no leading underscore.
            let mut content = "{\n".to_owned();
            for sym in EXPORTED_SYMBOLS {
                content.push_str(&format!("  {sym};\n"));
            }
            content.push_str("};\n");

            let path = std::path::Path::new(&out_dir).join("datadog-ipc-helper.sym");
            std::fs::write(&path, content).expect("failed to write dynamic-list file");
            println!("cargo:rustc-link-arg=-Wl,--dynamic-list={}", path.display());
        }
        "macos" => {
            // macOS ld64 -exported_symbols_list: one symbol per line, with leading _.
            let content: String = EXPORTED_SYMBOLS
                .iter()
                .map(|s| format!("_{s}\n"))
                .collect();

            let path = std::path::Path::new(&out_dir).join("datadog-ipc-helper-exports.txt");
            std::fs::write(&path, content).expect("failed to write exported_symbols_list file");
            println!(
                "cargo:rustc-link-arg=-Wl,-exported_symbols_list,{}",
                path.display()
            );
        }
        _ => {}
    }
}
