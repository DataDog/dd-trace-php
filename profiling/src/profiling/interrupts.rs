use crate::bindings::zend_execute_data;
use ahash::HashMapExt;
use rustc_hash::FxHashMap;
use std::ptr;
use std::sync::atomic::{AtomicBool, AtomicPtr, AtomicU32, Ordering};
use std::sync::{Arc, Mutex};

#[derive(Debug, Eq, PartialEq, Hash)]
pub struct VmInterrupt {
    pub current_execute_data: *mut zend_execute_data,
    pub interrupt_count_addr: *const AtomicU32,
    pub current_execute_data_addr: ptr::NonNull<*mut zend_execute_data>,
    pub vm_interrupt_addr: ptr::NonNull<AtomicBool>,
}

#[derive(Debug, Eq, PartialEq, Hash)]
pub struct Id {
    pub current_execute_data_addr: ptr::NonNull<*mut zend_execute_data>,
    pub vm_interrupt_addr: ptr::NonNull<AtomicBool>,
}

#[derive(Debug)]
pub struct State {
    pub current_execute_data_addr: ptr::NonNull<AtomicPtr<zend_execute_data>>,
    pub interrupt_count_addr: ptr::NonNull<AtomicU32>,
}

unsafe impl Send for Id {} // lies
unsafe impl Send for State {} // more lies

pub(super) struct Manager {
    vm_interrupts: Arc<Mutex<FxHashMap<Id, State>>>,
}

impl Manager {
    pub(super) fn new() -> Self {
        // Capacity 1 because we expect there to be 1 thread in NTS mode, and
        // if it happens to be ZTS this is just an initial capacity anyway.
        let vm_interrupts = Arc::new(Mutex::new(FxHashMap::with_capacity(1)));
        Self {
            vm_interrupts: vm_interrupts.clone(),
        }
    }

    /// Add the interrupt to the manager's set.
    pub(super) fn add_interrupt(&self, id: Id, state: State) {
        let mut vm_interrupts = self.vm_interrupts.lock().unwrap();
        vm_interrupts.insert(id, state);
    }

    /// Remove the interrupt from the manager's set.
    pub(super) fn remove_interrupt(&self, id: Id) {
        let mut vm_interrupts = self.vm_interrupts.lock().unwrap();
        vm_interrupts.remove(&id);
    }

    #[inline]
    pub(super) fn has_interrupts(&self) -> bool {
        !self.vm_interrupts.lock().unwrap().is_empty()
    }

    pub(super) fn trigger_interrupts(&self) {
        let mut vm_interrupts = self.vm_interrupts.lock().unwrap();
        for (id, state) in vm_interrupts.iter_mut() {
            // SAFETY: the interrupt_count_addr is stable and alive, and is
            // atomic on modern PHP versions (data race possible on older
            // versions, but nothing we can do).
            unsafe {
                (*state.interrupt_count_addr.as_ptr()).fetch_add(1, Ordering::SeqCst);
            }

            #[cfg(php_good_closure_invoke)]
            {
                // SAFETY: THIS IS A DATA RACE CONDITION. DO NOT DEREFERENCE
                // THIS POINTER! Compare it to EG(vm_stack_top) in the
                // interrupt handler and if they are equal, then dereference
                // the EG(vm_stack_top) pointer (after type casting).
                let current_execute_data = unsafe { *id.current_execute_data_addr.as_ptr() };

                // SAFETY: the current_execute_data_addr is stable and writeable.
                unsafe {
                    (*state.current_execute_data_addr.as_ptr())
                        .store(current_execute_data, Ordering::SeqCst)
                };
            }

            // SAFETY: the vm_interrupt_addr is stable and writeable.
            unsafe {
                (*id.vm_interrupt_addr.as_ptr()).store(true, Ordering::SeqCst);
            }
        }
    }
}
