//! GenMC's model-checking interface, as used by the library itself
//! (`--cfg sgc_genmc`, bitcode builds only: the symbols exist only inside
//! GenMC, so native builds must never reference them).

use core::ffi::{c_char, c_int};

unsafe extern "C" {
    /// What GenMC's `assert.h` expands `assert()` to.
    fn __VERIFIER_assert_fail(msg: *const c_char, file: *const c_char, line: c_int) -> !;
}

/// Reports `msg` (NUL-terminated) as an assertion violation.
#[inline(always)]
pub(crate) fn assert_fail(msg: &'static str) -> ! {
    // SAFETY: both strings are NUL-terminated and 'static.
    unsafe { __VERIFIER_assert_fail(msg.as_ptr().cast(), c"shm_gen_cache".as_ptr(), 0) }
}
