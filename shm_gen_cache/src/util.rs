//! Small helpers.

use core::sync::atomic::{AtomicU64, Ordering::Relaxed};

/// The architectural cache-line size used for every shared alignment:
/// 128 bytes on aarch64, 64 elsewhere. It is part of the shared layout, so
/// it is a fixed per-architecture constant rather than a compiler's
/// destructive-interference estimate, which may vary.
#[cfg(target_arch = "aarch64")]
pub const CACHE_LINE: usize = 128;
/// The architectural cache-line size used for every shared alignment:
/// 128 bytes on aarch64, 64 elsewhere.
#[cfg(not(target_arch = "aarch64"))]
pub const CACHE_LINE: usize = 64;

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
/// `wait::BoundedWait` have. Inline assembly is used, rather than
/// `spin_loop()`, so that the pause is a compiler memory barrier as well.
///
/// Under GenMC it compiles to nothing: inline assembly is not supported
/// there.
#[inline(always)]
pub(crate) fn cpu_relax() {
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

/// Yields the processor (`sched_yield`). Used by the rotation waits outside
/// Linux only; not available in GenMC builds, whose short rotation waits
/// never yield.
#[cfg(all(not(sgc_genmc_short_waits), not(target_os = "linux")))]
#[inline]
pub(crate) fn sched_yield() {
    #[cfg(feature = "std")]
    std::thread::yield_now();
    #[cfg(not(feature = "std"))]
    {
        unsafe extern "C" {
            fn sched_yield() -> i32;
        }
        // SAFETY: POSIX sched_yield has no preconditions.
        unsafe {
            sched_yield();
        }
    }
}

/// How many epochs `local_epoch` (a 32-bit stamp) lags behind
/// `global_epoch`, in wrapping 32-bit arithmetic.
#[inline(always)]
pub(crate) const fn age_of(global_epoch: u64, local_epoch: u32) -> u32 {
    (global_epoch as u32).wrapping_sub(local_epoch)
}

/// Rounds up to an 8-byte boundary, in 64-bit arithmetic so that 32-bit
/// lengths cannot overflow.
#[inline(always)]
pub(crate) const fn align8(value: u64) -> u64 {
    (value + 7) & !7
}

/// Rounds a 32-bit value up to a power-of-two alignment; `None` on
/// overflow.
#[inline(always)]
pub(crate) const fn checked_round_up(value: u32, alignment: u32) -> Option<u32> {
    match value.checked_add(alignment - 1) {
        Some(v) => Some(v & !(alignment - 1)),
        None => None,
    }
}

/// Loads the 8 bytes at `src` as a little-endian word. The fixed size lowers
/// to a single (possibly unaligned) load, never a library call.
///
/// # Safety
/// `src..src + 8` must be readable.
#[inline(always)]
pub(crate) unsafe fn load_u64(src: *const u8) -> u64 {
    // SAFETY: guaranteed by the caller.
    unsafe { src.cast::<u64>().read_unaligned() }
}

/// Returns `src[0..n)`, `0 < n < 8`, zero-extended into a little-endian
/// word.
///
/// Only fixed-size loads are used: a variable-length copy would be an
/// out-of-line call, and it would force the destination onto the stack.
/// `prefix_readable` says the `8 - n` bytes before `src` belong to the same
/// buffer (a full word of it was already consumed); then one overlapping
/// 8-byte load ending at `src + n`, shifted down, suffices. Otherwise the
/// value is composed from 4-, 2- and 1-byte loads. Either way no byte
/// outside the caller's buffer is read.
///
/// # Safety
/// `src..src + n` must be readable, and so must `src + n - 8..src` if
/// `prefix_readable`.
#[inline(always)]
pub(crate) unsafe fn load_tail(src: *const u8, n: usize, prefix_readable: bool) -> u64 {
    production_assert!(n > 0 && n < 8);
    if prefix_readable {
        // SAFETY: the 8 bytes ending at src + n are readable (caller).
        return unsafe { load_u64(src.add(n).sub(8)) } >> ((8 - n) * 8);
    }
    let mut v: u64 = 0;
    let mut pos: usize = 0;
    // SAFETY (all three loads): within src..src + n, as n's bits select them.
    if n & 4 != 0 {
        v = unsafe { src.cast::<u32>().read_unaligned() } as u64;
        pos = 4;
    }
    if n & 2 != 0 {
        v |= (unsafe { src.add(pos).cast::<u16>().read_unaligned() } as u64) << (pos * 8);
        pos += 2;
    }
    if n & 1 != 0 {
        v |= (unsafe { src.add(pos).read() } as u64) << (pos * 8);
    }
    v
}

/// Appends `src[0..n)` to the record words starting at word index `w`,
/// one relaxed store per word; the final word is zero-padded. Returns the
/// next free word index.
///
/// # Safety
/// `src..src + n` must be readable and `words[w..w + ceil(n / 8))` must be
/// valid shared record words.
#[inline(always)]
pub(crate) unsafe fn emit_bytes_relaxed(
    words: *const AtomicU64,
    mut w: u32,
    src: *const u8,
    n: usize,
) -> u32 {
    let mut i: usize = 0;
    while i + 8 <= n {
        // SAFETY: guaranteed by the caller.
        unsafe { (*words.add(w as usize)).store(load_u64(src.add(i)), Relaxed) };
        i += 8;
        w += 1;
    }
    if i < n {
        // load_tail zero-extends, which is the padding.
        // SAFETY: guaranteed by the caller; a full word precedes src + i
        // whenever i != 0.
        unsafe {
            (*words.add(w as usize)).store(load_tail(src.add(i), n - i, i != 0), Relaxed);
        }
        w += 1;
    }
    w
}

/// Appends `count` already-padded words without narrowing the final source
/// access. Returns the next free word index.
///
/// # Safety
/// `src..src + count` must be readable words, and `words[w..w + count)`
/// valid shared record words.
#[inline(always)]
pub(crate) unsafe fn emit_words_relaxed(
    words: *const AtomicU64,
    mut w: u32,
    src: *const u64,
    count: usize,
) -> u32 {
    let mut i: usize = 0;
    while i < count {
        // SAFETY: guaranteed by the caller.
        unsafe { (*words.add(w as usize)).store(src.add(i).read(), Relaxed) };
        i += 1;
        w += 1;
    }
    w
}
