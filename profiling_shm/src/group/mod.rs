/// SIMD group probe abstraction.
///
/// Selected at compile time based on target architecture:
/// - x86_64  → SSE2 (WIDTH = 16, BitMask = u16)
/// - aarch64 → NEON (WIDTH = 16, BitMask = u16)
/// - else    → generic u64 word (WIDTH = 8, BitMask = u8)
#[cfg(target_arch = "x86_64")]
mod sse2;
#[cfg(target_arch = "x86_64")]
pub use sse2::{Group, WIDTH};

#[cfg(target_arch = "aarch64")]
mod neon;
#[cfg(target_arch = "aarch64")]
pub use neon::{Group, WIDTH};

#[cfg(not(any(target_arch = "x86_64", target_arch = "aarch64")))]
mod generic;
#[cfg(not(any(target_arch = "x86_64", target_arch = "aarch64")))]
pub use generic::{Group, WIDTH};
