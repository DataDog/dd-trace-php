//! A private replacement for glibc's `__cxa_thread_atexit_impl`.
//!
//! Rust registers the destructor of every `thread_local!` holding a `Drop` type with
//! `__cxa_thread_atexit_impl`. glibc's implementation bumps `l_tls_dtor_count` on the link map of
//! the calling object and refuses to unmap it until every registered destructor has run -- so a
//! library that touched a single `thread_local` from a thread that never exits (the PHP request
//! thread, say) can never be `dlclose()`d again. That is how `libdatadog_php.so` and
//! `datadog-profiling.so` ended up permanently resident, which in turn made an Apache reload
//! re-enter startup against stale state.
//!
//! Linking this crate replaces that mechanism with a `pthread_key_t` whose destructor glibc invokes
//! on thread exit -- the same guarantee, without the reference on the link map. The implementation
//! itself is in `src/cxa_thread_atexit.c`, because the override only takes effect if the definition
//! is hidden; see the comment at the top of that file.
//!
//! Note that linking the crate is not enough on its own: the references Rust's std emits are
//! *weak*, and a weak undefined reference does not pull a member out of an archive (which is what
//! an rlib is). Every artifact must therefore call [`init`] -- or at least [`flush`] -- from
//! somewhere, which doubles as the point where the exit hooks get installed.

#[cfg(all(target_os = "linux", target_env = "gnu"))]
mod imp {
    extern "C" {
        fn dd_cxa_thread_atexit_init();
        fn dd_cxa_thread_atexit_flush();
    }

    /// Takes over thread-local destructor registration for this shared object and installs the
    /// process-exit/unload hooks. Idempotent, and safe to call from any thread.
    pub fn init() {
        // SAFETY: no arguments, no return value, and the callee is thread-safe.
        unsafe { dd_cxa_thread_atexit_init() }
    }

    /// Runs the destructors pending for the calling thread, ahead of the thread actually exiting.
    /// PHP uses this to tie them to *PHP's* notion of a thread ending (`PHP_GSHUTDOWN`).
    pub fn flush() {
        // SAFETY: as above. A destructor could in principle re-enter registration, which the
        // implementation accounts for.
        unsafe { dd_cxa_thread_atexit_flush() }
    }
}

#[cfg(all(target_os = "linux", target_env = "gnu"))]
pub use imp::{flush, init};

/// Nothing to do: platforms without glibc either have no `__cxa_thread_atexit_impl` or do not tie
/// destructor registration to the lifetime of the shared object.
#[cfg(not(all(target_os = "linux", target_env = "gnu")))]
pub fn init() {}

/// See [`init`].
#[cfg(not(all(target_os = "linux", target_env = "gnu")))]
pub fn flush() {}
