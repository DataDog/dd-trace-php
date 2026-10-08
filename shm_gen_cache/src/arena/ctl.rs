//! The arena control word.
//!
//! Stored as a `u64` so a reservation can claim bytes with one `fetch_add`
//! on the bump field instead of a load + CAS retry loop. The encoding (part
//! of the shared layout, little-endian): epoch stamp in bits
//! 0-31, sealed in bit 32, bump in bits 33-63. A carry out of bit 63 is
//! discarded by `fetch_add`; the configuration checks bound every
//! incarnation's overshoot so the bump never wraps.

/// A decoded-on-demand control word.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub(crate) struct CtlWord(pub(crate) u64);

impl CtlWord {
    pub(crate) const SEALED_SHIFT: u32 = 32;
    pub(crate) const BUMP_SHIFT: u32 = 33;
    pub(crate) const SEALED: u64 = 1 << Self::SEALED_SHIFT;

    /// `bump` must be below 2^31.
    #[inline(always)]
    pub(crate) const fn new(epoch: u32, sealed: bool, bump: u32) -> Self {
        CtlWord(
            epoch as u64
                | (sealed as u64) << Self::SEALED_SHIFT
                | (bump as u64) << Self::BUMP_SHIFT,
        )
    }

    /// The incarnation's 32-bit epoch stamp.
    #[inline(always)]
    pub(crate) const fn epoch(self) -> u32 {
        self.0 as u32
    }

    /// Whether the incarnation is retired (no further reservations).
    #[inline(always)]
    pub(crate) const fn sealed(self) -> bool {
        (self.0 >> Self::SEALED_SHIFT) & 1 != 0
    }

    /// Bytes claimed so far (may exceed the record area: failed claims
    /// overshoot).
    #[inline(always)]
    pub(crate) const fn bump(self) -> u32 {
        (self.0 >> Self::BUMP_SHIFT) as u32
    }

    /// The word that adds `n` to bump.
    #[inline(always)]
    pub(crate) const fn bump_increment(n: u32) -> u64 {
        (n as u64) << Self::BUMP_SHIFT
    }
}
