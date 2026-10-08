//! Assertions and fatal errors.
//!
//! `sgc_assert!` states a model property. It is checked in verification
//! builds (feature `verify`) and in debug builds.
//! Under GenMC (`--cfg sgc_genmc`) a failure is reported through
//! `__VERIFIER_assert_fail`, which GenMC turns into a counterexample with a
//! trace instead of a crash.
//!
//! `production_assert!` states a production invariant. It is checked in
//! ordinary debug builds only: verification builds omit it so that models
//! pay only for the properties stated by their harness, and release builds
//! omit it too. When omitted the condition is **not
//! evaluated**: some conditions perform loads or system calls, which a
//! release or model build must not execute.

/// Checks a model property; see the module documentation.
#[allow(unused_macros)]
macro_rules! sgc_assert {
    ($cond:expr, $msg:literal $(,)?) => {
        if cfg!(any(feature = "verify", debug_assertions)) && !$cond {
            $crate::assert::assertion_failed(concat!($msg, "\0"));
        }
    };
}

/// Checks a production invariant; see the module documentation.
macro_rules! production_assert {
    ($cond:expr $(,)?) => {
        if cfg!(all(debug_assertions, not(feature = "verify"))) && !$cond {
            $crate::assert::assertion_failed(concat!(stringify!($cond), "\0"));
        }
    };
}

/// Reports a failed assertion. `msg` is NUL-terminated.
#[cold]
#[inline(never)]
#[track_caller]
pub(crate) fn assertion_failed(msg: &'static str) -> ! {
    #[cfg(sgc_genmc)]
    {
        crate::genmc::assert_fail(msg)
    }
    #[cfg(not(sgc_genmc))]
    {
        fatal(msg.trim_end_matches('\0'))
    }
}

/// Terminates the process (abort), e.g. on a participant slot
/// whose state was corrupted.
#[cold]
#[inline(never)]
#[track_caller]
pub(crate) fn fatal(msg: &'static str) -> ! {
    #[cfg(sgc_genmc)]
    {
        let _ = msg;
        crate::genmc::assert_fail("shm_gen_cache: fatal\0")
    }
    #[cfg(all(not(sgc_genmc), feature = "std"))]
    {
        use std::io::Write as _;
        let mut err = std::io::stderr().lock();
        let _ = err.write_all(b"shm_gen_cache: ");
        let _ = err.write_all(msg.as_bytes());
        let _ = err.write_all(b"\n");
        std::process::abort()
    }
    #[cfg(all(not(sgc_genmc), not(feature = "std")))]
    {
        // Without std there is no portable abort; with `panic = "abort"`
        // (every release artifact) this aborts too.
        panic!("{}", msg)
    }
}
