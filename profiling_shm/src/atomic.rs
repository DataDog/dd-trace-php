#[cfg(feature = "loom")]
pub(crate) use loom::sync::atomic::{fence, AtomicU32, AtomicU64, Ordering};

#[cfg(not(feature = "loom"))]
pub(crate) use core::sync::atomic::{fence, AtomicU32, AtomicU64, Ordering};

pub(crate) fn spin_loop() {
    #[cfg(feature = "loom")]
    loom::hint::spin_loop();

    #[cfg(not(feature = "loom"))]
    core::hint::spin_loop();
}
