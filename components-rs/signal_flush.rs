//! PHP process policy around libdatadog's raw-thread-safe flush exchange.

use datadog_sidecar::service::signal_flush::SignalFlush;

/// Execute the prepared flush. For a default signal disposition, terminate the process afterward.
/// The object must remain alive until the raw worker exits; custom handlers use normal shutdown
/// to join that worker. `_exit` terminates the whole process without runtime cleanup.
/// The signal handler must reject inherited state before starting a worker after fork.
#[no_mangle]
#[inline(never)]
pub unsafe extern "C-unwind" fn datadog_sidecar_signal_flush_run(
    flush: &SignalFlush,
    terminate_process: bool,
) -> i32 {
    let result = flush.run();
    if terminate_process {
        libc::_exit(0);
    }
    result
}
