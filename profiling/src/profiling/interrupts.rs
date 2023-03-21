use std::sync::atomic::{AtomicBool, AtomicU32, Ordering};
use std::sync::Mutex;

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
    vm_interrupts: Mutex<Vec<VmInterrupt>>,
}

impl InterruptManager {
    pub(super) fn new(vm_interrupts: Mutex<Vec<VmInterrupt>>) -> Self {
        Self { vm_interrupts }
    }

    pub(super) fn add_interrupt(&self, interrupt: VmInterrupt) -> Result<(), (usize, VmInterrupt)> {
        let mut vm_interrupts = self.vm_interrupts.lock().unwrap();
        if let Some(index) = vm_interrupts.iter().position(|v| v == &interrupt) {
            return Err((index, interrupt));
        }
        vm_interrupts.push(interrupt);
        Ok(())
    }

    pub(super) fn remove_interrupt(&self, interrupt: VmInterrupt) -> Result<(), VmInterrupt> {
        let mut vm_interrupts = self.vm_interrupts.lock().unwrap();
        match vm_interrupts.iter().position(|v| v == &interrupt) {
            None => Err(interrupt),
            Some(index) => {
                vm_interrupts.swap_remove(index);
                Ok(())
            }
        }
    }

    pub(super) fn trigger_interrupts(&self) {
        let vm_interrupts = self.vm_interrupts.lock().unwrap();
        vm_interrupts.iter().for_each(|obj| unsafe {
            (*obj.engine_ptr).store(true, Ordering::SeqCst);
            (*obj.interrupt_count_ptr).fetch_add(1, Ordering::SeqCst);
        });
    }
}
