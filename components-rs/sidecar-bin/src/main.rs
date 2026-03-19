// Copyright 2021-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

// Force-link the appsec FFI symbols so dlsym(RTLD_DEFAULT, …) can find them.
// The `use` is sufficient to prevent dead-code elimination; the functions
// themselves are `#[no_mangle]` and therefore kept by the linker.
use datadog_sidecar_appsec_ffi as _;
use libdd_common_ffi as _; // provides ddog_Error_drop / ddog_Error_message

fn main() {
    let args: Vec<String> = std::env::args().collect();
    match args.get(1).map(|s| s.as_str()) {
        Some("ipc-helper") => {
            #[cfg(unix)]
            datadog_sidecar::run_daemon_from_passed_fd();
            #[cfg(windows)]
            datadog_sidecar::run_daemon_from_passed_handle();
        }
        Some("crashtracker") => crashtracker_receiver_main(),
        Some(other) => {
            eprintln!("datadog-ipc-helper: unknown subcommand '{other}'");
            eprintln!("usage: datadog-ipc-helper <ipc-helper|crashtracker>");
            unsafe { libc::exit(1) };
        }
        None => {
            eprintln!("datadog-ipc-helper: subcommand required");
            eprintln!("usage: datadog-ipc-helper <ipc-helper|crashtracker>");
            unsafe { libc::exit(1) };
        }
    }
}

fn crashtracker_receiver_main() {
    unsafe {
        if let Err(e) = libdd_crashtracker::receiver_entry_point_stdin() {
            eprintln!("{e}");
            libc::exit(1);
        }
    }
}
