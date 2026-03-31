use crate::atomic::{AtomicU32, Ordering};

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
        crate::atomic::spin_loop();
    }
}

impl Drop for SpinGuard<'_> {
    fn drop(&mut self) {
        self.0.store(0, Ordering::Release);
    }
}

#[cfg(all(test, not(feature = "loom")))]
mod tests {
    use super::*;
    use crate::atomic::AtomicU32;

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

#[cfg(all(test, feature = "loom"))]
mod loom_tests {
    use super::*;
    use crate::atomic::{AtomicU32, Ordering};
    use loom::sync::Arc;

    /// At most one thread may be inside the critical section at a time.
    /// A concurrent counter is incremented on entry and decremented on exit;
    /// loom verifies the counter never reaches 2.
    #[test]
    fn mutual_exclusion() {
        loom::model(|| {
            let lock = Arc::new(AtomicU32::new(0));
            let inside = Arc::new(loom::sync::atomic::AtomicU32::new(0));
            let lock2 = Arc::clone(&lock);
            let inside2 = Arc::clone(&inside);

            let t1 = loom::thread::spawn(move || {
                if let Some(_g) = try_lock(&lock2) {
                    let prev = inside2.fetch_add(1, Ordering::Relaxed);
                    assert_eq!(prev, 0, "thread 1: critical section already occupied");
                    inside2.fetch_sub(1, Ordering::Relaxed);
                }
            });

            if let Some(_g) = try_lock(&lock) {
                let prev = inside.fetch_add(1, Ordering::Relaxed);
                assert_eq!(prev, 0, "thread 0: critical section already occupied");
                inside.fetch_sub(1, Ordering::Relaxed);
            }

            t1.join().unwrap();
        });
    }

    /// After a guard is dropped, a subsequent `try_lock` must succeed because
    /// the Release store in Drop is visible via the Acquire CAS in `try_lock`.
    #[test]
    fn release_visible_after_drop() {
        loom::model(|| {
            let lock = Arc::new(AtomicU32::new(0));
            let lock2 = Arc::clone(&lock);
            let t1 = loom::thread::spawn(move || {
                let _g = try_lock(&lock2); // acquire then drop
            });
            t1.join().unwrap();
            assert!(
                try_lock(&lock).is_some(),
                "lock not released after guard drop"
            );
        });
    }
}
