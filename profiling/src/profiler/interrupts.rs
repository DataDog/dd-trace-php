use std::collections::HashSet;
use std::sync::atomic::{AtomicBool, AtomicU32, Ordering};
use std::sync::{Arc, Mutex};

#[derive(Debug, Eq, PartialEq, Hash)]
pub struct VmInterrupt {
    #[cfg(target_os = "macos")]
    pub wall_sample_pending_ptr: *const AtomicBool,
    pub cpu_sample_count_ptr: *const AtomicU32,
    pub engine_ptr: *const AtomicBool,
}

impl std::fmt::Display for VmInterrupt {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        write!(f, "VmInterrupt{{")?;
        #[cfg(target_os = "macos")]
        write!(f, "{:?}, ", self.wall_sample_pending_ptr)?;
        write!(
            f,
            "{:?}, {:?}}}",
            self.cpu_sample_count_ptr, self.engine_ptr
        )
    }
}

// This is a lie, technically, but we're trying to build it safely on top of
// the PHP VM.
unsafe impl Send for VmInterrupt {}

pub(super) struct InterruptManager {
    vm_interrupts: Arc<Mutex<HashSet<VmInterrupt>>>,
}

impl InterruptManager {
    pub(super) fn new() -> Self {
        // Capacity 1 because we expect there to be 1 thread in NTS mode, and
        // if it happens to be ZTS this is just an initial capacity anyway.
        let vm_interrupts = Arc::new(Mutex::new(HashSet::with_capacity(1)));
        Self {
            vm_interrupts: vm_interrupts.clone(),
        }
    }

    /// Add the interrupt to the manager's set.
    pub(super) fn add_interrupt(&self, interrupt: VmInterrupt) {
        let mut vm_interrupts = self.vm_interrupts.lock().unwrap();
        vm_interrupts.insert(interrupt);
    }

    /// Remove the interrupt from the manager's set.
    pub(super) fn remove_interrupt(&self, interrupt: VmInterrupt) {
        let mut vm_interrupts = self.vm_interrupts.lock().unwrap();
        vm_interrupts.remove(&interrupt);
        unsafe {
            // Reset pending samples to prevent sampling during `mshutdown` (PHP 8.0 bug with
            // userland destructors), but leave the interrupt flag unchanged as other extensions
            // may have raised it.
            #[cfg(target_os = "macos")]
            (*interrupt.wall_sample_pending_ptr).store(false, Ordering::Relaxed);
            (*interrupt.cpu_sample_count_ptr).store(0, Ordering::Relaxed);
        }
    }

    #[cfg(target_os = "macos")]
    #[inline]
    pub(super) fn has_interrupts(&self) -> bool {
        !self.vm_interrupts.lock().unwrap().is_empty()
    }

    #[cfg(target_os = "macos")]
    pub(super) fn trigger_time_interrupts(&self) {
        let vm_interrupts = self.vm_interrupts.lock().unwrap();
        vm_interrupts.iter().for_each(|obj| unsafe {
            // Do not re-signal a thread until it consumes its pending sample.
            if (*obj.wall_sample_pending_ptr)
                .compare_exchange(false, true, Ordering::Release, Ordering::Relaxed)
                .is_ok()
            {
                // macOS lacks Linux's per-thread CPU timer, so preserve the previous biased
                // fallback by gathering CPU time whenever the wall timer fires.
                (*obj.cpu_sample_count_ptr).fetch_add(1, Ordering::Relaxed);
                (*obj.engine_ptr).store(true, Ordering::SeqCst);
            }
        });
    }

    /// Retained independent CPU trigger path; this PoC deliberately has no caller/producer.
    #[allow(dead_code)]
    pub(super) fn trigger_cpu_interrupts(&self) {
        let vm_interrupts = self.vm_interrupts.lock().unwrap();
        vm_interrupts.iter().for_each(|obj| unsafe {
            (*obj.cpu_sample_count_ptr).fetch_add(1, Ordering::Relaxed);
            (*obj.engine_ptr).store(true, Ordering::SeqCst);
        });
    }
}

#[cfg(all(test, target_os = "macos"))]
mod tests {
    use super::*;

    #[test]
    fn time_interrupts_are_coupled_and_coalesced() {
        let wall_pending = AtomicBool::new(false);
        let cpu_samples = AtomicU32::new(0);
        let engine = AtomicBool::new(false);
        let manager = InterruptManager::new();
        let interrupt = VmInterrupt {
            wall_sample_pending_ptr: &wall_pending,
            cpu_sample_count_ptr: &cpu_samples,
            engine_ptr: &engine,
        };
        manager.add_interrupt(interrupt);

        manager.trigger_time_interrupts();
        assert!(wall_pending.load(Ordering::Acquire));
        assert_eq!(cpu_samples.load(Ordering::Relaxed), 1);
        assert!(engine.load(Ordering::SeqCst));

        engine.store(false, Ordering::SeqCst);
        manager.trigger_time_interrupts();
        assert_eq!(cpu_samples.load(Ordering::Relaxed), 1);
        assert!(!engine.load(Ordering::SeqCst));

        wall_pending.store(false, Ordering::Release);
        manager.trigger_time_interrupts();
        assert_eq!(cpu_samples.load(Ordering::Relaxed), 2);
        assert!(engine.load(Ordering::SeqCst));
    }
}
