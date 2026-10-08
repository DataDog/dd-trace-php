//! Test harness for the GenMC suite.
//!
//! Every test is a `#![no_std]`, `#![no_main]` program that defines an
//! `extern "C" fn main` and uses only this module to create threads, state
//! properties and constrain the explored executions. The same source is
//! built two ways (see `tests/genmc.rs` and README.md):
//!
//! * GenMC bitcode (`--cfg sgc_genmc`): threads are GenMC threads, a failed
//!   [`check!`] or reached [`witness!`] is `__VERIFIER_assert_fail`, and
//!   [`assume`] prunes executions;
//! * native smoke executable: pthreads, failures print and abort, `assume`
//!   is a no-op (assumptions are path constraints for the model checker).
//!
//! # Universal properties and witnesses
//!
//! [`check!`] states a property every complete execution must satisfy. A
//! passing safety run does not show that an interesting outcome can happen
//! at all, so tests also name the outcomes that must be *reachable* with
//! [`witness!`]:
//!
//! ```ignore
//! witness!("V1", winner == V1);   // some execution must end with V1
//! ```
//!
//! The runner builds the test once without witnesses (safety: every
//! `witness!` compiles to nothing) and once per witness name `N` with
//! `--cfg genmc_witness="N"`. In that build only `witness!("N", outcome)`
//! is active, and it fails with the message `GENMC_WITNESS_N` when
//! `outcome` holds. GenMC reporting exactly that violation proves the
//! outcome reachable. Place witnesses after
//! all checks of the execution, so a witness counterexample is also an
//! execution in which every check passed. Witnesses add no shared accesses
//! or synchronisation.
//!
//! # Threads
//!
//! Threads are spawned inside a [`scope`] call, as with
//! `std::thread::scope`: [`Scope::spawn`] runs `f(&mut arg)` on a new
//! thread; `f` must be a function item or a non-capturing closure (a
//! zero-sized type), so the thread start routine is a plain monomorphised
//! function and no allocation or boxing is involved. `arg` stays borrowed
//! until the scope ends, which joins every thread not joined yet; so no
//! thread can outlive its argument, even if its [`Thread`] is leaked.
//! [`Scope::spawn_symmetric`] declares the new thread a symmetric copy of
//! an earlier one running the same `f` (GenMC's symmetry reduction); it is
//! a plain spawn in witness builds, where symmetry could hide the
//! distinguishing outcome, and natively.
//!
//! ```ignore
//! let mut first = Insert { .. };
//! let mut second = Insert { .. };
//! scope(|s| {
//!     let t1 = s.spawn(insert, &mut first);
//!     let t2 = s.spawn_symmetric(insert, &mut second, &t1);
//!     t1.join();
//!     t2.join();
//! });
//! ```
//!
//! Argument types must be `Send`. A test that hands a participant
//! registration (which is bound to its registering thread, hence `!Send`)
//! to a worker wraps it in [`Lent`]; see there.

#![no_std]

use core::cell::Cell;
use core::ffi::{c_int, c_void};
use core::marker::PhantomData;
use core::ops::{Deref, DerefMut};
use core::sync::atomic::AtomicU32;
use core::sync::atomic::Ordering::Relaxed;

use shm_gen_cache::{Params, ParticipantLock};

#[cfg(not(sgc_genmc))]
extern crate std;

/// States a property of every complete execution; `check!(cond)` or
/// `check!(cond, "explanation")`. The failure message names the source
/// location and the condition.
#[macro_export]
macro_rules! check {
    ($cond:expr $(, $why:literal)? $(,)?) => {
        if !$cond {
            $crate::fail(concat!(
                file!(),
                ":",
                line!(),
                ": check failed: ",
                stringify!($cond),
                $(" (", $why, ")",)?
                "\0"
            ));
        }
    };
}

/// Checks that a `Result` is `Ok` or an `Option` is `Some` and returns the
/// value (`unwrap()` without the formatting machinery of a panic).
#[macro_export]
macro_rules! check_ok {
    ($e:expr $(,)?) => {
        match $crate::Success::success($e) {
            Some(value) => value,
            None => $crate::fail(concat!(
                file!(),
                ":",
                line!(),
                ": check failed: ",
                stringify!($e),
                " succeeds\0"
            )),
        }
    };
}

/// Names an outcome that some complete execution must reach; see the
/// module documentation. `name` must be an upper-case identifier-like
/// string literal, unique within the test.
#[macro_export]
macro_rules! witness {
    ($name:literal, $outcome:expr $(,)?) => {
        if cfg!(genmc_witness = $name) && $outcome {
            $crate::fail(concat!("GENMC_WITNESS_", $name, "\0"));
        }
    };
}

/// The values [`check_ok!`] accepts.
pub trait Success {
    /// The success value.
    type Value;
    /// The success value, if any.
    fn success(self) -> Option<Self::Value>;
}

impl<T, E> Success for Result<T, E> {
    type Value = T;
    #[inline(always)]
    fn success(self) -> Option<T> {
        self.ok()
    }
}

impl<T> Success for Option<T> {
    type Value = T;
    #[inline(always)]
    fn success(self) -> Option<T> {
        self
    }
}

/// Reports a failed property. `msg` must be NUL-terminated.
#[cold]
#[inline(never)]
pub fn fail(msg: &'static str) -> ! {
    #[cfg(sgc_genmc)]
    // SAFETY: both strings are NUL-terminated and 'static.
    unsafe {
        genmc::__VERIFIER_assert_fail(msg.as_ptr().cast(), c"harness".as_ptr(), 0)
    }
    #[cfg(not(sgc_genmc))]
    {
        std::eprintln!("{}", msg.trim_end_matches('\0'));
        std::process::abort()
    }
}

/// Restricts exploration to executions in which `condition` holds.
/// Natively a no-op: native runs are smoke checks.
/// Every use must be explained in the test's opening comment.
#[inline(always)]
pub fn assume(condition: bool) {
    #[cfg(sgc_genmc)]
    // SAFETY: plain GenMC builtin.
    unsafe {
        genmc::__VERIFIER_assume_internal(condition, genmc::ASSUME_USER)
    };
    #[cfg(not(sgc_genmc))]
    let _ = condition;
}

/// Ends the calling thread immediately, without unwinding or running
/// destructors: under GenMC the thread simply stops (a crashed process);
/// natively it parks forever without further shared accesses.
pub fn abandon_thread() -> ! {
    #[cfg(sgc_genmc)]
    // SAFETY: plain GenMC builtin.
    unsafe {
        genmc::__VERIFIER_thread_exit(core::ptr::null_mut())
    }
    #[cfg(not(sgc_genmc))]
    loop {
        std::thread::park();
    }
}

/// An opaque thread identity, comparable with `==`.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub struct ThreadId(sys::Tid);

/// The calling thread's identity.
#[inline]
pub fn current_thread() -> ThreadId {
    ThreadId(sys::current())
}

/// The most threads one [`scope`] may spawn.
pub const MAX_THREADS: usize = 8;

/// Runs `f` with a [`Scope`] for spawning threads that borrow from the
/// caller (like `std::thread::scope`). When `f` returns, every thread the
/// scope spawned whose [`Thread`] nobody joined is joined, in spawn order;
/// join explicitly with [`Thread::join`] for any other order.
///
/// Arguments lent to the threads must be declared outside `f`, so their
/// borrows outlive the scope.
pub fn scope<'env, R>(f: impl for<'scope> FnOnce(&'scope Scope<'scope, 'env>) -> R) -> R {
    let scope = Scope {
        threads: [const { Spawned::new() }; MAX_THREADS],
        spawned: Cell::new(0),
        _scope: PhantomData,
        _env: PhantomData,
    };
    // Joins the remaining threads when `f` returns, and also if it
    // unwinds (natively), before anything it borrows goes away.
    let _join = JoinRemaining(&scope);
    f(&scope)
}

/// Spawns threads borrowing from outside a [`scope`] call.
pub struct Scope<'scope, 'env: 'scope> {
    threads: [Spawned; MAX_THREADS],
    spawned: Cell<usize>,
    // Invariant in both, as for `std::thread::Scope`.
    _scope: PhantomData<&'scope mut &'scope ()>,
    _env: PhantomData<&'env mut &'env ()>,
}

/// A scope's record of one spawned thread.
struct Spawned {
    /// Written once, by the spawning thread, before the [`Thread`] exists.
    tid: Cell<Option<sys::Tid>>,
    /// Set by whoever takes over the join: [`Thread::join`] or
    /// [`Thread::leak`] (possibly on another thread of the scope), or the
    /// end of the scope. A single RMW, so each thread is joined once.
    claimed: AtomicU32,
}

impl Spawned {
    const fn new() -> Self {
        Spawned {
            tid: Cell::new(None),
            claimed: AtomicU32::new(0),
        }
    }
}

/// Takes over the join of a thread: true for exactly one caller.
#[inline]
fn claim(claimed: &AtomicU32) -> bool {
    claimed.swap(1, Relaxed) == 0
}

impl<'scope> Scope<'scope, '_> {
    /// Runs `f(arg)` on a new thread. `f` must be zero-sized (a function
    /// item or non-capturing closure).
    pub fn spawn<A: Send, F: Fn(&mut A) + Copy>(
        &'scope self,
        f: F,
        arg: &'scope mut A,
    ) -> Thread<'scope, F> {
        let _ = f;
        self.start::<A, F>(arg, None)
    }

    /// Like [`Scope::spawn`], declaring the thread symmetric to
    /// `predecessor` (same code, arguments that are interchangeable for
    /// every check). Symmetry is used only by the safety build under GenMC.
    pub fn spawn_symmetric<A: Send, F: Fn(&mut A) + Copy>(
        &'scope self,
        f: F,
        arg: &'scope mut A,
        predecessor: &Thread<'scope, F>,
    ) -> Thread<'scope, F> {
        let _ = f;
        let symmetric_to = if cfg!(genmc_witness) {
            None
        } else {
            Some(predecessor.tid)
        };
        self.start::<A, F>(arg, symmetric_to)
    }

    fn start<A: Send, F: Fn(&mut A) + Copy>(
        &'scope self,
        arg: &'scope mut A,
        symmetric_to: Option<sys::Tid>,
    ) -> Thread<'scope, F> {
        let index = self.spawned.get();
        if index == MAX_THREADS {
            fail("too many threads in one scope\0");
        }
        let tid = sys::spawn(start::<A, F>, (arg as *mut A).cast(), symmetric_to);
        let spawned = &self.threads[index];
        spawned.tid.set(Some(tid));
        self.spawned.set(index + 1);
        Thread {
            claimed: &spawned.claimed,
            tid,
            _f: PhantomData,
        }
    }
}

/// Joins, on drop, the threads of a scope that nobody has claimed.
struct JoinRemaining<'a, 'scope, 'env>(&'a Scope<'scope, 'env>);

impl Drop for JoinRemaining<'_, '_, '_> {
    fn drop(&mut self) {
        // A join claimed elsewhere completes before its claimant finishes,
        // and the claimant is either this thread (done already) or a thread
        // of the scope, which is joined here too.
        let scope = self.0;
        for spawned in &scope.threads[..scope.spawned.get()] {
            if let Some(tid) = spawned.tid.get()
                && claim(&spawned.claimed)
            {
                sys::join(tid);
            }
        }
    }
}

/// A thread spawned by a [`Scope`]. `F` is the thread function's type, so
/// that [`Scope::spawn_symmetric`] can only name a predecessor running the
/// same code. It may be handed to another thread of the scope, which then
/// joins it. Dropping (or leaking) it leaves the join to the scope.
pub struct Thread<'scope, F> {
    claimed: &'scope AtomicU32,
    tid: sys::Tid,
    _f: PhantomData<F>,
}

impl<F> Thread<'_, F> {
    /// Waits for the thread to finish.
    pub fn join(self) {
        if claim(self.claimed) {
            sys::join(self.tid);
        }
    }

    /// Detaches the thread from its scope, which will not join it.
    ///
    /// # Safety
    /// The thread must make no further access to its argument or to
    /// anything else borrowed from outside the scope (including [`Thread`]
    /// handles): e.g. it is parked forever in [`abandon_thread`].
    pub unsafe fn leak(self) {
        claim(self.claimed);
    }

    /// The thread's identity.
    pub fn id(&self) -> ThreadId {
        ThreadId(self.tid)
    }
}

/// The start routine of a thread running `F` on an `A`.
extern "C" fn start<A, F: Fn(&mut A) + Copy>(arg: *mut c_void) -> *mut c_void {
    const { assert!(size_of::<F>() == 0, "thread functions must not capture") };
    // SAFETY: F is zero-sized and Copy: any value is the value; arg is the
    // `&mut A` lent by Scope::spawn until the thread is joined (by the
    // scope at the latest).
    unsafe {
        let f: F = core::mem::zeroed();
        f(&mut *arg.cast::<A>());
    }
    core::ptr::null_mut()
}

/// Lends a thread-bound value (a participant registration) to one worker
/// thread.
///
/// The worker must use it exclusively until it has been joined, which the
/// `&mut` borrow taken by [`Scope::spawn`] enforces, and must not drop it; the
/// registering thread keeps ownership. It is sound for the cache's participant
/// registrations because they are bound to a thread only through the
/// liveness backend's notion of identity, which these tests replace with
/// deterministic backends.
pub struct Lent<'a, T>(&'a mut T);

impl<'a, T> Lent<'a, T> {
    /// Lends `value`.
    pub fn new(value: &'a mut T) -> Self {
        Lent(value)
    }
}

// SAFETY: see the type documentation; the registering thread is blocked in
// (or has not reached) the join while the worker uses the value. Only for
// participant registrations: other !Send types (e.g. `Rc`) may be bound to
// their thread in ways this argument does not cover.
unsafe impl<P: Params> Send for Lent<'_, ParticipantLock<'_, P>> {}

impl<T> Deref for Lent<'_, T> {
    type Target = T;
    fn deref(&self) -> &T {
        self.0
    }
}

impl<T> DerefMut for Lent<'_, T> {
    fn deref_mut(&mut self) -> &mut T {
        self.0
    }
}

/// `bytes` zero-padded into little-endian words, as lookups store values in
/// an output buffer: `padded_words::<2>(b"ABCDEFGHIJ")`.
pub const fn padded_words<const W: usize>(bytes: &[u8]) -> [u64; W] {
    assert!(bytes.len() <= 8 * W);
    let mut words = [0u64; W];
    let mut i = 0;
    while i < bytes.len() {
        words[i / 8] |= (bytes[i] as u64) << (8 * (i % 8));
        i += 1;
    }
    words
}

/// Element-wise word comparison. Use this instead of `==` on arrays or
/// slices of words in checked code: rustc lowers array equality to a single
/// wide integer load, which GenMC rejects as a mixed-size access.
#[inline]
pub fn words_eq(a: &[u64], b: &[u64]) -> bool {
    if a.len() != b.len() {
        return false;
    }
    let mut i = 0;
    while i < a.len() {
        if a[i] != b[i] {
            return false;
        }
        i += 1;
    }
    true
}

#[cfg(sgc_genmc)]
#[panic_handler]
fn panic(_: &core::panic::PanicInfo) -> ! {
    fail("panic\0")
}

#[cfg(sgc_genmc)]
mod genmc {
    //! GenMC's runtime interface (`genmc_internal.h`). The `__VERIFIER_spawn`
    //! family and `__VERIFIER_assume` are inline functions/macros in
    //! `genmc.h`, so the underlying symbols are called directly.
    use super::*;
    use core::ffi::c_char;

    pub type Tid = i64; // __VERIFIER_thread_t = long
    pub const ASSUME_USER: c_char = 0; // GENMC_ASSUME_USER

    type StartRoutine = extern "C" fn(*mut c_void) -> *mut c_void;

    unsafe extern "C" {
        pub fn __VERIFIER_assert_fail(msg: *const c_char, file: *const c_char, line: c_int) -> !;
        pub fn __VERIFIER_assume_internal(cond: bool, kind: c_char);
        pub fn __VERIFIER_thread_exit(ret: *mut c_void) -> !;
        fn __VERIFIER_thread_create(
            attr: *const c_void,
            f: StartRoutine,
            arg: *mut c_void,
        ) -> c_int;
        fn __VERIFIER_thread_create_symmetric(
            attr: *const c_void,
            f: StartRoutine,
            arg: *mut c_void,
            th: Tid,
        ) -> c_int;
        fn __VERIFIER_thread_join(th: Tid) -> *mut c_void;
        fn __VERIFIER_thread_self() -> Tid;
    }

    pub fn spawn(f: StartRoutine, arg: *mut c_void, symmetric_to: Option<Tid>) -> Tid {
        // SAFETY: GenMC builtins; arg outlives the thread (Thread borrow).
        unsafe {
            match symmetric_to {
                None => __VERIFIER_thread_create(core::ptr::null(), f, arg) as Tid,
                Some(th) => {
                    __VERIFIER_thread_create_symmetric(core::ptr::null(), f, arg, th) as Tid
                }
            }
        }
    }

    pub fn join(th: Tid) {
        // SAFETY: GenMC builtin.
        unsafe { __VERIFIER_thread_join(th) };
    }

    pub fn current() -> Tid {
        // SAFETY: GenMC builtin.
        unsafe { __VERIFIER_thread_self() }
    }
}

#[cfg(sgc_genmc)]
use genmc as sys;

#[cfg(not(sgc_genmc))]
mod native {
    //! pthreads, called directly so that the start routine and argument
    //! passing are the same as under GenMC.
    use super::*;

    pub type Tid = usize; // pthread_t: unsigned long (Linux), pointer (macOS)

    type StartRoutine = extern "C" fn(*mut c_void) -> *mut c_void;

    unsafe extern "C" {
        fn pthread_create(
            th: *mut Tid,
            attr: *const c_void,
            f: StartRoutine,
            arg: *mut c_void,
        ) -> c_int;
        fn pthread_join(th: Tid, ret: *mut *mut c_void) -> c_int;
        fn pthread_self() -> Tid;
    }

    pub fn spawn(f: StartRoutine, arg: *mut c_void, _symmetric_to: Option<Tid>) -> Tid {
        let mut th: Tid = 0;
        // SAFETY: arg outlives the thread (Thread borrow).
        let rc = unsafe { pthread_create(&mut th, core::ptr::null(), f, arg) };
        if rc != 0 {
            fail("pthread_create failed\0");
        }
        th
    }

    pub fn join(th: Tid) {
        // SAFETY: th is a joinable thread created by spawn.
        if unsafe { pthread_join(th, core::ptr::null_mut()) } != 0 {
            fail("pthread_join failed\0");
        }
    }

    pub fn current() -> Tid {
        // SAFETY: no preconditions.
        unsafe { pthread_self() }
    }
}

#[cfg(not(sgc_genmc))]
use native as sys;
