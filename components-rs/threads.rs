// Shared by PHP background workers: no PHP signal handler may run on them.
#[cfg(unix)]
use core::mem::MaybeUninit;
use std::thread::JoinHandle;

/// Spawns a thread with asynchronous signals masked.
pub(crate) fn spawn<F, T>(name: &str, f: F) -> std::io::Result<JoinHandle<T>>
where
    F: FnOnce() -> T + Send + 'static,
    T: Send + 'static,
{
    #[cfg(unix)]
    let previous_mask_mem = {
        /* This helper thread has no valid PHP/TSRM context, so it must not run
         * any PHP signal handler. Block asynchronous signals in the parent before
         * spawning: the new thread inherits the mask atomically with its creation.
         * Masking only inside the new thread leaves a window in which PHP can
         * install and trigger an asynchronous handler before the helper runs.
         *
         * Synchronous fault signals remain unblocked so a genuine fault on the
         * helper thread is still reported, for example by the crashtracker.
         */
        let mut sigset_mem = MaybeUninit::uninit();
        let mut previous_mask_mem = MaybeUninit::uninit();
        let sigset = sigset_mem.as_mut_ptr();

        // SAFETY: both signal sets point to valid, suitably aligned storage.
        let mask_result = unsafe {
            libc::sigfillset(sigset);

            const KEEP_UNBLOCKED: [libc::c_int; 6] = [
                libc::SIGSEGV,
                libc::SIGBUS,
                libc::SIGFPE,
                libc::SIGILL,
                libc::SIGABRT,
                libc::SIGTRAP,
            ];

            for signal in KEEP_UNBLOCKED {
                libc::sigdelset(sigset, signal);
            }

            libc::pthread_sigmask(libc::SIG_BLOCK, sigset, previous_mask_mem.as_mut_ptr())
        };
        assert_eq!(
            mask_result, 0,
            "failed to block signals before spawning {name}"
        );

        previous_mask_mem
    };
    let result = std::thread::Builder::new().name(name.to_string()).spawn(f);

    #[cfg(unix)]
    {
        // SAFETY: pthread_sigmask initialized previous_mask_mem above, and this
        // restores the spawning PHP thread regardless of whether spawn succeeded.
        let restore_result = unsafe {
            libc::pthread_sigmask(
                libc::SIG_SETMASK,
                previous_mask_mem.as_ptr(),
                std::ptr::null_mut(),
            )
        };
        assert_eq!(
            restore_result, 0,
            "failed to restore signal mask after spawning {name}"
        );
    }
    result
}

#[cfg(all(test, unix))]
mod tests {
    use super::*;

    fn signal_is_blocked(signal: libc::c_int) -> bool {
        let mut current = MaybeUninit::uninit();
        // SAFETY: querying the calling thread's mask writes a valid signal set.
        unsafe {
            assert_eq!(
                libc::pthread_sigmask(libc::SIG_BLOCK, std::ptr::null(), current.as_mut_ptr()),
                0
            );
            libc::sigismember(current.as_ptr(), signal) == 1
        }
    }

    #[test]
    fn helper_masks_application_signals_and_restores_the_caller() {
        let before = signal_is_blocked(libc::SIGTERM);
        assert!(
            spawn("signal-mask-test", || signal_is_blocked(libc::SIGTERM))
                .unwrap()
                .join()
                .unwrap()
        );
        assert_eq!(signal_is_blocked(libc::SIGTERM), before);
    }
}
