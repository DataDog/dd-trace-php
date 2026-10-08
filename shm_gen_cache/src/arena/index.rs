//! Index entries, refs and hashing.
//!
//! An index entry is `{u32 epoch stamp, u32 ref}`, i.e. stamp in bits 0-31
//! and ref in bits 32-63 of the 64-bit word. Initialisation is incarnation
//! zero (`{0, 0}`); cache epochs start at two, so both lookup generations
//! initially differ from it.
//!
//! A ref's low `offset_bits` store an 8-byte word offset into the record
//! area; all remaining bits store the high bits of the mixed hash (the tag),
//! which lets probes skip records whose hash differs. Only valid word
//! offsets need encoding; an area of N words needs `bit_width(N - 1)` offset
//! bits, and a record area below 2^31 bytes leaves at least four tag bits.
//! The low bits of the mixed hash select the home bucket; the tag bits are
//! the top ones, so no hash bit serves both purposes.

use crate::config::HotParams;

/// The stamp of an initialised (never written) entry.
pub(crate) const EMPTY_EPOCH: u32 = 0;

/// Mask of the stamp bits of an entry word.
pub(crate) const EPOCH_BITS: u64 = 0x0000_0000_FFFF_FFFF;

/// The finaliser of MurmurHash3: spreads the caller's hash over all bits.
#[inline(always)]
pub const fn mix_hash(mut key: u64) -> u64 {
    key ^= key >> 33;
    key = key.wrapping_mul(0xff51_afd7_ed55_8ccd);
    key ^= key >> 33;
    key
}

/// The home bucket of `hash`.
#[cfg_attr(
    not(any(feature = "verify", feature = "test-access")),
    allow(dead_code)
)]
#[inline(always)]
pub const fn bucket(hash: u64, bucket_count: u32) -> u32 {
    (mix_hash(hash) % bucket_count as u64) as u32
}

/// An index entry word.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub(crate) struct IndexEntry(pub(crate) u64);

impl IndexEntry {
    #[inline(always)]
    pub(crate) const fn new(epoch: u32, r#ref: u32) -> Self {
        IndexEntry(epoch as u64 | (r#ref as u64) << 32)
    }

    #[inline(always)]
    pub(crate) const fn epoch(self) -> u32 {
        self.0 as u32
    }

    #[inline(always)]
    pub(crate) const fn r#ref(self) -> u32 {
        (self.0 >> 32) as u32
    }
}

/// The ref split of one configuration (it depends on the record area size).
#[derive(Clone, Copy)]
pub(crate) struct RefCodec {
    offset_bits: u32,
    offset_mask: u32,
    tag_shift: u32,
}

impl RefCodec {
    #[inline(always)]
    pub(crate) const fn new(hp: &HotParams) -> Self {
        RefCodec {
            offset_bits: hp.offset_bits,
            offset_mask: hp.offset_mask,
            tag_shift: hp.tag_shift,
        }
    }

    /// The top `tag_bits` bits of the mixed hash.
    #[inline(always)]
    pub(crate) const fn hash_tag(self, mixed_hash: u64) -> u32 {
        (mixed_hash >> self.tag_shift) as u32
    }

    /// Packs an 8-aligned record offset (below the record area size) and a
    /// tag.
    #[inline(always)]
    pub(crate) fn encode(self, record_offset: u32, tag: u32) -> u32 {
        production_assert!(record_offset.is_multiple_of(8));
        production_assert!(tag <= u32::MAX >> self.offset_bits);
        (tag << self.offset_bits) | (record_offset / 8)
    }

    /// The record byte offset of a ref.
    #[inline(always)]
    pub(crate) const fn offset(self, r#ref: u32) -> u32 {
        (r#ref & self.offset_mask) * 8
    }

    /// The tag of a ref.
    #[inline(always)]
    pub(crate) const fn tag(self, r#ref: u32) -> u32 {
        r#ref >> self.offset_bits
    }

    /// The offset bits of a ref.
    #[inline(always)]
    pub(crate) const fn offset_bits(self) -> u32 {
        self.offset_bits
    }

    /// Mask of the stamp and tag bits of an entry word (everything but the
    /// offset).
    #[inline(always)]
    pub(crate) const fn candidate_bits(self) -> u64 {
        EPOCH_BITS | ((!self.offset_mask as u64) << 32)
    }
}
