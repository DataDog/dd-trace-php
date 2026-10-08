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

use crate::util::cpu_relax;

/// The 32-bit half of a shared 64-bit word that the write ending a wait
/// changes: the word a monitored sleep arms on and a blocked waiter's futex.
#[derive(Clone, Copy)]
#[cfg_attr(sgc_genmc_short_waits, allow(dead_code))]
pub(crate) struct WatchedWord<'a> {
    word: &'a AtomicU64,
    /// Bits 32-63 rather than bits 0-31.
    high: bool,
}

impl<'a> WatchedWord<'a> {
    /// Bits 0-31 of `word`.
    #[inline(always)]
    pub(crate) const fn low(word: &'a AtomicU64) -> Self {
        WatchedWord { word, high: false }
    }

    /// Bits 32-63 of `word`.
    #[inline(always)]
    pub(crate) const fn high(word: &'a AtomicU64) -> Self {
        WatchedWord { word, high: true }
    }
}

/// Waits for a condition that another participant ends by writing the cache
/// line of a known [`WatchedWord`]. Use one instance per wait:
///
/// ```text
/// let mut wait = BoundedWait::new();
/// while !done() {
///     if !wait.pause_until(watched, &mut done) {
///         // Budget exhausted: recover or time out.
///     }
/// }
/// ```
///
/// [`pause_until`](Self::pause_until) returns false once the budget is
/// exhausted. It calls `done` after arming the monitor or reading the futex
/// word, so that a write between the caller's check and the sleep cannot be
/// missed. `done` returns true once the awaited write has happened; it must
/// only read, since a wait calls it any number of times, including right
/// before each sleep.
///
/// A blocked waiter sleeps until the watched half changes and
/// [`wake_waiters`] is called on it, or until its timeout. A writer that does
/// not call `wake_waiters` only delays the waiter by that timeout.
///
/// Successive calls escalate through stages that end at fixed times, not
/// after fixed numbers of calls. On Linux, with a processor that can sleep on
/// a monitored line (WFET, WAITPKG or MWAITX):
///
///  1. 64 calls each execute one `cpu_relax()`: 0.6 us in all with ISB on an
///     M4 Max, 0.74 us with PAUSE on a Ryzen 9 9950X, ~3.6 us with PAUSE on
///     a 2.5 GHz Skylake-SP. This catches the waits that end almost at once
///     without reading the clock.
///  2. Until 20 us after stage 1, each call sleeps on the watched cache line
///     until it is written, for at most 8 us: the write wakes the waiter at
///     once, with no system call. 20 us exceeds the p99.9 of ownership waits
///     on dedicated cores, ~5 to 20 us at 24 to 43 threads, and is about the
///     cost of blocking, a futex wait, a wake and two context switches. A
///     monitor can be lost before the sleep, e.g. if an interrupt or
///     migration intervenes; the 8 us bound caps what that costs.
///  3. Until the 5 ms budget, which also starts after stage 1, each call
///     blocks on a futex until the watched half changes and `wake_waiters`
///     is called, for at most 50 us at first, doubling up to 1 ms for writers
///     that do not wake waiters. By now the participant waited for is
///     probably not running; blocking lets the scheduler run it, possibly on
///     this CPU. Without a wake, this is about 9 calls.
///  4. Once the budget is exhausted, `pause_until` returns false.
///
/// Without such a processor, each call in stage 2 spins on 64 `cpu_relax()`
/// instead. Outside Linux, stages 2 and 3 are one stage of monitored sleeps
/// or spins until the budget, with a `sched_yield()` every 16th call. With
/// `sgc_genmc_short_waits`, waits stop after two calls of stage 1.
pub(crate) struct BoundedWait {
    spins: u32,
    #[cfg(not(sgc_genmc_short_waits))]
    timed: timed::Stages,
}

/// Calls of stage 1. GenMC explores interleavings, not time: two polls
/// exercise the same outcomes as any longer wait, and it supports neither
/// clocks nor yields.
const SPIN_LIMIT: u32 = if cfg!(sgc_genmc_short_waits) { 2 } else { 64 };

impl BoundedWait {
    /// A wait that has not paused yet.
    #[inline(always)]
    pub(crate) const fn new() -> Self {
        BoundedWait {
            spins: 0,
            #[cfg(not(sgc_genmc_short_waits))]
            timed: timed::Stages::new(),
        }
    }

    /// Pauses once, as the current stage prescribes (see [`BoundedWait`]),
    /// unless `done` holds after arming the monitor or reading the futex
    /// word. Returns false, without pausing, once the budget is exhausted.
    #[must_use]
    #[inline]
    pub(crate) fn pause_until(
        &mut self,
        watched: WatchedWord<'_>,
        mut done: impl FnMut() -> bool,
    ) -> bool {
        if self.spins < SPIN_LIMIT {
            self.spins += 1;
            cpu_relax();
            return true;
        }
        #[cfg(sgc_genmc_short_waits)]
        {
            let _ = (watched, &mut done);
            false
        }
        #[cfg(not(sgc_genmc_short_waits))]
        {
            self.timed.pause_until(watched, &mut done)
        }
    }
}

/// Wakes the participants blocked in [`BoundedWait::pause_until`] on
/// `watched`. Call after the write that may end their wait.
#[inline]
pub(crate) fn wake_waiters(watched: WatchedWord<'_>) {
    #[cfg(all(not(sgc_genmc_short_waits), target_os = "linux"))]
    timed::futex::wake_all(watched);
    #[cfg(not(all(not(sgc_genmc_short_waits), target_os = "linux")))]
    let _ = watched;
}

/// Stages 2 to 4, which read the clock.
#[cfg(not(sgc_genmc_short_waits))]
mod timed {
    use super::{WatchedWord, cpu_relax};

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
    /// The end of stage 2, after stage 1.
    #[cfg(target_os = "linux")]
    const MONITORED_PERIOD: Nanos = 20 * US;
    /// The first futex timeout (see stage 3).
    #[cfg(target_os = "linux")]
    const FIRST_BLOCK: Nanos = 50 * US;
    /// The longest futex timeout (see stage 3).
    #[cfg(target_os = "linux")]
    const MAX_BLOCK: Nanos = MS;
    /// Lets a descheduled owner or pinner sharing this CPU run. Not before
    /// the 16th call: most waits end sooner, and when another thread is
    /// runnable, a yield costs a context switch both ways.
    #[cfg(not(target_os = "linux"))]
    const CALLS_PER_YIELD: u32 = 16;

    /// The state of stages 2 to 4.
    pub(super) struct Stages {
        /// Calls after stage 1; the first one starts the budget.
        sleeps: u32,
        deadline: Nanos,
        #[cfg(target_os = "linux")]
        monitored_until: Nanos,
        #[cfg(target_os = "linux")]
        next_block: Nanos,
    }

    impl Stages {
        #[inline(always)]
        pub(super) const fn new() -> Self {
            Stages {
                sleeps: 0,
                deadline: 0,
                #[cfg(target_os = "linux")]
                monitored_until: 0,
                #[cfg(target_os = "linux")]
                next_block: FIRST_BLOCK,
            }
        }

        /// One call of stages 2 to 4.
        pub(super) fn pause_until(
            &mut self,
            watched: WatchedWord<'_>,
            done: &mut impl FnMut() -> bool,
        ) -> bool {
            let now = clock::now();
            if self.sleeps == 0 {
                self.deadline = now + BUDGET;
                #[cfg(target_os = "linux")]
                {
                    self.monitored_until = now + MONITORED_PERIOD;
                }
            } else if now >= self.deadline {
                return false;
            }
            self.sleeps += 1;
            #[cfg(target_os = "linux")]
            let stage_end = {
                if now >= self.monitored_until {
                    futex::wait_for_change(watched, done, self.next_block.min(self.deadline - now));
                    self.next_block = (self.next_block * 2).min(MAX_BLOCK);
                    return true;
                }
                self.monitored_until
            };
            #[cfg(not(target_os = "linux"))]
            let stage_end = {
                if self.sleeps.is_multiple_of(CALLS_PER_YIELD) {
                    crate::util::sched_yield();
                }
                self.deadline
            };
            monitor::sleep_until_line_written(
                monitor::current(),
                watched,
                done,
                MAX_SLEEP.min(stage_end - now),
            );
            true
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

        /// The watched half's current value. Loaded as the whole word, so
        /// that Rust code never accesses the word with mixed sizes; the
        /// value is that of the half at the same point in its coherence
        /// order.
        #[cfg(target_os = "linux")]
        #[inline(always)]
        fn load(self) -> u32 {
            (self.word.load(core::sync::atomic::Ordering::Relaxed) >> (32 * self.high as u32))
                as u32
        }
    }

    /// The C `struct timespec` of the supported 64-bit targets.
    #[repr(C)]
    #[cfg_attr(
        not(any(target_os = "linux", target_vendor = "apple")),
        allow(dead_code)
    )]
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
        #[cfg(any(
            target_arch = "aarch64",
            target_arch = "riscv64",
            target_arch = "loongarch64"
        ))]
        const SYS_FUTEX: i64 = 98;
        #[cfg(target_arch = "powerpc64")]
        const SYS_FUTEX: i64 = 221;
        // The n64 ABI (mips64el).
        #[cfg(target_arch = "mips64")]
        const SYS_FUTEX: i64 = 5194;
        #[cfg(not(any(
            target_arch = "x86_64",
            target_arch = "aarch64",
            target_arch = "riscv64",
            target_arch = "loongarch64",
            target_arch = "powerpc64",
            target_arch = "mips64"
        )))]
        compile_error!(
            "futex system call number unknown on this architecture; supported: \
             x86_64, aarch64, riscv64, loongarch64, powerpc64, mips64"
        );
        const FUTEX_WAIT: i64 = 0;
        const FUTEX_WAKE: i64 = 1;

        /// Blocks on the futex at `watched` until it changes and
        /// [`wake_all`] is called on it, or for at most `timeout`, unless
        /// `done` already holds after the futex word is read.
        pub(in super::super) fn wait_for_change(
            watched: WatchedWord<'_>,
            done: &mut impl FnMut() -> bool,
            timeout: Nanos,
        ) {
            // A write after this load makes FUTEX_WAIT return at once.
            let seen = watched.load();
            if done() {
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
            done: &mut impl FnMut() -> bool,
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
                        if !done() {
                            // SAFETY: as above.
                            unsafe { x86::sleep_waitpkg(ticks) };
                        }
                    } else {
                        // SAFETY: detect() found MONITORX/MWAITX.
                        unsafe { x86::arm_mwaitx(p) };
                        if !done() {
                            // SAFETY: as above.
                            unsafe { x86::sleep_mwaitx(ticks) };
                        }
                    }
                }
                #[cfg(target_arch = "aarch64")]
                Mechanism::Wfet => {
                    arm64::arm_wfet(watched.as_ptr());
                    if !done() {
                        // SAFETY: detect() found FEAT_WFxT.
                        unsafe { arm64::sleep_wfet(duration) };
                    } else {
                        arm64::clrex();
                    }
                }
                _ => {
                    for _ in 0..64 {
                        if done() {
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
        use core::sync::atomic::Ordering::{Relaxed, Release};
        use std::thread;
        use std::time::{Duration, Instant};

        use super::monitor::{self, Mechanism};
        use crate::wait::{BoundedWait, WatchedWord, wake_waiters};

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
        /// wait took, as an error if it timed out.
        fn wait_for_write(delay: Duration, wake: bool) -> Result<Duration, Duration> {
            let line = Line(AtomicU64::new(0));
            let word = &line.0;
            thread::scope(|s| {
                s.spawn(|| {
                    thread::sleep(delay);
                    word.store(1, Release);
                    if wake {
                        wake_waiters(WatchedWord::low(word));
                    }
                });
                let start = Instant::now();
                let mut wait = BoundedWait::new();
                let written = || word.load(Relaxed) != 0;
                let mut timed_out = false;
                while word.load(core::sync::atomic::Ordering::Acquire) == 0 {
                    if !wait.pause_until(WatchedWord::low(word), written) {
                        timed_out = true;
                        break;
                    }
                }
                let waited = start.elapsed();
                if timed_out { Err(waited) } else { Ok(waited) }
            })
        }

        #[test]
        fn write_ends_wait() {
            let _serial = timing_test();
            // A woken write ends the wait soon after it, even once the waiter
            // blocks (on Linux, from 20 us). Retry to tolerate an unlucky
            // scheduling delay.
            for _ in 0..5 {
                let waited = wait_for_write(Duration::from_micros(200), true);
                let waited = waited.expect("the wait timed out");
                assert!(waited > Duration::ZERO);
                if waited < Duration::from_millis(1) {
                    return;
                }
            }
            panic!("a woken write did not end the wait promptly");
        }

        #[test]
        fn unwoken_write_ends_wait() {
            let _serial = timing_test();
            // Without a wake, a blocked waiter still sees the write by its
            // next timeout, at most 1 ms later, before the 5 ms budget.
            let waited = wait_for_write(Duration::from_millis(2), false);
            let waited = waited.expect("the wait timed out");
            assert!(waited > Duration::ZERO);
            assert!(waited < Duration::from_millis(4), "waited {waited:?}");
        }

        #[test]
        fn unwritten_wait_exhausts_budget() {
            let _serial = timing_test();
            let line = Line(AtomicU64::new(0));
            let mut wait = BoundedWait::new();
            let start = Instant::now();
            while wait.pause_until(WatchedWord::low(&line.0), || line.0.load(Relaxed) != 0) {}
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
                assert!(wait.pause_until(WatchedWord::low(&line.0), || true));
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
                        &mut || true,
                        8_000,
                    );
                    monitor::sleep_until_line_written(
                        Mechanism::Wfet,
                        watched,
                        &mut || false,
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
            let mut written = || {
                calls += 1;
                line.0.load(Relaxed) != 0
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
