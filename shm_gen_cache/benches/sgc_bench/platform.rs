//! Thread placement, clocks and spin hints.

use std::sync::OnceLock;
use std::time::Instant;

/// Monotonic nanoseconds since the first call in the process.
#[inline]
pub fn now_ns() -> u64 {
    static EPOCH: OnceLock<Instant> = OnceLock::new();
    let epoch = *EPOCH.get_or_init(Instant::now);
    Instant::now().duration_since(epoch).as_nanos() as u64
}

/// The bench's own pause hint (`pause` on x86, `yield` on aarch64),
/// independent of the hint the library uses in its own waits.
#[inline(always)]
pub fn spin_pause() {
    #[cfg(any(target_arch = "x86_64", target_arch = "x86"))]
    // SAFETY: a hint without operands or side effects.
    unsafe {
        std::arch::asm!("pause", options(nostack, preserves_flags));
    }
    #[cfg(target_arch = "aarch64")]
    // SAFETY: a hint without operands or side effects.
    unsafe {
        std::arch::asm!("yield", options(nostack, preserves_flags));
    }
    #[cfg(not(any(target_arch = "x86_64", target_arch = "x86", target_arch = "aarch64")))]
    std::sync::atomic::compiler_fence(std::sync::atomic::Ordering::SeqCst);
}

/// Keeps workers on performance cores (macOS) while there are enough of
/// them; a no-op elsewhere.
pub fn raise_thread_priority() {
    #[cfg(target_os = "macos")]
    // SAFETY: sets the calling thread's own QoS class.
    unsafe {
        libc::pthread_set_qos_class_self_np(libc::qos_class_t::QOS_CLASS_USER_INTERACTIVE, 0);
    }
}

/// Binds worker `t` to the t-th CPU of the process's affinity mask
/// (wrapping), so that `taskset -c <one CPU per core> ... --pin` gives every
/// worker its own physical core and a deterministic placement across
/// caches. Linux only; a no-op unless `pin`.
pub fn pin_worker(pin: bool, t: u32) {
    #[cfg(target_os = "linux")]
    {
        if !pin {
            return;
        }
        // SAFETY: plain libc calls on a zeroed cpu_set_t owned here.
        unsafe {
            let mut allowed: libc::cpu_set_t = std::mem::zeroed();
            if libc::sched_getaffinity(0, size_of::<libc::cpu_set_t>(), &mut allowed) != 0 {
                return;
            }
            let count = libc::CPU_COUNT(&allowed);
            if count == 0 {
                return;
            }
            let mut want = (t % count as u32) as i32;
            for cpu in 0..libc::CPU_SETSIZE as usize {
                if libc::CPU_ISSET(cpu, &allowed) {
                    if want == 0 {
                        let mut one: libc::cpu_set_t = std::mem::zeroed();
                        libc::CPU_SET(cpu, &mut one);
                        libc::pthread_setaffinity_np(
                            libc::pthread_self(),
                            size_of::<libc::cpu_set_t>(),
                            &one,
                        );
                        return;
                    }
                    want -= 1;
                }
            }
        }
    }
    #[cfg(not(target_os = "linux"))]
    let _ = (pin, t);
}

pub const NO_CPU_LOCATION: u32 = u32::MAX;
const CPU_NUMBER_MASK: u64 = 0xfff;
const CPU_CLUSTER_MASK: u64 = 0xff000;

/// The current CPU and cluster (Apple silicon), or [`NO_CPU_LOCATION`].
pub fn current_cpu_location() -> u32 {
    #[cfg(all(target_os = "macos", target_arch = "aarch64"))]
    {
        // XNU publishes the current CPU number and logical cluster in
        // TPIDR_EL0, in this layout.
        let value: u64;
        // SAFETY: reads a user-readable system register.
        unsafe {
            std::arch::asm!("mrs {}, TPIDR_EL0", out(reg) value, options(nostack, preserves_flags));
        }
        (value & (CPU_NUMBER_MASK | CPU_CLUSTER_MASK)) as u32
    }
    #[cfg(not(all(target_os = "macos", target_arch = "aarch64")))]
    NO_CPU_LOCATION
}

pub fn cpu_number(location: u32) -> u32 {
    (u64::from(location) & CPU_NUMBER_MASK) as u32
}

pub fn cpu_cluster(location: u32) -> u32 {
    ((u64::from(location) & CPU_CLUSTER_MASK) >> 12) as u32
}

/// Whether `--check-pinning` can work here.
pub const CAN_CHECK_PINNING: bool = cfg!(all(target_os = "macos", target_arch = "aarch64"));
/// Whether `--huge-pages` can work here (and is the default).
pub const HUGE_PAGES_SUPPORTED: bool = cfg!(target_os = "linux");
