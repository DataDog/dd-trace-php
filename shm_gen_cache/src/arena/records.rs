//! Records: header, lengths and key comparison.
//!
//! A record starts at an 8-aligned offset of the record area:
//!
//! ```text
//! word 0: header  {u32 epoch stamp (bits 0-31), u32 len (bits 32-63)}
//!         len = payload bytes, excluding the header and final padding
//! word 1: lengths {u32 key_len (bits 0-31), u32 value_len (bits 32-63)}
//! words 2..: key bytes, zero-padded to a word, then value bytes, the final
//!            word zero-padded
//! ```
//!
//! Every access is a 64-bit atomic word access. The header is stored last,
//! with release (W5).

use core::sync::atomic::Ordering::Relaxed;

use super::ArenaView;
use crate::util::{align8, load_tail, load_u64};

/// A record header word.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub(crate) struct RecordHeader(pub(crate) u64);

impl RecordHeader {
    #[inline(always)]
    pub(crate) const fn new(epoch: u32, len: u32) -> Self {
        RecordHeader(epoch as u64 | (len as u64) << 32)
    }

    #[inline(always)]
    pub(crate) const fn epoch(self) -> u32 {
        self.0 as u32
    }

    #[inline(always)]
    pub(crate) const fn len(self) -> u32 {
        (self.0 >> 32) as u32
    }

    /// Bytes of a record with payload `len`, including the header and the
    /// final padding.
    #[inline(always)]
    pub(crate) const fn record_size(len: u32) -> u64 {
        8 + align8(len as u64)
    }
}

/// A record lengths word.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub struct RecordLengths(pub(crate) u64);

impl RecordLengths {
    #[inline(always)]
    pub(crate) const fn new(key_len: u32, value_len: u32) -> Self {
        RecordLengths(key_len as u64 | (value_len as u64) << 32)
    }

    /// The key length.
    #[inline(always)]
    pub const fn key_len(self) -> u32 {
        self.0 as u32
    }

    /// The value length.
    #[inline(always)]
    pub const fn value_len(self) -> u32 {
        (self.0 >> 32) as u32
    }

    /// The header's `len` of a record with these lengths (32-bit wrapping
    /// arithmetic, which cannot wrap for any valid key and value size).
    #[inline(always)]
    pub(crate) const fn payload_size(self) -> u32 {
        8u32.wrapping_add((self.key_len().wrapping_add(7)) & !7)
            .wrapping_add(self.value_len())
    }
}

/// Compares the stored key at `key_offset`, whose length the caller already
/// matched against `key.len()`, with `key`.
///
/// Each stored word is one relaxed load, in order, stopping at the first
/// mismatch. Only the key's bytes are compared: the final word's padding is
/// masked off, so this does not rely on writers zero-padding it. The
/// caller's key is private, so it is read with plain fixed-size loads (see
/// [`load_tail`]). Control flow follows `key.len()`, which is in a register
/// early, rather than the stored length, so mispredicted word-count branches
/// resolve without waiting for the record's lengths load.
#[inline]
pub(crate) fn key_matches(arena: ArenaView<'_>, key_offset: u32, key: &[u8]) -> bool {
    let key_len = key.len() as u32;
    let full = key_len & !7;
    let mut pos: u32 = 0;
    while pos < full {
        // SAFETY: pos + 8 <= key.len().
        let k = unsafe { load_u64(key.as_ptr().add(pos as usize)) };
        if arena.word(key_offset + pos).load(Relaxed) != k {
            return false;
        }
        pos += 8;
    }
    let rem = key_len - full;
    if rem == 0 {
        return true;
    }
    let stored = arena.word(key_offset + full).load(Relaxed);
    let mask = !0u64 >> ((8 - rem) * 8);
    // SAFETY: key[full..] holds rem bytes, preceded by a full word if
    // full != 0.
    let tail = unsafe { load_tail(key.as_ptr().add(full as usize), rem as usize, full != 0) };
    (stored ^ tail) & mask == 0
}
