//! Production assertions and fatal errors.
//!
//! `production_assert!` states a production invariant. It is checked in
//! ordinary debug builds only: verification builds (feature `verify`) omit it
//! so that models pay only for the properties stated by their harness, and
//! release builds omit it too. When omitted the condition is **not
//! evaluated**: some conditions perform loads or system calls, which a
//! release or model build must not execute.
//!
//! [`fatal`] reports a state the protocol never produces, e.g. a participant
//! slot whose state changed under its owner. With `std` it aborts the process;
//! otherwise, and in GenMC builds (`--cfg sgc_genmc`), it panics. The GenMC
//! runner turns `core`'s panic entry points into assertion failures, so the
//! model checker records a safety violation.

/// Checks a production invariant; see the module documentation.
macro_rules! production_assert {
    ($cond:expr $(,)?) => {
        if cfg!(all(debug_assertions, not(feature = "verify"))) && !$cond {
            $crate::assert::fatal(stringify!($cond));
        }
    };
}

/// Terminates the process (abort), e.g. on a participant slot
/// whose state was corrupted.
#[cold]
#[inline(never)]
pub(crate) fn fatal(msg: &'static str) -> ! {
    #[cfg(all(feature = "std", not(sgc_genmc)))]
    {
        use std::io::Write as _;
        let mut err = std::io::stderr().lock();
        let _ = err.write_all(b"shm_gen_cache: ");
        let _ = err.write_all(msg.as_bytes());
        let _ = err.write_all(b"\n");
        std::process::abort()
    }
    #[cfg(not(all(feature = "std", not(sgc_genmc))))]
    {
        // Without std there is no portable abort; with `panic = "abort"`
        // (every release artifact) this aborts too.
        panic!("{}", msg)
    }
}
