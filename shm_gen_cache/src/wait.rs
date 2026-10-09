//! Bounded waits for another participant to finish rotating or to unpin.
//!
//! A waiter knows the cache line whose next write may end its wait: the
//! cache header, which holds both `global_epoch` and the rotation owner word,
//! or a participant slot, which holds its pin. Where the processor can sleep
//! until a monitored line is written (WFET on aarch64, UMWAIT with WAITPKG on
//! Intel, MWAITX on AMD), the waiter does so; elsewhere it spins. A wait that
//! lasts longer usually means the participant waited for is not running, e.g.
//! because it was preempted; on Linux the waiter then blocks on a futex,
//! giving its CPU to the scheduler. Either way it stops at a wall-clock budget
//! rather than after a number of attempts, whose duration would vary by
//! orders of magnitude with the processor and with how busy the machine is.

use core::sync::atomic::AtomicU64;

/// The 32-bit half of a shared 64-bit word that the write ending a wait
/// changes: the word a monitored sleep arms on and a blocked waiter's futex.
#[derive(Clone, Copy)]
#[cfg(not(sgc_genmc_short_waits))]
pub(crate) struct WatchedWord<'a> {
    word: &'a AtomicU64,
    /// Bits 32-63 rather than bits 0-31.
    high: bool,
}

/// With `sgc_genmc_short_waits`, no wait reads the watched word.
#[derive(Clone, Copy)]
#[cfg(sgc_genmc_short_waits)]
pub(crate) struct WatchedWord<'a>(core::marker::PhantomData<&'a AtomicU64>);

impl<'a> WatchedWord<'a> {
    /// Bits 0-31 of `word`.
    #[inline(always)]
    pub(crate) const fn low(word: &'a AtomicU64) -> Self {
        Self::new(word, false)
    }

    /// Bits 32-63 of `word`.
    #[inline(always)]
    pub(crate) const fn high(word: &'a AtomicU64) -> Self {
        Self::new(word, true)
    }

    #[cfg(not(sgc_genmc_short_waits))]
    #[inline(always)]
    const fn new(word: &'a AtomicU64, high: bool) -> Self {
        WatchedWord { word, high }
    }

    #[cfg(sgc_genmc_short_waits)]
    #[inline(always)]
    const fn new(_word: &'a AtomicU64, _high: bool) -> Self {
        WatchedWord(core::marker::PhantomData)
    }

    /// The whole word's current value: the waits load the word whole, so
    /// that Rust code never accesses it with mixed sizes.
    #[cfg(not(sgc_genmc_short_waits))]
    #[inline(always)]
    fn read(self) -> u64 {
        self.word.load(core::sync::atomic::Ordering::Relaxed)
    }

    /// The watched half of `word`, a value of the whole word.
    #[cfg(all(
        not(sgc_genmc_short_waits),
        any(target_os = "linux", sgc_genmc_futex_model)
    ))]
    #[inline(always)]
    fn half(self, word: u64) -> u32 {
        (word >> (32 * self.high as u32)) as u32
    }
}

/// Waits for a condition that another participant ends by writing the cache
/// line of a known [`WatchedWord`]. Use one instance per wait:
///
/// ```text
/// let mut wait = BoundedWait::new();
/// while !done(watched_word.load()) {
///     if let Pause::BudgetExhausted = wait.pause_until(watched, &mut done) {
///         // Recover or time out.
///     }
/// }
/// ```
///
/// [`pause_until`](Self::pause_until) returns [`Pause::BudgetExhausted`]
/// once the budget is exhausted. It calls `done` after arming the monitor or
/// reading the futex word, so that a write between the caller's check and
/// the sleep cannot be missed. `done(word)` returns true once the awaited
/// write has happened. `word` is the whole watched word's value: before a
/// futex block, from the same load as the value the futex is armed with, so
/// that the waiter never blocks on a value that already ended its wait (a
/// newer value, seen by a second load, could have gone back to it); after
/// arming a monitor, from a load after arming. `done` may read other words
/// too, and must only read, since a wait calls it any number of times,
/// including right before each sleep.
///
/// A blocked waiter sleeps until the watched half changes and
/// [`wake_waiters`] is called on it, or until the budget ends. So every write
/// that may end a wait must be followed by `wake_waiters` on its watched word:
/// unconditionally, or, for an announced wait, whenever the write finds the
/// announcement. A writer that does not wake delays a blocked waiter until
/// its budget ends.
///
/// Successive calls escalate through stages that end at fixed times, not
/// after fixed numbers of calls. Each stage is a type; [`Strategy`] composes
/// them at compile time. On Linux, with a processor that can sleep on a
/// monitored line (WFET, WAITPKG or MWAITX):
///
///  1. [`Spin`]: 64 calls each execute one `cpu_relax()`: 0.6 us in all with
///     ISB on an M4 Max, 0.74 us with PAUSE on a Ryzen 9 9950X, ~3.6 us with
///     PAUSE on a 2.5 GHz Skylake-SP. This catches the waits that end almost
///     at once without reading the clock.
///  2. `MonitoredFor`: until 20 us after stage 1, each call sleeps on the
///     watched cache line until it is written, for at most 8 us: the write
///     wakes the waiter at once, with no system call. 20 us exceeds the
///     p99.9 of ownership waits on dedicated cores, ~5 to 20 us at 24 to 43
///     threads, and is about the cost of blocking, a futex wait, a wake and
///     two context switches. A monitor can be lost before the sleep, e.g. if
///     an interrupt or migration intervenes; the 8 us bound caps what that
///     costs.
///  3. `FutexBlock`: until the 5 ms budget (`Budget`), which also starts
///     after stage 1, a call blocks on a futex until the watched half
///     changes and `wake_waiters` is called, or until the budget ends: one
///     futex() syscall per wait, unless a spurious wake or a signal ends it
///     early, and the next call blocks again. By now the participant waited
///     for is probably not running; blocking lets the scheduler run it,
///     possibly on this CPU. It requires cooperation from the writer, which
///     must wake waiters after its write: unconditionally (affordable in cold
///     paths), or, for an announced wait
///     ([`pause_until_announced`](Self::pause_until_announced)), only if it
///     finds the announcement.
///  4. Once the budget is exhausted, the wait returns
///     [`Pause::BudgetExhausted`].
///
/// Without such a processor, each call in stage 2 spins on 64 `cpu_relax()`
/// instead. Outside Linux, stages 2 and 3 are one stage of monitored sleeps
/// or spins until the budget, with a `sched_yield()` every 16th call
/// (`MonitoredYielding`). With `sgc_genmc_short_waits`, waits stop after two
/// calls of stage 1. With `sgc_genmc_futex_model` instead, they have no spin
/// stage and block at once in a model of the futex without a timeout
/// ([`futex_model`]).
pub(crate) struct BoundedWait {
    stages: Strategy,
}

/// The outcome of [`BoundedWait::pause_until`].
#[must_use]
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub(crate) enum Pause {
    /// Paused once (or found `done`): check the condition and call again.
    Continue,
    /// The last stage is over; the wait did not pause. Never with
    /// `sgc_genmc_futex_model`, whose waits block until woken.
    #[cfg(not(sgc_genmc_futex_model))]
    BudgetExhausted,
}

/// The stages of a wait, in order. GenMC explores interleavings, not time:
/// two polls exercise the same outcomes as any longer wait, and it supports
/// neither clocks nor yields.
#[cfg(sgc_genmc_short_waits)]
type Strategy = Spin<2>;
#[cfg(sgc_genmc_futex_model)]
type Strategy = futex_model::FutexBlock;
#[cfg(all(
    not(any(sgc_genmc_short_waits, sgc_genmc_futex_model)),
    target_os = "linux"
))]
type Strategy = Then<Spin<64>, timed::Budget<Then<timed::MonitoredFor, timed::FutexBlock>>>;
#[cfg(all(
    not(any(sgc_genmc_short_waits, sgc_genmc_futex_model)),
    not(target_os = "linux")
))]
type Strategy = Then<Spin<64>, timed::Budget<timed::MonitoredYielding>>;

#[cfg(all(sgc_genmc_short_waits, sgc_genmc_futex_model))]
compile_error!("sgc_genmc_short_waits and sgc_genmc_futex_model are alternatives");

impl BoundedWait {
    /// A wait that has not paused yet.
    #[inline(always)]
    pub(crate) const fn new() -> Self {
        BoundedWait {
            stages: <Strategy as Stage>::NEW,
        }
    }

    /// Pauses once, as the current stage prescribes (see [`BoundedWait`]),
    /// unless `done` holds after arming the monitor or reading the futex
    /// word.
    #[inline]
    pub(crate) fn pause_until(
        &mut self,
        watched: WatchedWord<'_>,
        mut done: impl FnMut(u64) -> bool,
    ) -> Pause {
        self.pause(Target::new(watched, &mut done, None::<&mut fn() -> bool>))
    }

    /// [`pause_until`](Self::pause_until), calling `announce` before each futex
    /// block: this allows writers and waiters to coordinate: the waiter
    /// announces to the writer that it's waiting, and the writer can skip the
    /// awakening (futex syscall) if it sees no such announcement.  If it
    /// returns false (the watched word changed), the call continues without
    /// blocking.
    #[inline]
    pub(crate) fn pause_until_announced(
        &mut self,
        watched: WatchedWord<'_>,
        mut done: impl FnMut(u64) -> bool,
        mut announce: impl FnMut() -> bool,
    ) -> Pause {
        self.pause(Target::new(watched, &mut done, Some(&mut announce)))
    }

    #[inline(always)]
    fn pause(
        &mut self,
        mut target: Target<'_, '_, impl FnMut(u64) -> bool, impl FnMut() -> bool>,
    ) -> Pause {
        match self.stages.pause(&mut target) {
            Step::Paused => Pause::Continue,
            #[cfg(not(sgc_genmc_futex_model))]
            Step::Over => Pause::BudgetExhausted,
        }
    }
}

/// Wakes the participants blocked in [`BoundedWait::pause_until`] on
/// `watched`. Call after the write that may end their wait.
#[inline]
pub(crate) fn wake_waiters(watched: WatchedWord<'_>) {
    #[cfg(all(
        not(any(sgc_genmc_short_waits, sgc_genmc_futex_model)),
        target_os = "linux"
    ))]
    timed::futex::wake_all(watched);
    #[cfg(sgc_genmc_futex_model)]
    futex_model::wake(watched);
    #[cfg(not(any(
        sgc_genmc_futex_model,
        all(not(sgc_genmc_short_waits), target_os = "linux")
    )))]
    let _ = watched;
}

/// What a pause acts on: the watched word, the caller's condition and, for
/// an announced wait, its announcement.
#[cfg(not(sgc_genmc_short_waits))]
struct Target<'w, 'f, D, A> {
    watched: WatchedWord<'w>,
    done: &'f mut D,
    /// Only the futex stages (on Linux, or the model's) announce.
    #[cfg(any(target_os = "linux", sgc_genmc_futex_model))]
    announce: Option<&'f mut A>,
    #[cfg(not(any(target_os = "linux", sgc_genmc_futex_model)))]
    announce: core::marker::PhantomData<&'f mut A>,
}

/// With `sgc_genmc_short_waits`, the only stage ([`Spin`]) reads nothing.
#[cfg(sgc_genmc_short_waits)]
struct Target<'w, 'f, D, A>(core::marker::PhantomData<(WatchedWord<'w>, &'f mut D, &'f mut A)>);

impl<'w, 'f, D, A> Target<'w, 'f, D, A> {
    #[inline(always)]
    fn new(watched: WatchedWord<'w>, done: &'f mut D, announce: Option<&'f mut A>) -> Self {
        #[cfg(sgc_genmc_short_waits)]
        {
            let _ = (watched, done, announce);
            Target(core::marker::PhantomData)
        }
        #[cfg(not(sgc_genmc_short_waits))]
        Target {
            watched,
            done,
            #[cfg(any(target_os = "linux", sgc_genmc_futex_model))]
            announce,
            #[cfg(not(any(target_os = "linux", sgc_genmc_futex_model)))]
            announce: {
                let _ = announce;
                core::marker::PhantomData
            },
        }
    }
}

/// The result of one call of a stage.
enum Step {
    Paused,
    /// The stage has ended; it did not pause. The futex model's only stage
    /// never ends.
    #[cfg(not(sgc_genmc_futex_model))]
    Over,
}

/// A stage that needs no clock.
trait Stage {
    /// The stage before its first call.
    const NEW: Self;

    /// Pauses once, or returns [`Step::Over`] without pausing once the
    /// stage has ended (and on every later call).
    fn pause<D: FnMut(u64) -> bool, A: FnMut() -> bool>(
        &mut self,
        target: &mut Target<'_, '_, D, A>,
    ) -> Step;
}

/// `A` until it is over, then `B`.
#[cfg(not(any(sgc_genmc_short_waits, sgc_genmc_futex_model)))]
struct Then<A, B> {
    first: A,
    second: B,
    first_over: bool,
}

#[cfg(not(any(sgc_genmc_short_waits, sgc_genmc_futex_model)))]
impl<A: Stage, B: Stage> Stage for Then<A, B> {
    const NEW: Self = Then {
        first: A::NEW,
        second: B::NEW,
        first_over: false,
    };

    #[inline(always)]
    fn pause<D: FnMut(u64) -> bool, F: FnMut() -> bool>(
        &mut self,
        target: &mut Target<'_, '_, D, F>,
    ) -> Step {
        if !self.first_over {
            match self.first.pause(target) {
                Step::Paused => return Step::Paused,
                Step::Over => self.first_over = true,
            }
        }
        self.second.pause(target)
    }
}

/// `N` calls that each execute one `cpu_relax()`, without reading the clock
/// or the condition.
#[cfg(not(sgc_genmc_futex_model))]
struct Spin<const N: u32> {
    calls: u32,
}

#[cfg(not(sgc_genmc_futex_model))]
impl<const N: u32> Stage for Spin<N> {
    const NEW: Self = Spin { calls: 0 };

    #[inline(always)]
    fn pause<D: FnMut(u64) -> bool, A: FnMut() -> bool>(
        &mut self,
        _target: &mut Target<'_, '_, D, A>,
    ) -> Step {
        if self.calls == N {
            return Step::Over;
        }
        self.calls += 1;
        cpu_relax();
        Step::Paused
    }
}

/// The stages that read the clock.
#[cfg(not(any(sgc_genmc_short_waits, sgc_genmc_futex_model)))]
mod timed {
    use super::{Stage, Step, Target, Then, WatchedWord, cpu_relax};

    /// Nanoseconds, of a duration or of the monotonic clock.
    type Nanos = u64;
    const US: Nanos = 1_000;
    const MS: Nanos = 1_000_000;

    /// Far longer than a healthy wait. A waiter exhausts it when the
    /// participant it waits for has stopped (e.g. a stopped process) or
    /// stays descheduled for long; the caller then falls back to liveness
    /// checks and reaping. While blocked, a waiter costs the machine nothing.
    const BUDGET: Nanos = 5 * MS;
    /// The longest single monitored sleep (see stage 2).
    const MAX_SLEEP: Nanos = 8 * US;
    /// The length of stage 2.
    #[cfg(target_os = "linux")]
    const MONITORED_PERIOD: Nanos = 20 * US;
    /// Lets a descheduled owner or pinner sharing this CPU run. Not before
    /// the 16th call: most waits end sooner, and when another thread is
    /// runnable, a yield costs a context switch both ways.
    #[cfg(not(target_os = "linux"))]
    const CALLS_PER_YIELD: u32 = 16;

    /// A stage within the budget, given the time of the call and the
    /// budget's deadline (`now < deadline`).
    pub(super) trait TimedStage {
        /// The stage before its first call.
        const NEW: Self;

        /// Pauses once, or returns [`Step::Over`] without pausing once the
        /// stage has ended (and on every later call).
        fn pause_at<D: FnMut(u64) -> bool, A: FnMut() -> bool>(
            &mut self,
            now: Nanos,
            deadline: Nanos,
            target: &mut Target<'_, '_, D, A>,
        ) -> Step;
    }

    impl<A: TimedStage, B: TimedStage> TimedStage for Then<A, B> {
        const NEW: Self = Then {
            first: A::NEW,
            second: B::NEW,
            first_over: false,
        };

        #[inline(always)]
        fn pause_at<D: FnMut(u64) -> bool, F: FnMut() -> bool>(
            &mut self,
            now: Nanos,
            deadline: Nanos,
            target: &mut Target<'_, '_, D, F>,
        ) -> Step {
            if !self.first_over {
                match self.first.pause_at(now, deadline, target) {
                    Step::Paused => return Step::Paused,
                    Step::Over => self.first_over = true,
                }
            }
            self.second.pause_at(now, deadline, target)
        }
    }

    /// Reads the clock for `S`, starting the budget on the first call; over
    /// once the budget is exhausted, or once `S` is.
    pub(super) struct Budget<S> {
        stages: S,
        /// Zero before the first call.
        deadline: Nanos,
    }

    impl<S: TimedStage> Stage for Budget<S> {
        const NEW: Self = Budget {
            stages: S::NEW,
            deadline: 0,
        };

        #[inline]
        fn pause<D: FnMut(u64) -> bool, A: FnMut() -> bool>(
            &mut self,
            target: &mut Target<'_, '_, D, A>,
        ) -> Step {
            let now = clock::now();
            if self.deadline == 0 {
                self.deadline = now + BUDGET;
            } else if now >= self.deadline {
                return Step::Over;
            }
            self.stages.pause_at(now, self.deadline, target)
        }
    }

    /// Stage 2 on Linux: monitored sleeps of at most `MAX_SLEEP`, for
    /// `MONITORED_PERIOD` from its first call.
    #[cfg(target_os = "linux")]
    pub(super) struct MonitoredFor {
        /// Zero before the first call.
        until: Nanos,
    }

    #[cfg(target_os = "linux")]
    impl TimedStage for MonitoredFor {
        const NEW: Self = MonitoredFor { until: 0 };

        #[inline]
        fn pause_at<D: FnMut(u64) -> bool, A: FnMut() -> bool>(
            &mut self,
            now: Nanos,
            _deadline: Nanos,
            target: &mut Target<'_, '_, D, A>,
        ) -> Step {
            if self.until == 0 {
                self.until = now + MONITORED_PERIOD;
            } else if now >= self.until {
                return Step::Over;
            }
            monitor::sleep_until_line_written(
                monitor::current(),
                target.watched,
                target.done,
                MAX_SLEEP.min(self.until - now),
            );
            Step::Paused
        }
    }

    /// Stages 2 and 3 outside Linux: monitored sleeps of at most
    /// `MAX_SLEEP` until the budget ends, with a scheduler yield every
    /// `CALLS_PER_YIELD`th call.
    #[cfg(not(target_os = "linux"))]
    pub(super) struct MonitoredYielding {
        calls: u32,
    }

    #[cfg(not(target_os = "linux"))]
    impl TimedStage for MonitoredYielding {
        const NEW: Self = MonitoredYielding { calls: 0 };

        #[inline]
        fn pause_at<D: FnMut(u64) -> bool, A: FnMut() -> bool>(
            &mut self,
            now: Nanos,
            deadline: Nanos,
            target: &mut Target<'_, '_, D, A>,
        ) -> Step {
            self.calls += 1;
            if self.calls.is_multiple_of(CALLS_PER_YIELD) {
                crate::util::sched_yield();
            }
            monitor::sleep_until_line_written(
                monitor::current(),
                target.watched,
                target.done,
                MAX_SLEEP.min(deadline - now),
            );
            Step::Paused
        }
    }

    /// Stage 3 on Linux: a futex block until the budget ends, announced
    /// first if the wait is.
    #[cfg(target_os = "linux")]
    pub(super) struct FutexBlock;

    #[cfg(target_os = "linux")]
    impl TimedStage for FutexBlock {
        const NEW: Self = FutexBlock;

        #[inline]
        fn pause_at<D: FnMut(u64) -> bool, A: FnMut() -> bool>(
            &mut self,
            now: Nanos,
            deadline: Nanos,
            target: &mut Target<'_, '_, D, A>,
        ) -> Step {
            if let Some(announce) = &mut target.announce
                && !announce()
            {
                return Step::Paused;
            }
            futex::wait_for_change(target.watched, target.done, deadline - now);
            Step::Paused
        }
    }

    impl WatchedWord<'_> {
        /// The address of the watched half.
        #[inline(always)]
        fn as_ptr(self) -> *const u32 {
            // Little-endian (checked in lib.rs): bits 32-63 are at byte 4.
            let base = self.word.as_ptr().cast::<u32>().cast_const();
            // SAFETY: both halves lie within the word.
            unsafe { base.add(self.high as usize) }
        }
    }

    /// The C `struct timespec` of the supported 64-bit targets.
    #[repr(C)]
    #[cfg(any(target_os = "linux", target_vendor = "apple"))]
    struct Timespec {
        tv_sec: i64,
        tv_nsec: i64,
    }

    /// The steady clock.
    mod clock {
        use super::Nanos;

        /// Linux's steady clock; on Apple systems, the one `std::time::Instant`
        /// uses, which does not advance while the system sleeps.
        #[cfg(target_os = "linux")]
        const STEADY_CLOCK: i32 = 1; // CLOCK_MONOTONIC
        #[cfg(target_vendor = "apple")]
        const STEADY_CLOCK: i32 = 8; // CLOCK_UPTIME_RAW

        /// The steady clock's current time.
        #[cfg(any(target_os = "linux", target_vendor = "apple"))]
        #[inline]
        pub(super) fn now() -> Nanos {
            unsafe extern "C" {
                fn clock_gettime(clock: i32, time: *mut super::Timespec) -> i32;
            }
            let mut time = super::Timespec {
                tv_sec: 0,
                tv_nsec: 0,
            };
            // SAFETY: `time` is a valid timespec to write, and the clock
            // exists, so the call cannot fail.
            unsafe { clock_gettime(STEADY_CLOCK, &mut time) };
            time.tv_sec as Nanos * 1_000_000_000 + time.tv_nsec as Nanos
        }

        /// The steady clock's current time, from an arbitrary origin.
        #[cfg(all(
            feature = "std",
            not(any(target_os = "linux", target_vendor = "apple"))
        ))]
        pub(super) fn now() -> Nanos {
            static ORIGIN: std::sync::OnceLock<std::time::Instant> = std::sync::OnceLock::new();
            ORIGIN
                .get_or_init(std::time::Instant::now)
                .elapsed()
                .as_nanos() as Nanos
        }

        #[cfg(all(
            not(feature = "std"),
            not(any(target_os = "linux", target_vendor = "apple"))
        ))]
        compile_error!("rotation waits need feature `std` for a clock on this OS");
    }

    /// Futex waits and wakes. Shared, not private, futexes: participants
    /// are in different processes.
    #[cfg(target_os = "linux")]
    pub(super) mod futex {
        use super::{Nanos, Timespec, WatchedWord};

        #[cfg(target_arch = "x86_64")]
        const SYS_FUTEX: i64 = 202;
        #[cfg(target_arch = "aarch64")]
        const SYS_FUTEX: i64 = 98;
        #[cfg(not(any(target_arch = "x86_64", target_arch = "aarch64")))]
        compile_error!(
            "futex system call number unknown on this architecture; supported: \
             x86_64, aarch64"
        );
        const FUTEX_WAIT: i64 = 0;
        const FUTEX_WAKE: i64 = 1;

        /// Blocks on the futex at `watched` until it changes and
        /// [`wake_all`] is called on it, or for at most `timeout`, unless
        /// `done` already holds after the futex word is read.
        pub(in super::super) fn wait_for_change(
            watched: WatchedWord<'_>,
            done: &mut impl FnMut(u64) -> bool,
            timeout: Nanos,
        ) {
            // A write after this load makes FUTEX_WAIT return at once. The
            // condition sees the same value the futex is armed with.
            let word = watched.read();
            let seen = watched.half(word);
            if done(word) {
                return;
            }
            let relative = Timespec {
                tv_sec: (timeout / 1_000_000_000) as i64,
                tv_nsec: (timeout % 1_000_000_000) as i64,
            };
            // Any result (woken, value changed, timed out, interrupted) ends
            // this pause.
            futex(watched, FUTEX_WAIT, seen as i64, &relative);
        }

        /// Wakes every waiter blocked on the futex at `watched`.
        #[inline]
        pub(in super::super) fn wake_all(watched: WatchedWord<'_>) {
            futex(watched, FUTEX_WAKE, i32::MAX as i64, core::ptr::null());
        }

        /// The futex system call on the watched half, for the operations
        /// that take a value and a relative timeout (or none).
        fn futex(watched: WatchedWord<'_>, op: i64, value: i64, timeout: *const Timespec) {
            unsafe extern "C" {
                fn syscall(number: i64, ...) -> i64;
            }
            // SAFETY: the futex word is a valid, aligned 32-bit word, which
            // the kernel only reads, and `timeout` is null or points to a
            // live timespec (callers).
            unsafe {
                syscall(
                    SYS_FUTEX,
                    watched.as_ptr(),
                    op,
                    value,
                    timeout,
                    core::ptr::null::<u32>(),
                    0i64,
                );
            }
        }
    }

    /// Sleeps on a monitored cache line, with the mechanism this processor
    /// supports, detected on first use.
    pub(super) mod monitor {
        use core::sync::atomic::AtomicU8;
        use core::sync::atomic::Ordering::{Acquire, Release};

        use super::{Nanos, WatchedWord, cpu_relax};

        /// How a waiter sleeps until a cache line is written.
        #[derive(Clone, Copy, PartialEq, Eq, Debug)]
        #[repr(u8)]
        pub(crate) enum Mechanism {
            /// Not detected yet.
            Unknown = 0,
            /// No monitor: 64 `cpu_relax()` per sleep.
            Spin = 1,
            /// An exclusive load arms the monitor; WFET sleeps.
            #[cfg(target_arch = "aarch64")]
            Wfet = 2,
            /// UMONITOR and UMWAIT (WAITPKG, Intel).
            #[cfg(target_arch = "x86_64")]
            Waitpkg = 3,
            /// MONITORX and MWAITX (AMD).
            #[cfg(target_arch = "x86_64")]
            Mwaitx = 4,
        }

        /// The detected mechanism (`Mechanism as u8`). Detection is
        /// idempotent, so concurrent first uses may repeat it.
        static DETECTED: AtomicU8 = AtomicU8::new(Mechanism::Unknown as u8);

        /// The mechanism this processor supports, detected on first use.
        #[inline]
        pub(crate) fn current() -> Mechanism {
            let m = match DETECTED.load(Acquire) {
                1 => Mechanism::Spin,
                #[cfg(target_arch = "aarch64")]
                2 => Mechanism::Wfet,
                #[cfg(target_arch = "x86_64")]
                3 => Mechanism::Waitpkg,
                #[cfg(target_arch = "x86_64")]
                4 => Mechanism::Mwaitx,
                _ => Mechanism::Unknown,
            };
            if m != Mechanism::Unknown {
                return m;
            }
            let m = detect();
            DETECTED.store(m as u8, Release);
            m
        }

        /// Sleeps until the cache line of `watched` is written, for at most
        /// `duration`, or until a spurious wakeup, unless `done` already
        /// holds after arming the monitor. Without a monitor, spins on 64
        /// `cpu_relax()` instead, stopping once `done` holds.
        pub(crate) fn sleep_until_line_written(
            m: Mechanism,
            watched: WatchedWord<'_>,
            done: &mut impl FnMut(u64) -> bool,
            duration: Nanos,
        ) {
            match m {
                #[cfg(target_arch = "x86_64")]
                Mechanism::Waitpkg | Mechanism::Mwaitx => {
                    use core::sync::atomic::Ordering::Relaxed;
                    // At least one tick: a zero MWAITX timer may disable it.
                    let ticks = (duration * x86::TSC_PER_US.load(Relaxed) / 1000).max(1);
                    let p = watched.as_ptr();
                    if m == Mechanism::Waitpkg {
                        // SAFETY: detect() found WAITPKG.
                        unsafe { x86::arm_waitpkg(p) };
                        if !done(watched.read()) {
                            // SAFETY: as above.
                            unsafe { x86::sleep_waitpkg(ticks) };
                        }
                    } else {
                        // SAFETY: detect() found MONITORX/MWAITX.
                        unsafe { x86::arm_mwaitx(p) };
                        if !done(watched.read()) {
                            // SAFETY: as above.
                            unsafe { x86::sleep_mwaitx(ticks) };
                        }
                    }
                }
                #[cfg(target_arch = "aarch64")]
                Mechanism::Wfet => {
                    arm64::arm_wfet(watched.as_ptr());
                    if !done(watched.read()) {
                        // SAFETY: detect() found FEAT_WFxT.
                        unsafe { arm64::sleep_wfet(duration) };
                    } else {
                        arm64::clrex();
                    }
                }
                _ => {
                    for _ in 0..64 {
                        if done(watched.read()) {
                            break;
                        }
                        cpu_relax();
                    }
                }
            }
        }

        /// Detects the mechanism from CPUID; it calibrates the TSC rate
        /// first if it is a monitored sleep.
        #[cfg(target_arch = "x86_64")]
        fn detect() -> Mechanism {
            let (waitpkg, mwaitx) = x86::monitored_sleeps();
            if !waitpkg && !mwaitx {
                return Mechanism::Spin;
            }
            // Published before the mechanism, by the release store of the
            // caller.
            x86::TSC_PER_US.store(
                x86::calibrate_tsc_per_us(),
                core::sync::atomic::Ordering::Relaxed,
            );
            if waitpkg {
                Mechanism::Waitpkg
            } else {
                Mechanism::Mwaitx
            }
        }

        /// Detects FEAT_WFxT from the OS: user code cannot read the ID
        /// registers portably.
        #[cfg(all(target_arch = "aarch64", target_os = "linux"))]
        fn detect() -> Mechanism {
            const AT_HWCAP2: u64 = 26;
            // HWCAP2_WFXT, spelled out as libc may lack it.
            const HWCAP2_WFXT: u64 = 1 << 31;
            unsafe extern "C" {
                fn getauxval(kind: u64) -> u64;
            }
            // SAFETY: getauxval has no preconditions.
            if unsafe { getauxval(AT_HWCAP2) } & HWCAP2_WFXT != 0 {
                return Mechanism::Wfet;
            }
            Mechanism::Spin
        }

        /// Detects FEAT_WFxT from the OS: user code cannot read the ID
        /// registers portably.
        #[cfg(all(target_arch = "aarch64", target_vendor = "apple"))]
        fn detect() -> Mechanism {
            unsafe extern "C" {
                fn sysctlbyname(
                    name: *const core::ffi::c_char,
                    old: *mut core::ffi::c_void,
                    old_len: *mut usize,
                    new: *mut core::ffi::c_void,
                    new_len: usize,
                ) -> i32;
            }
            let mut supported: i32 = 0;
            let mut size = size_of::<i32>();
            // SAFETY: a NUL-terminated name, and `supported` is writable for
            // `size` bytes; nothing is set.
            let result = unsafe {
                sysctlbyname(
                    c"hw.optional.arm.FEAT_WFxT".as_ptr(),
                    (&raw mut supported).cast(),
                    &mut size,
                    core::ptr::null_mut(),
                    0,
                )
            };
            if result == 0 && supported != 0 {
                return Mechanism::Wfet;
            }
            Mechanism::Spin
        }

        #[cfg(not(any(
            target_arch = "x86_64",
            all(
                target_arch = "aarch64",
                any(target_os = "linux", target_vendor = "apple")
            )
        )))]
        fn detect() -> Mechanism {
            Mechanism::Spin
        }

        /// UMONITOR/UMWAIT and MONITORX/MWAITX. The instructions are in
        /// inline assembly, which needs no target feature, so they are only
        /// executed once detected and no `-C target-feature` is needed.
        #[cfg(target_arch = "x86_64")]
        mod x86 {
            use core::arch::x86_64::{__cpuid, __cpuid_count, __get_cpuid_max, _rdtsc};
            use core::sync::atomic::AtomicU64;

            use super::super::{Nanos, US, clock};
            use super::cpu_relax;

            /// TSC ticks per microsecond, calibrated with the mechanism;
            /// MWAITX's timer counts at the same rate.
            pub(super) static TSC_PER_US: AtomicU64 = AtomicU64::new(0);

            /// Whether CPUID reports WAITPKG and MONITORX/MWAITX.
            pub(super) fn monitored_sleeps() -> (bool, bool) {
                const LEAF7_ECX_WAITPKG: u32 = 1 << 5;
                const EXT1_ECX_MWAITX: u32 = 1 << 29;
                // SAFETY (all four): every x86_64 processor has CPUID, and
                // a leaf is only read once the maximum leaf covers it.
                let (max_leaf, _) = unsafe { __get_cpuid_max(0) };
                let (max_ext_leaf, _) = unsafe { __get_cpuid_max(0x8000_0000) };
                let waitpkg =
                    max_leaf >= 7 && unsafe { __cpuid_count(7, 0) }.ecx & LEAF7_ECX_WAITPKG != 0;
                let mwaitx = max_ext_leaf >= 0x8000_0001
                    && unsafe { __cpuid(0x8000_0001) }.ecx & EXT1_ECX_MWAITX != 0;
                (waitpkg, mwaitx)
            }

            /// Counts TSC ticks over 50 us of the steady clock, once per
            /// process: within ~1% of the rate, which only sets how long a
            /// sleep may last.
            pub(super) fn calibrate_tsc_per_us() -> u64 {
                let start = clock::now();
                // SAFETY: RDTSC is available on every x86_64 processor.
                let first = unsafe { _rdtsc() };
                let mut now;
                loop {
                    now = clock::now();
                    if now - start >= 50 * US {
                        break;
                    }
                    cpu_relax();
                }
                let elapsed: Nanos = now - start;
                // SAFETY: as above.
                let ticks = unsafe { _rdtsc() }.wrapping_sub(first);
                (ticks.wrapping_mul(1000) / elapsed).max(1)
            }

            /// Arms the monitor on the cache line of `p`.
            ///
            /// # Safety
            /// The processor must support WAITPKG.
            #[inline]
            pub(super) unsafe fn arm_waitpkg(p: *const u32) {
                // SAFETY: WAITPKG is supported (caller); UMONITOR only
                // monitors the address, which it does not access.
                unsafe {
                    core::arch::asm!("umonitor {}", in(reg) p, options(nostack, preserves_flags));
                }
            }

            /// Sleeps in C0.1, the lighter state, which wakes faster than
            /// C0.2, for `ticks`. UMWAIT takes an absolute TSC deadline.
            /// Linux caps a single UMWAIT (by default at 100,000 ticks), far
            /// above the longest sleep.
            ///
            /// # Safety
            /// The processor must support WAITPKG.
            #[inline]
            pub(super) unsafe fn sleep_waitpkg(ticks: u64) {
                // SAFETY: RDTSC is available on every x86_64 processor.
                let deadline = unsafe { _rdtsc() }.wrapping_add(ticks);
                // SAFETY: WAITPKG is supported (caller); UMWAIT only sleeps
                // and sets CF.
                unsafe {
                    core::arch::asm!(
                        "umwait {control:e}",
                        control = in(reg) 1u32,
                        in("eax") deadline as u32,
                        in("edx") (deadline >> 32) as u32,
                        options(nostack),
                    );
                }
            }

            /// Arms the monitor on the cache line of `p`, with no extensions
            /// or hints.
            ///
            /// # Safety
            /// The processor must support MONITORX/MWAITX.
            #[inline]
            pub(super) unsafe fn arm_mwaitx(p: *const u32) {
                // SAFETY: MONITORX is supported (caller); it only monitors
                // the address in RAX, which it does not access.
                unsafe {
                    core::arch::asm!(
                        "monitorx",
                        in("rax") p,
                        in("ecx") 0u32,
                        in("edx") 0u32,
                        options(nostack, preserves_flags),
                    );
                }
            }

            /// Sleeps for at most `ticks`: extension bit 1 enables the timer
            /// in EBX, a relative tick count.
            ///
            /// # Safety
            /// The processor must support MONITORX/MWAITX.
            #[inline]
            pub(super) unsafe fn sleep_mwaitx(ticks: u64) {
                let timer = ticks.min(u32::MAX as u64);
                // SAFETY: MWAITX is supported (caller). LLVM reserves RBX,
                // so the timer is swapped into it and RBX restored after.
                unsafe {
                    core::arch::asm!(
                        "xchg {timer}, rbx",
                        "mwaitx",
                        "xchg {timer}, rbx",
                        timer = inout(reg) timer => _,
                        in("eax") 0u32,
                        in("ecx") 2u32,
                        options(nostack, preserves_flags),
                    );
                }
            }
        }

        /// The exclusive monitor and WFET.
        #[cfg(target_arch = "aarch64")]
        mod arm64 {
            use super::super::Nanos;

            /// Consumes any pending event, then arms the exclusive monitor: a
            /// write by another core to the granule of `p` (at most a cache
            /// line) clears it, which wakes WFE/WFET. A 32-bit exclusive load,
            /// since watched words need only be 4-byte aligned.
            ///
            /// Clearing an armed monitor generates an event, and every sleep
            /// ends with [`clrex`]. Left pending, that event would make the
            /// next WFET return at once, so no WFET would ever sleep. The
            /// event register cannot be cleared directly: SEVL sets it, so the
            /// WFE after it consumes it without sleeping. The drain runs here,
            /// just before arming: run right after the disarm instead, it does
            /// not always consume that event (seen on Apple M4). A write
            /// before the arm is not missed: the caller checks its condition
            /// after arming.
            #[inline]
            pub(super) fn arm_wfet(p: *const u32) {
                // SAFETY: `p` is a valid, aligned word of shared memory
                // (WatchedWord); the load's value is discarded. SEVL and WFE
                // only set and consume this core's event register, and the
                // WFE does not sleep, as SEVL set it.
                unsafe {
                    core::arch::asm!(
                        "sevl",
                        "wfe",
                        "ldxr {ignored:w}, [{p}]",
                        p = in(reg) p,
                        ignored = out(reg) _,
                        options(nostack, preserves_flags),
                    );
                }
            }

            /// Clears the exclusive monitor. The event this generates is
            /// consumed by the next [`arm_wfet`].
            #[inline]
            pub(super) fn clrex() {
                // SAFETY: CLREX only clears this core's exclusive monitor.
                unsafe { core::arch::asm!("clrex", options(nostack, preserves_flags)) };
            }

            /// Sleeps until the armed monitor is cleared, an event, or
            /// `duration` passes, then clears the monitor.
            ///
            /// # Safety
            /// The processor must support FEAT_WFxT.
            #[inline]
            pub(super) unsafe fn sleep_wfet(duration: Nanos) {
                let now: u64;
                let frequency: u64;
                // SAFETY: EL0 may read the virtual counter and its
                // frequency, which have no side effects.
                unsafe {
                    core::arch::asm!("mrs {}, cntvct_el0", out(reg) now, options(nomem, nostack, preserves_flags));
                    core::arch::asm!("mrs {}, cntfrq_el0", out(reg) frequency, options(nomem, nostack, preserves_flags));
                }
                // WFET takes an absolute CNTVCT_EL0 deadline.
                let deadline = now + duration * frequency / 1_000_000_000 + 1;
                // SAFETY: FEAT_WFxT is supported (caller). WFET x16, encoded
                // directly so that no assembler extension is needed; it only
                // sleeps.
                unsafe {
                    core::arch::asm!(".inst 0xd5031010", in("x16") deadline, options(nostack, preserves_flags));
                }
                clrex();
            }
        }
    }

    #[cfg(all(test, feature = "std"))]
    mod tests {
        //! A monitored write ends the wait, the budget bounds it.

        use core::sync::atomic::AtomicU64;
        use core::sync::atomic::Ordering::Release;
        use std::thread;
        use std::time::{Duration, Instant};

        use super::monitor::{self, Mechanism};
        use crate::wait::{BoundedWait, Pause, WatchedWord, wake_waiters};

        /// A word on a cache line of its own, as for the cache header and
        /// participant slots.
        #[cfg_attr(target_arch = "aarch64", repr(align(128)))]
        #[cfg_attr(not(target_arch = "aarch64"), repr(align(64)))]
        struct Line(AtomicU64);

        const _: () = assert!(align_of::<Line>() == crate::util::CACHE_LINE);

        /// Serializes the tests that measure wall-clock time, so that their
        /// waiter and writer threads do not compete with each other's for
        /// the processors.
        fn timing_test() -> std::sync::MutexGuard<'static, ()> {
            static TIMING: std::sync::Mutex<()> = std::sync::Mutex::new(());
            TIMING
                .lock()
                .unwrap_or_else(|poisoned| poisoned.into_inner())
        }

        #[test]
        fn reports_mechanism() {
            let m = monitor::current();
            assert_ne!(m, Mechanism::Unknown);
            std::eprintln!("wait mechanism: {m:?}");
        }

        /// Writes the word after `delay`, from another thread, while this one
        /// waits, then wakes blocked waiters if `wake`. Returns how long the
        /// wait took and how long it lasted after the write, as an error if
        /// it timed out.
        fn wait_for_write(delay: Duration, wake: bool) -> Result<(Duration, Duration), Duration> {
            let line = Line(AtomicU64::new(0));
            let word = &line.0;
            thread::scope(|s| {
                let writer = s.spawn(|| {
                    thread::sleep(delay);
                    let written = Instant::now();
                    word.store(1, Release);
                    if wake {
                        wake_waiters(WatchedWord::low(word));
                    }
                    written
                });
                let start = Instant::now();
                let mut wait = BoundedWait::new();
                let written = |w: u64| w != 0;
                let mut timed_out = false;
                while word.load(core::sync::atomic::Ordering::Acquire) == 0 {
                    if wait.pause_until(WatchedWord::low(word), written) == Pause::BudgetExhausted {
                        timed_out = true;
                        break;
                    }
                }
                let end = Instant::now();
                let waited = end - start;
                let after_write = end.saturating_duration_since(writer.join().unwrap());
                if timed_out {
                    Err(waited)
                } else {
                    Ok((waited, after_write))
                }
            })
        }

        #[test]
        fn write_ends_wait() {
            let _serial = timing_test();
            // A woken write ends the wait soon after it, even once the waiter
            // blocks (on Linux, from 20 us). Measured from the write: the
            // sleep before it can itself last 1 ms (Windows timers). Retry to
            // tolerate an unlucky scheduling delay.
            for _ in 0..5 {
                let waited = wait_for_write(Duration::from_micros(200), true);
                let (waited, after_write) = waited.expect("the wait timed out");
                assert!(waited > Duration::ZERO);
                if after_write < Duration::from_millis(1) {
                    return;
                }
            }
            panic!("a woken write did not end the wait promptly");
        }

        #[test]
        fn unwoken_write_ends_wait() {
            let _serial = timing_test();
            // Without a wake, a waiter still sees the write and does not
            // exhaust its budget. On Linux the write, 2 ms in, lands in the
            // futex stage, whose single block lasts until the budget's
            // deadline; the check after the block then sees it. So the whole
            // wait lasts the budget, however long the writer's sleep took
            // (as long as it ends before the deadline): a futex block
            // returns no earlier than its timeout unless woken, and a
            // spurious return blocks again until the same deadline.
            // Elsewhere the monitored sleeps see the write at once (observed:
            // ~300 ns on Windows after the write).
            #[cfg(target_os = "linux")]
            const BOUNDS: (Duration, Duration) = (
                Duration::from_nanos(super::BUDGET),
                Duration::from_nanos(super::BUDGET + super::MS),
            );
            #[cfg(not(target_os = "linux"))]
            const BOUNDS: (Duration, Duration) = (Duration::ZERO, Duration::from_micros(100));
            // On Linux the whole wait; elsewhere the wait after the write,
            // whose sleep can overrun.
            fn measured((waited, after_write): (Duration, Duration)) -> Duration {
                if cfg!(target_os = "linux") {
                    waited
                } else {
                    after_write
                }
            }
            // Judged by the median of many trials, so that a waiter or writer
            // the scheduler delays once does not fail the test. A trial that
            // times out (e.g. the writer overslept the 5 ms budget) counts
            // as slower than any other.
            const TRIALS: usize = 21;
            let mut waits: [Option<Duration>; TRIALS] = core::array::from_fn(|_| {
                wait_for_write(Duration::from_millis(2), false)
                    .ok()
                    .map(measured)
            });
            waits.sort_by_key(|w| w.unwrap_or(Duration::MAX));
            let median = waits[TRIALS / 2];
            assert!(
                median.is_some_and(|m| BOUNDS.0 <= m && m < BOUNDS.1),
                "median wait {median:?} (bounds {BOUNDS:?}); all: {waits:?}"
            );
        }

        #[test]
        fn unwritten_wait_exhausts_budget() {
            let _serial = timing_test();
            let line = Line(AtomicU64::new(0));
            let mut wait = BoundedWait::new();
            let start = Instant::now();
            while wait.pause_until(WatchedWord::low(&line.0), |w| w != 0) == Pause::Continue {}
            let waited = start.elapsed();
            assert!(waited >= Duration::from_millis(5), "waited {waited:?}");
            assert!(waited < Duration::from_millis(100), "waited {waited:?}");
        }

        #[test]
        fn satisfied_condition_skips_sleep() {
            let _serial = timing_test();
            // With the condition holding at the post-arm check, no sleep
            // happens, so even many pauses stay far below one budget.
            let line = Line(AtomicU64::new(0));
            let mut wait = BoundedWait::new();
            let start = Instant::now();
            for _ in 0..1000 {
                assert_eq!(
                    wait.pause_until(WatchedWord::low(&line.0), |_| true),
                    Pause::Continue
                );
            }
            assert!(start.elapsed() < Duration::from_millis(1));
        }

        #[test]
        #[cfg(target_arch = "aarch64")]
        fn wfet_sleeps_after_each_disarm() {
            // Disarming an armed monitor generates an event; unless it is
            // consumed, the next WFET returns at once instead of sleeping.
            // Disarm on both paths (condition already holding, and after a
            // sleep), then check that an unwritten 8 us sleep lasts well over
            // the ~60 ns of a WFET that returns at once.
            //
            // Events from outside the test (SEV on any core sets every
            // core's event register) also end sleeps early. A plain WFE after
            // draining the event register, which the disarm cannot affect,
            // is the control: a batch counts only if the control slept too.
            //
            // A drain placed right after the disarm instead of before the arm
            // fails only in some processes. On Apple M4 this seems to be a
            // particularity of its efficiency cores.
            if monitor::current() != Mechanism::Wfet {
                return;
            }
            let _serial = timing_test();
            let line = Line(AtomicU64::new(0));
            let watched = WatchedWord::low(&line.0);
            const BATCHES: u32 = 10;
            const SLEEPS: u32 = 50;
            const SLEPT: Duration = Duration::from_nanos(500);
            let mut fast = std::vec::Vec::new();
            for _ in 0..BATCHES {
                let start = Instant::now();
                for _ in 0..SLEEPS {
                    // SAFETY: SEVL and WFE only set and consume this core's
                    // event register; the second WFE sleeps until an event.
                    unsafe {
                        core::arch::asm!("sevl", "wfe", "wfe", options(nostack, preserves_flags))
                    };
                }
                let control = start.elapsed() / SLEEPS;
                let start = Instant::now();
                for _ in 0..SLEEPS {
                    monitor::sleep_until_line_written(
                        Mechanism::Wfet,
                        watched,
                        &mut |_| true,
                        8_000,
                    );
                    monitor::sleep_until_line_written(
                        Mechanism::Wfet,
                        watched,
                        &mut |_| false,
                        8_000,
                    );
                }
                let per_sleep = start.elapsed() / SLEEPS;
                std::eprintln!("{per_sleep:?} per WFET sleep, {control:?} per plain WFE");
                if control <= SLEPT {
                    continue;
                }
                if per_sleep > SLEPT {
                    return;
                }
                fast.push(per_sleep);
            }
            assert!(
                fast.is_empty(),
                "WFET returned at once while a plain WFE slept: {fast:?} per sleep"
            );
            std::eprintln!("not measured: outside events ended every plain WFE early");
        }

        #[test]
        fn fallback_spin_sees_write() {
            // The spin fallback stops as soon as the condition holds.
            let line = Line(AtomicU64::new(1));
            let mut calls = 0u32;
            let mut written = |w: u64| {
                calls += 1;
                w != 0
            };
            monitor::sleep_until_line_written(
                Mechanism::Spin,
                WatchedWord::low(&line.0),
                &mut written,
                8_000,
            );
            assert_eq!(calls, 1);
        }
    }
}

/// Spin-wait hint: `pause` on x86_64, `isb` on aarch64, each also a compiler
/// memory barrier; a compiler fence elsewhere.
///
/// It pauses a spinning thread briefly, so that a spin-wait loop neither
/// floods the awaited cache line with reads nor burns the core's issue
/// slots. On aarch64 it is not `yield`: that is only a hint for cores with
/// simultaneous multithreading, which almost no aarch64 core has, so it
/// executes as a no-op (~0.3 ns on an M4 Max). `isb` flushes the pipeline,
/// a delay of tens of cycles (~9 ns there), closer to `pause` (it is also
/// what `core::hint::spin_loop()` emits). `wfe` would sleep instead, but it
/// needs an armed monitor, which only the monitored waits of
/// [`BoundedWait`] have. Inline assembly is used, rather than
/// `spin_loop()`, so that the pause is a compiler memory barrier as well.
///
/// Under GenMC it compiles to nothing: inline assembly is not supported
/// there.
#[inline(always)]
fn cpu_relax() {
    #[cfg(all(not(sgc_genmc), target_arch = "x86_64"))]
    // SAFETY: `pause` has no operands or side effects; without `nomem` the
    // block is a compiler memory barrier.
    unsafe {
        core::arch::asm!("pause", options(nostack, preserves_flags));
    }
    #[cfg(all(not(sgc_genmc), target_arch = "aarch64"))]
    // SAFETY: as above, for `isb`.
    unsafe {
        core::arch::asm!("isb", options(nostack, preserves_flags));
    }
    #[cfg(all(
        not(sgc_genmc),
        not(any(target_arch = "x86_64", target_arch = "aarch64"))
    ))]
    core::sync::atomic::compiler_fence(core::sync::atomic::Ordering::SeqCst);
}

/// See [`futex_model::stale_blocks`].
#[cfg(all(sgc_genmc_futex_model, feature = "verify"))]
pub(crate) use futex_model::stale_blocks as futex_model_stale_blocks;
/// See [`futex_model::woken_waits`].
#[cfg(all(sgc_genmc_futex_model, feature = "verify"))]
pub(crate) use futex_model::woken_waits as futex_model_woken_waits;

/// A model of the Linux futex for GenMC, which supports no system calls,
/// used with `sgc_genmc_futex_model` instead of `sgc_genmc_short_waits`.
///
/// It has the semantics the waits rely on, with no timeout: a wait whose
/// futex word no longer holds the value the waiter saw returns at once;
/// otherwise the waiter is queued and blocks until a wake on that word. A
/// "kernel" lock, like the futex hash-bucket lock, makes the value check
/// and the queueing atomic with respect to wakes. Without a timeout, a wake
/// that a waiter needs and never gets leaves it blocked forever, which
/// GenMC reports, where natively it would only delay the waiter until its
/// budget ends.
///
/// Every wait blocks here from its first call (see [`FutexBlock`]), so a program
/// using the model must not let a participant die while another may wait
/// for it: nothing would wake the waiter. At most
/// [`WAITERS`](futex_model::WAITERS) threads may be blocked at once.
#[cfg(sgc_genmc_futex_model)]
mod futex_model {
    use core::sync::atomic::Ordering::{Acquire, Relaxed, Release};
    use core::sync::atomic::{AtomicBool, AtomicU32, AtomicU64, AtomicUsize};

    use super::{Stage, Step, Target, WatchedWord, cpu_relax};

    /// Waiter records: the most threads blocked at once.
    pub(super) const WAITERS: usize = 2;

    /// The "kernel" lock, held while checking a futex word and queueing,
    /// and while waking.
    static LOCK: AtomicU32 = AtomicU32::new(0);
    /// The key ([`key`]) of the futex word each waiter is queued on; zero
    /// for a free record.
    static QUEUED_ON: [AtomicUsize; WAITERS] = [const { AtomicUsize::new(0) }; WAITERS];
    /// Set by a wake of the word its waiter is queued on.
    static WOKEN: [AtomicBool; WAITERS] = [const { AtomicBool::new(false) }; WAITERS];
    /// Waits that blocked and were woken, for tests' witnesses
    /// ([`woken_waits`]).
    static WOKEN_WAITS: AtomicU32 = AtomicU32::new(0);
    /// Waits that blocked although their condition already held, because
    /// the futex word went back to the value the waiter read (ABA). For
    /// tests' witnesses ([`stale_blocks`]). With the rotation owner word:
    ///
    /// ```text
    /// waiter W                          owner A (registration id X)
    /// --------                          ---------------------------
    /// word = owner word (id X)
    /// done(word)? no (owner X, epoch e)
    ///                                   publish epoch e + 1
    ///                                   release: owner = 0; wake: nobody queued
    ///                                   acquire again: owner = X
    /// FUTEX_WAIT(X): half == X, blocks
    ///   although the epoch moved
    ///                                   publish epoch e + 2
    ///                                   release: owner = 0; wake: ends W's block
    /// ```
    static STALE_BLOCKS: AtomicU32 = AtomicU32::new(0);

    /// The only stage of a wait in the model: announced first if the wait
    /// is, it blocks until woken, as long as the watched half still holds
    /// the value it read. It is never over. Spin polls before it would only
    /// multiply the explored executions with equivalent reads.
    pub(super) struct FutexBlock;

    impl Stage for FutexBlock {
        const NEW: Self = FutexBlock;

        fn pause<D: FnMut(u64) -> bool, A: FnMut() -> bool>(
            &mut self,
            target: &mut Target<'_, '_, D, A>,
        ) -> Step {
            if let Some(announce) = &mut target.announce
                && !announce()
            {
                return Step::Paused;
            }
            // As natively: a write after this load makes the wait return at
            // once.
            let word = target.watched.read();
            let seen = target.watched.half(word);
            if (target.done)(word) {
                return Step::Paused;
            }
            wait(target.watched, seen, target.done);
            Step::Paused
        }
    }

    /// Wakes every waiter queued on `watched`.
    pub(super) fn wake(watched: WatchedWord<'_>) {
        let key = key(watched);
        lock();
        for i in 0..WAITERS {
            if QUEUED_ON[i].load(Relaxed) == key {
                WOKEN[i].store(true, Release);
            }
        }
        unlock();
    }

    /// Returns at once if `watched` no longer holds `seen`; otherwise
    /// blocks until a wake on it.
    /// `done` is the caller's condition, re-read only to count stale blocks.
    fn wait(watched: WatchedWord<'_>, seen: u32, done: &mut impl FnMut(u64) -> bool) {
        let key = key(watched);
        lock();
        let word = watched.read();
        if watched.half(word) != seen {
            unlock();
            return;
        }
        if done(word) {
            STALE_BLOCKS.store(STALE_BLOCKS.load(Relaxed) + 1, Relaxed);
        }
        let Some(record) = (0..WAITERS).find(|&i| QUEUED_ON[i].load(Relaxed) == 0) else {
            crate::assert::fatal("more blocked waiters than the futex model has records");
        };
        QUEUED_ON[record].store(key, Relaxed);
        WOKEN[record].store(false, Relaxed);
        unlock();
        while !WOKEN[record].load(Acquire) {
            cpu_relax();
        }
        // Freed by its waiter only, so that a later waiter cannot reset the
        // flag before this one has seen it.
        lock();
        QUEUED_ON[record].store(0, Relaxed);
        WOKEN_WAITS.store(WOKEN_WAITS.load(Relaxed) + 1, Relaxed);
        unlock();
    }

    /// How many waits blocked and were then woken. Read it once every
    /// waiter has returned (e.g. after joining the threads).
    #[cfg(feature = "verify")]
    pub(crate) fn woken_waits() -> u32 {
        lock();
        let waits = WOKEN_WAITS.load(Relaxed);
        unlock();
        waits
    }

    /// How many waits blocked although their condition already held
    /// ([`STALE_BLOCKS`]). Read it once every waiter has returned.
    #[cfg(feature = "verify")]
    pub(crate) fn stale_blocks() -> u32 {
        lock();
        let blocks = STALE_BLOCKS.load(Relaxed);
        unlock();
        blocks
    }

    /// The address of the watched half, as the futex key: never zero.
    fn key(watched: WatchedWord<'_>) -> usize {
        core::ptr::from_ref::<AtomicU64>(watched.word) as usize + 4 * watched.high as usize
    }

    fn lock() {
        loop {
            // Test, then test-and-set: GenMC turns the read-only loop into
            // an assumption.
            while LOCK.load(Relaxed) != 0 {
                cpu_relax();
            }
            if LOCK.compare_exchange(0, 1, Acquire, Relaxed).is_ok() {
                return;
            }
        }
    }

    fn unlock() {
        LOCK.store(0, Release);
    }
}
