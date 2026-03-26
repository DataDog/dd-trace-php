use core::sync::atomic::{AtomicU32, Ordering};

pub struct SpinGuard<'a>(&'a AtomicU32);

/// Number of CAS attempts (first attempt + retries with `spin_loop` hint).
/// Small enough to be safe inside a signal handler; large enough to ride over
/// the common case where the lock holder is mid-intern (~50–200 ns).
const SPIN_ATTEMPTS: u32 = 8;

/// Try to acquire the lock.  Retries up to `SPIN_ATTEMPTS` times using the
/// CPU spin-loop hint before giving up.  Never blocks indefinitely — this is
/// the async-signal-safety mechanism: a signal handler that cannot acquire
/// the lock after all attempts returns `None` (→ `WouldBlock`) rather than
/// deadlocking.
pub fn try_lock(lock: &AtomicU32) -> Option<SpinGuard<'_>> {
    let mut attempts = SPIN_ATTEMPTS;
    loop {
        if lock
            .compare_exchange_weak(0, 1, Ordering::Acquire, Ordering::Relaxed)
            .is_ok()
        {
            return Some(SpinGuard(lock));
        }
        attempts -= 1;
        if attempts == 0 {
            return None;
        }
        core::hint::spin_loop();
    }
}

impl Drop for SpinGuard<'_> {
    fn drop(&mut self) {
        self.0.store(0, Ordering::Release);
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use core::sync::atomic::AtomicU32;

    #[test]
    fn acquire_and_release() {
        let lock = AtomicU32::new(0);
        let guard = try_lock(&lock);
        assert!(guard.is_some());
        assert_eq!(lock.load(Ordering::Relaxed), 1);
        drop(guard);
        assert_eq!(lock.load(Ordering::Relaxed), 0);
    }

    #[test]
    fn contended_returns_none() {
        let lock = AtomicU32::new(0);
        let _guard = try_lock(&lock).unwrap();
        assert!(try_lock(&lock).is_none());
    }
}
