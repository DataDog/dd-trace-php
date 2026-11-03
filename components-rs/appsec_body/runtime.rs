//! Private allocator and panic runtime, localized with the rest of this
//! static library before linking it into the tracer.
use crate::{AppsecBodyAllocator, AppsecBodyMessagePack, AppsecBodyStatus};
use std::alloc::{GlobalAlloc, Layout, System};
use std::cell::{Cell, UnsafeCell};
use std::mem::MaybeUninit;
use std::panic::{catch_unwind, UnwindSafe};
use std::ptr;
use std::sync::Once;

#[global_allocator]
static ALLOCATOR: ParserAllocator = ParserAllocator;

// Serves every allocation made while panicking: the message, the payload box
// and the exception panic_unwind boxes (56 bytes on 64-bit Unix). Messages
// grow by doubling and the reserve never reuses memory.
const UNWIND_RESERVE: usize = 2048;

thread_local! {
    // No allocation or destructor is needed to access this TLS slot.
    static CURRENT: Cell<*const State> = const { Cell::new(ptr::null()) };
}

pub(super) fn run(
    allocator: AppsecBodyAllocator,
    parse: impl FnOnce() -> AppsecBodyMessagePack + UnwindSafe,
) -> AppsecBodyMessagePack {
    // Calls must not nest. Discard any pointer left by an abandoned invocation
    // before runtime initialization, which must allocate through System.
    CURRENT.with(|current| current.set(ptr::null()));

    static INIT: Once = Once::new();
    INIT.call_once(|| {
        // GlobalAlloc must NEVER unwind. The allocation-error hook runs after
        // the allocator has returned NULL, outside its nounwind shim.
        std::alloc::set_alloc_error_hook(|layout| oom(layout));
        // The default hook prints the panic and allocates while doing so.
        std::panic::set_hook(Box::new(|_| {}));
    });
    // std's panic machinery uses thread::current(). Its persistent TLS must
    // be initialized with System, not with request-lifetime Zend memory.
    drop(std::thread::current());

    let state = State {
        allocator,
        reserve: Reserve(UnsafeCell::new([MaybeUninit::uninit(); UNWIND_RESERVE])),
        used: Cell::new(0),
        failed: Cell::new(false),
    };
    CURRENT.with(|current| current.set(&state));
    match catch_unwind(parse) {
        Ok(body) if state.failed.get() => {
            unsafe { crate::ddog_appsec_body_private_drop(body) };
            AppsecBodyMessagePack::empty(AppsecBodyStatus::OutOfMemory)
        }
        Ok(body) => AppsecBodyMessagePack {
            free: Some(allocator.free),
            ..body
        },
        Err(payload) => {
            let status = if state.failed.get() {
                AppsecBodyStatus::OutOfMemory
            } else {
                AppsecBodyStatus::InternalError
            };
            // The payload may live in the reserve; drop it before State.
            drop(payload);
            AppsecBodyMessagePack::empty(status)
        }
    }
}

struct State {
    allocator: AppsecBodyAllocator,
    reserve: Reserve,
    used: Cell<usize>,
    failed: Cell<bool>,
}

#[repr(align(16))]
struct Reserve(UnsafeCell<[MaybeUninit<u8>; UNWIND_RESERVE]>);

impl State {
    // Bump-allocates while panicking; nothing here is freed.
    unsafe fn reserve_alloc(&self, layout: Layout) -> *mut u8 {
        let base = self.reserve.0.get().cast::<u8>();
        // The x86_64 unwinder expects malloc's 16-byte alignment even though
        // Rust's exception layout only requests 8-byte alignment.
        let align = layout.align().max(16);
        let offset = (base.addr() + self.used.get()).next_multiple_of(align) - base.addr();
        if offset > UNWIND_RESERVE || layout.size() > UNWIND_RESERVE - offset {
            // An exhausted reserve cannot support another unwind.
            std::process::abort();
        }
        self.used.set(offset + layout.size());
        base.add(offset)
    }

    fn in_reserve(&self, ptr: *mut u8) -> bool {
        let base = self.reserve.0.get().addr();
        (base..base + UNWIND_RESERVE).contains(&ptr.addr())
    }
}

impl Drop for State {
    fn drop(&mut self) {
        CURRENT.with(|current| current.set(ptr::null()));
    }
}

fn oom(_: Layout) -> ! {
    CURRENT.with(|current| {
        let state = current.get();
        if state.is_null() {
            std::process::abort();
        }
        unsafe { (*state).failed.set(true) };
    });
    // A zero-sized payload avoids formatting and allocating an OOM message.
    std::panic::panic_any(OutOfMemory)
}

struct OutOfMemory;

// The backend is chosen from CURRENT, so no block may cross a run()
// boundary, except the output buffer, which carries its own free callback.
struct ParserAllocator;

unsafe impl GlobalAlloc for ParserAllocator {
    unsafe fn alloc(&self, layout: Layout) -> *mut u8 {
        CURRENT.with(|current| {
            let Some(state) = current.get().as_ref() else {
                return System.alloc(layout);
            };
            // std raises the panic count before it formats the message, and
            // reading it does not allocate.
            if std::thread::panicking() {
                return state.reserve_alloc(layout);
            }
            let ptr = (state.allocator.allocate)(state.allocator.context, layout.size());
            if ptr.is_null() {
                state.failed.set(true);
            } else if ptr.addr() & (layout.align() - 1) != 0 {
                // The callback only guarantees malloc-like alignment.
                (state.allocator.free)(ptr);
                return ptr::null_mut();
            }
            ptr
        })
    }

    unsafe fn dealloc(&self, ptr: *mut u8, layout: Layout) {
        CURRENT.with(|current| match current.get().as_ref() {
            Some(state) if state.in_reserve(ptr) => {}
            Some(state) => (state.allocator.free)(ptr),
            None => System.dealloc(ptr, layout),
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::ffi::c_void;

    extern "C" {
        fn malloc(size: usize) -> *mut u8;
        fn free(ptr: *mut u8);
    }

    unsafe extern "C" fn allocate(fail: *mut c_void, size: usize) -> *mut u8 {
        if *fail.cast::<bool>() {
            ptr::null_mut()
        } else {
            malloc(size)
        }
    }

    unsafe extern "C" fn release(ptr: *mut u8) {
        free(ptr)
    }

    static LONG: [u8; 300] = [b'x'; 300];

    #[test]
    fn replaces_abandoned_state() {
        CURRENT.with(|current| current.set(ptr::NonNull::<State>::dangling().as_ptr()));
        let mut fail = false;
        let allocator = AppsecBodyAllocator {
            context: (&mut fail as *mut bool).cast(),
            allocate,
            free: release,
        };
        let body = run(allocator, || crate::json::parse(b"[1]", 30));
        // Clear even on failure so the test harness never sees stale TLS.
        let remaining = CURRENT.with(|current| current.replace(ptr::null()));
        let status = body.status;
        unsafe { crate::ddog_appsec_body_private_drop(body) };
        assert!(remaining.is_null());
        assert_eq!(status, AppsecBodyStatus::Complete);
    }

    #[test]
    fn panics_do_not_depend_on_the_allocator() {
        let panics: [fn(); 4] = [
            || panic!("literal"),
            || panic!("formatted {}", std::hint::black_box(1)),
            || {
                let _ = [0u8][std::hint::black_box(1)];
            },
            || std::hint::black_box(Err::<(), _>(std::str::from_utf8(&LONG).unwrap())).unwrap(),
        ];
        for mut fail in [false, true] {
            for panic in panics {
                let allocator = AppsecBodyAllocator {
                    context: (&mut fail as *mut bool).cast(),
                    allocate,
                    free: release,
                };
                let body = run(allocator, move || {
                    panic();
                    AppsecBodyMessagePack::empty(AppsecBodyStatus::Complete)
                });
                assert_eq!(body.status, AppsecBodyStatus::InternalError);
            }
        }
        // run() installs a silent hook for the whole test binary.
        drop(std::panic::take_hook());
    }
}
