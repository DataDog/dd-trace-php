use std::collections::HashSet;
use std::sync::atomic::{AtomicBool, AtomicU32, Ordering};
use std::sync::{Arc, Mutex};

#[derive(Debug, Eq, PartialEq, Hash)]
pub struct VmInterrupt {
    pub interrupt_count_ptr: *const AtomicU32,
    pub engine_ptr: *const AtomicBool,
}

impl std::fmt::Display for VmInterrupt {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        write!(
            f,
            "VmInterrupt{{{:?}, {:?}}}",
            self.interrupt_count_ptr, self.engine_ptr
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
            // Reset interrupt counter to prevent sampling during `mshutdown` (PHP 8.0 bug with
            // userland destructors), but leave the interrupt flag unchanged as other extensions
            // may have raised it.
            (*interrupt.interrupt_count_ptr).store(0, Ordering::SeqCst);
        }
    }

    #[inline]
    pub(super) fn has_interrupts(&self) -> bool {
        !self.vm_interrupts.lock().unwrap().is_empty()
    }

    pub(super) fn trigger_interrupts(&self) {
        let vm_interrupts = self.vm_interrupts.lock().unwrap();
        vm_interrupts.iter().for_each(|obj| unsafe {
            (*obj.interrupt_count_ptr).fetch_add(1, Ordering::SeqCst);
            (*obj.engine_ptr).store(true, Ordering::SeqCst);
        });
    }
}
