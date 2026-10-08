//! One arena: control word, optional exact occupancy
//! counter, open-addressing index and bump-allocated record area.
//!
//! ```text
//! +0                    control line: ctl word (epoch:32 | sealed:1 | bump:31)
//! +CL (exact mode)      occupancy line: AtomicU32 entry count
//! +arena_header_size    index: bucket_count x u64 {epoch stamp:32, ref:32}
//! +records_offset       record area: record_area_size bytes, 8-aligned
//! ```
//!
//! The arena's size is a multiple of the cache line, so every arena (and
//! its control line) starts on one. Only the fixed-size header is a Rust
//! struct; the variable-length index and record area are addressed through
//! offsets from [`HotParams`].

pub(crate) mod ctl;
pub(crate) mod index;
pub(crate) mod probe;
mod put;
pub(crate) mod records;

use core::marker::PhantomData;
use core::ptr::NonNull;
use core::sync::atomic::{AtomicU32, AtomicU64};

use crate::config::HotParams;
use crate::util::CACHE_LINE;

pub(crate) use probe::{ProbeError, probe};
pub(crate) use put::{PutStatus, table_put};

/// The arena header's first line: the control word alone.
#[cfg_attr(target_arch = "aarch64", repr(C, align(128)))]
#[cfg_attr(not(target_arch = "aarch64"), repr(C, align(64)))]
pub(crate) struct ArenaCtlLine {
    pub(crate) ctl: AtomicU64,
}

/// The exact-mode header's second line: the entry counter alone.
#[cfg_attr(target_arch = "aarch64", repr(C, align(128)))]
#[cfg_attr(not(target_arch = "aarch64"), repr(C, align(64)))]
pub(crate) struct ExactOccupancyLine {
    pub(crate) value: AtomicU32,
}

const _: () = {
    assert!(core::mem::size_of::<ArenaCtlLine>() == CACHE_LINE);
    assert!(core::mem::size_of::<ExactOccupancyLine>() == CACHE_LINE);
};

/// A pointer view of one arena of a mapping (zero-cost, `Copy`).
#[derive(Clone, Copy)]
pub struct ArenaView<'m> {
    base: NonNull<u8>,
    index: NonNull<AtomicU64>,
    records: NonNull<u8>,
    _m: PhantomData<&'m AtomicU64>,
}

impl<'m> ArenaView<'m> {
    /// # Safety
    /// `base` must point to an arena laid out per `hp`, valid for `'m`.
    #[inline(always)]
    pub(crate) unsafe fn new(base: NonNull<u8>, hp: &HotParams) -> Self {
        // SAFETY: both offsets are within the arena (caller).
        unsafe {
            Self {
                base,
                index: base.add(hp.arena_header_size as usize).cast(),
                records: base.add(hp.records_offset),
                _m: PhantomData,
            }
        }
    }

    /// The control word.
    #[inline(always)]
    pub(crate) fn ctl(self) -> &'m AtomicU64 {
        // SAFETY: the control line starts the arena.
        unsafe { &self.base.cast::<ArenaCtlLine>().as_ref().ctl }
    }

    /// The exact occupancy counter. Only exists in exact mode.
    #[inline(always)]
    pub(crate) fn exact_occupancy(self) -> &'m AtomicU32 {
        // SAFETY: exact-mode arenas have the counter line after the control
        // line; only Exact calls this.
        unsafe {
            &self
                .base
                .add(CACHE_LINE)
                .cast::<ExactOccupancyLine>()
                .as_ref()
                .value
        }
    }

    /// Index entry `i`, `i < bucket_count`.
    #[inline(always)]
    pub(crate) fn slot(self, i: u32) -> &'m AtomicU64 {
        // SAFETY: callers mask i with bucket_mask (or bound it explicitly).
        unsafe { self.index.add(i as usize).as_ref() }
    }

    /// The record word at byte `offset`, 8-aligned and below the record
    /// area size.
    #[inline(always)]
    pub(crate) fn word(self, offset: u32) -> &'m AtomicU64 {
        // SAFETY: callers bound offset by the record area (validated
        // lengths or the reservation).
        unsafe {
            self.records
                .add(offset as usize)
                .cast::<AtomicU64>()
                .as_ref()
        }
    }

    /// The record words starting at byte `offset`.
    #[inline(always)]
    pub(crate) fn words_at(self, offset: u32) -> *const AtomicU64 {
        // SAFETY: as for word().
        unsafe {
            self.records
                .add(offset as usize)
                .cast::<AtomicU64>()
                .as_ptr()
        }
    }

    /// The first byte of the record area.
    #[inline(always)]
    pub(crate) fn records(self) -> NonNull<u8> {
        self.records
    }

    /// The index entries, for initialisation.
    #[inline(always)]
    pub(crate) fn index(self) -> NonNull<AtomicU64> {
        self.index
    }
}
