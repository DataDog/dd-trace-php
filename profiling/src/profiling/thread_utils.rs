use libc::sched_yield;
use log::warn;
use std::mem::MaybeUninit;
use std::thread::JoinHandle;
use std::time::{Duration, Instant};

/// Spawns a thread and masks off the signals that the Zend Engine uses.
pub fn spawn<F, T>(name: &str, f: F) -> JoinHandle<T>
where
    F: FnOnce() -> T + Send + 'static,
    T: Send + 'static,
{
    let result = std::thread::Builder::new()
        .name(name.to_string())
        .spawn(move || {
            /* Thread must not handle signals intended for PHP threads.
             * See Zend/zend_signal.c for which signals it registers.
             */
            unsafe {
                let mut sigset_mem = MaybeUninit::uninit();
                let sigset = sigset_mem.as_mut_ptr();
                libc::sigemptyset(sigset);

                const SIGNALS: [libc::c_int; 6] = [
                    libc::SIGPROF, // todo: SIGALRM on __CYGWIN__/__PHASE__
                    libc::SIGHUP,
                    libc::SIGINT,
                    libc::SIGTERM,
                    libc::SIGUSR1,
                    libc::SIGUSR2,
                ];

                for signal in SIGNALS {
                    libc::sigaddset(sigset, signal);
                }
                libc::pthread_sigmask(libc::SIG_BLOCK, sigset, std::ptr::null_mut());
            }
            f()
        });

    match result {
        Ok(handle) => handle,
        Err(err) => panic!("Failed to spawn thread {name}: {err}"),
    }
}

/// Waits for the handle to be finished. If finished, it will join the handle.
/// Otherwise, it will leak the handle.
/// # Panics
/// Panics if the thread being joined has panic'd.
pub fn join_timeout(handle: JoinHandle<()>, timeout: Duration, impact: &str) {
    // After notifying the other threads, it's likely they'll need some time
    // to respond adequately. Joining on the JoinHandle is supposed to be the
    // correct way to do this, but we've observed this can panic:
    // https://github.com/DataDog/dd-trace-php/issues/1919
    // Thus far, we have not been able to reproduce it and address the root
    // cause. So, for now, mitigate it instead with a busy loop.
    let start = Instant::now();
    while !handle.is_finished() {
        unsafe { sched_yield() };
        if start.elapsed() >= timeout {
            let name = handle.thread().name().unwrap_or("{unknown}");
            warn!("Timeout of {timeout:?} reached when joining thread '{name}'. {impact}");
            return;
        }
    }

    if let Err(err) = handle.join() {
        std::panic::resume_unwind(err)
    }
}
