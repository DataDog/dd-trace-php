//! Configuration: validation and the derived parameters and layout.
//!
//! One `const fn`, [`Config::resolve`], performs every check and computes
//! the derived constants; it serves both kinds of [`Params`]:
//!
//! * [`static_config!`](crate::static_config) evaluates it in a `const`
//!   (a failed check is a compile error) and
//!   produces a zero-sized type whose parameters fold to constants;
//! * [`RuntimeParams`] evaluates it at run time (a failed check is
//!   [`Error::InvalidArgument`]) and carries the values.
//!
//! The occupancy mode is a type parameter in both cases: it changes the arena
//! layout and removes code paths at compile time.

use core::marker::PhantomData;

use crate::error::Error;
use crate::occupancy::OccupancyMode;
use crate::pid::{GetPid, PlatformGetPid};
use crate::util::{CACHE_LINE, align8, checked_round_up};

/// Cache configuration; also the C API's `ddog_sgc_Config`.
///
/// Zero in `record_area_size`, `max_occupancy` or `reservation_chunk_size`
/// selects the default for that field (a zero value would be invalid for the
/// first two; for the third, zero is also how the default is stored).
#[repr(C)]
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct Config {
    /// Participant slots: at most this many registrations at once.
    pub participant_capacity: u32,
    /// Index entries per arena: a power of two and a multiple of 8.
    pub bucket_count: u32,
    /// Longest key, in bytes.
    pub max_key_size: u32,
    /// Longest value, in bytes.
    pub max_value_size: u32,
    /// Record bytes per arena: a positive multiple of 8 below 2^31. Zero
    /// selects `bucket_count * (16 + align8(max_key_size) +
    /// align8(max_value_size))`.
    pub record_area_size: u32,
    /// Positive rotation target (entries per arena); already-reserved
    /// records may exceed it. Zero selects `(uint32_t)(bucket_count * 0.7)`.
    pub max_occupancy: u32,
    /// Bytes claimed at once from an arena's record area. Subsequent records
    /// use the participant's remaining chunk without a shared reservation
    /// RMW. Must be a multiple of 8 and no larger than the record area. Zero
    /// selects exactly five times the largest record (including headers and
    /// separate key/value padding); it does not disable batching. A record
    /// at least as large as the chunk, or one near capacity when a whole
    /// chunk no longer fits, claims just its own size. A tail is abandoned
    /// when the next record does not fit in it, on rotation, and when the
    /// participant's registration ends.
    pub reservation_chunk_size: u32,
    /// Forces an exact shared occupancy counter even where the estimator
    /// would be selected.
    pub always_exact_occupancy: bool,
}

impl Config {
    /// The default configuration.
    ///
    /// cbindgen:ignore
    pub const DEFAULT: Config = Config {
        participant_capacity: 128,
        bucket_count: 1024,
        max_key_size: 64,
        max_value_size: 1024,
        record_area_size: 0,
        max_occupancy: 0,
        reservation_chunk_size: 0,
        always_exact_occupancy: false,
    };

    /// Runs every configuration check and computes the derived parameters and
    /// the shared layout.
    pub const fn resolve(self) -> Result<Derived, Error> {
        let b = self.bucket_count;
        // arena_params: power-of-two bucket count (so % is a mask), groups of
        // 8 never straddle the end partially.
        if b == 0 || b & (b - 1) != 0 || !b.is_multiple_of(8) {
            return Err(Error::InvalidArgument);
        }
        // Default record area: bucket_count * (8 + 8 + align8(key) +
        // align8(value)), in checked 32-bit arithmetic: the record area size
        // is a u32 field, so an overflowing default is rejected instead of
        // wrapping.
        let ras = if self.record_area_size != 0 {
            self.record_area_size
        } else {
            let (Some(k), Some(v)) = (
                checked_round_up(self.max_key_size, 8),
                checked_round_up(self.max_value_size, 8),
            ) else {
                return Err(Error::InvalidArgument);
            };
            let Some(per_bucket) = 16u32.checked_add(k) else {
                return Err(Error::InvalidArgument);
            };
            let Some(per_bucket) = per_bucket.checked_add(v) else {
                return Err(Error::InvalidArgument);
            };
            let Some(ras) = b.checked_mul(per_bucket) else {
                return Err(Error::InvalidArgument);
            };
            ras
        };
        // static_cast<u32>(bucket_count * 0.7)
        let max_occupancy = if self.max_occupancy != 0 {
            self.max_occupancy
        } else {
            (b as f64 * 0.7) as u32
        };

        // arena_params / index_ref
        if ras == 0 || ras >= 1 << 31 || !ras.is_multiple_of(8) {
            return Err(Error::InvalidArgument);
        }
        // cache_params
        if max_occupancy == 0 {
            return Err(Error::InvalidArgument);
        }
        let max_record_size: u64 =
            16 + align8(self.max_key_size as u64) + align8(self.max_value_size as u64);
        let chunk_size: u64 = if self.reservation_chunk_size != 0 {
            self.reservation_chunk_size as u64
        } else {
            5 * max_record_size
        };
        if max_record_size > ras as u64 || !chunk_size.is_multiple_of(8) || chunk_size > ras as u64
        {
            return Err(Error::InvalidArgument);
        }
        if !reservation_adds_fit_bump(self.participant_capacity, ras, chunk_size, max_record_size) {
            return Err(Error::InvalidArgument);
        }

        // index_ref: the low bits of a ref store an 8-byte word offset, the
        // remaining high bits a hash tag. An area of N words needs
        // bit_width(N - 1) offset bits; RAS < 2^31 leaves at least 4 tag bits.
        let max_word = ras / 8 - 1;
        let offset_bits = u32::BITS - max_word.leading_zeros();
        let tag_bits = 32 - offset_bits;
        let offset_mask = if offset_bits == 0 {
            0
        } else {
            u32::MAX >> (32 - offset_bits)
        };
        if tag_bits < 4 || max_word > offset_mask {
            return Err(Error::InvalidArgument);
        }
        // The bucket bits (low hash bits) and tag bits (high) must not
        // overlap within 64 bits.
        let bucket_bits = u32::BITS - (b - 1).leading_zeros();
        if bucket_bits + tag_bits > 64 {
            return Err(Error::InvalidArgument);
        }

        let estimates = estimates_occupancy(self.always_exact_occupancy, max_occupancy, b);

        // Shared layout (see cache.rs): header line, one line per
        // participant, three arenas each a header (1 line, plus the exact
        // occupancy line), the index and the record area, padded to a line.
        let arena_header_size = CACHE_LINE * if estimates { 1 } else { 2 };
        let records_offset = arena_header_size + 8 * b as usize;
        let arena_stride = (records_offset + ras as usize).next_multiple_of(CACHE_LINE);
        let arenas_offset = CACHE_LINE * (1 + self.participant_capacity as usize);
        let mapping_size = arenas_offset + 3 * arena_stride;

        Ok(Derived {
            hot: HotParams {
                bucket_mask: b - 1,
                offset_bits,
                offset_mask,
                tag_shift: 64 - tag_bits,
                record_area_size: ras,
                max_key_size: self.max_key_size,
                max_value_size: self.max_value_size,
                max_occupancy,
                chunk_size: chunk_size as u32,
                participant_capacity: self.participant_capacity,
                arena_header_size: arena_header_size as u32,
                arenas_offset,
                arena_stride,
                records_offset,
                target: max_occupancy as f64 / b as f64,
                buckets: b as f64,
            },
            mapping_size,
            header: Config {
                participant_capacity: self.participant_capacity,
                bucket_count: b,
                max_key_size: self.max_key_size,
                max_value_size: self.max_value_size,
                record_area_size: ras,
                max_occupancy,
                reservation_chunk_size: self.reservation_chunk_size,
                always_exact_occupancy: self.always_exact_occupancy,
            },
            estimates_occupancy: estimates,
        })
    }
}

impl Default for Config {
    fn default() -> Self {
        Self::DEFAULT
    }
}

/// Large arenas can estimate occupancy from the home slots that inserts
/// already probe, avoiding a shared atomic increment for every new key.
const fn estimates_occupancy(always_exact: bool, max_occupancy: u32, bucket_count: u32) -> bool {
    if always_exact {
        return false;
    }
    const THRESHOLD: u64 = 10000;
    max_occupancy < bucket_count
        && max_occupancy as u64 * max_occupancy as u64
            >= THRESHOLD * (bucket_count - max_occupancy) as u64
}

const _: () = {
    assert!(!estimates_occupancy(false, 4096 * 7 / 10, 4096));
    assert!(estimates_occupancy(false, 8192 * 7 / 10, 8192));
};

/// The bump `fetch_add` adds directly to the high 31 bits of the packed
/// control word. A carry out of bit 63 is discarded, leaving epoch and sealed
/// intact but wrapping bump to a small value, so a later reservation could
/// reuse an already allocated range in the same arena incarnation. A
/// successful claim whose precheck raced another claim may overshoot the
/// record area by less than one maximal claim; its usable tail is clipped to
/// capacity. Failed adds may overshoot by at most two maximal claims per
/// participant. A claim is the larger of a chunk and a maximal record. Prove
/// that the sum stays below 2^31, using division so the check itself cannot
/// overflow.
const fn reservation_adds_fit_bump(
    participant_capacity: u32,
    record_area_size: u32,
    chunk_size: u64,
    max_record_size: u64,
) -> bool {
    let bump_limit: u64 = 1 << 31;
    let area_size = record_area_size as u64;
    let max_claim_size = if chunk_size > max_record_size {
        chunk_size
    } else {
        max_record_size
    };
    let failed_adds_per_participant = 2 * max_claim_size;
    area_size < bump_limit
        && max_claim_size < bump_limit - area_size
        && participant_capacity as u64
            <= (bump_limit - 1 - area_size - max_claim_size) / failed_adds_per_participant
}

/// The parameters the lookup and insert paths read, kept together (and
/// first in [`Derived`]) so a runtime handle touches one or two cache
/// lines. Opaque outside the crate: they are offsets and masks the cache
/// writes through, so only [`Config::resolve`] produces them. Every hot entry point copies them into a local first: the handle
/// lives in memory whose address escaped to C, so without the copy every
/// field would be re-loaded after each acquire fence.
#[repr(C)]
#[derive(Clone, Copy, Debug, PartialEq)]
pub struct HotParams {
    /// `bucket_count - 1`.
    pub(crate) bucket_mask: u32,
    /// Index ref split: low `offset_bits` hold the record word offset.
    pub(crate) offset_bits: u32,
    /// `(1 << offset_bits) - 1`.
    pub(crate) offset_mask: u32,
    /// `64 - tag_bits`: the hash tag is `mixed_hash >> tag_shift`.
    pub(crate) tag_shift: u32,
    /// Record area bytes per arena.
    pub(crate) record_area_size: u32,
    /// Longest key.
    pub(crate) max_key_size: u32,
    /// Longest value.
    pub(crate) max_value_size: u32,
    /// Rotation target.
    pub(crate) max_occupancy: u32,
    /// Effective reservation chunk size (default resolved).
    pub(crate) chunk_size: u32,
    /// Participant slots.
    pub(crate) participant_capacity: u32,
    /// Bytes before an arena's index (1 or 2 cache lines).
    pub(crate) arena_header_size: u32,
    /// Offset of arena 0 in the mapping.
    pub(crate) arenas_offset: usize,
    /// Distance between arenas.
    pub(crate) arena_stride: usize,
    /// Offset of the record area within an arena.
    pub(crate) records_offset: usize,
    /// Estimator target: `max_occupancy / bucket_count`.
    pub(crate) target: f64,
    /// Estimator input: `bucket_count`.
    pub(crate) buckets: f64,
}

/// Everything [`Config::resolve`] computes.
///
/// Only [`Config::resolve`] constructs one (the fields are private), so a
/// `Derived` always describes a valid geometry: [`crate::Cache::initialize`]
/// and the safe operations write through its offsets unchecked.
///
/// Cache-line aligned: every lookup and insert reads it through the C
/// handle (a heap allocation), so it must not share a line with anything
/// a thread writes.
#[cfg_attr(target_arch = "aarch64", repr(C, align(128)))]
#[cfg_attr(not(target_arch = "aarch64"), repr(C, align(64)))]
#[derive(Clone, Copy, Debug, PartialEq)]
pub struct Derived {
    /// Hot-path parameters.
    pub(crate) hot: HotParams,
    /// Bytes of the shared mapping.
    pub(crate) mapping_size: usize,
    /// The values stored in the mapping header: defaults resolved, except
    /// `reservation_chunk_size`, which is stored as configured (zero stays
    /// zero).
    pub(crate) header: Config,
    /// Whether the configuration selects the occupancy estimator.
    pub(crate) estimates_occupancy: bool,
}

impl Derived {
    /// Bytes of the shared mapping.
    #[inline(always)]
    pub const fn mapping_size(&self) -> usize {
        self.mapping_size
    }

    /// The values stored in the mapping header: defaults resolved, except
    /// `reservation_chunk_size`, which is stored as configured (zero stays
    /// zero).
    #[inline(always)]
    pub const fn header(&self) -> &Config {
        &self.header
    }

    /// Whether the configuration selects the occupancy estimator.
    #[inline(always)]
    pub const fn estimates_occupancy(&self) -> bool {
        self.estimates_occupancy
    }
}

/// Cache parameters.
///
/// Everything the algorithm reads goes through [`Params::derived`]: for a
/// zero-sized static configuration it returns a reference to an associated
/// constant, so every field folds to a constant after inlining; for
/// [`RuntimeParams`] it returns the stored values.
///
/// # Safety
/// The cache writes through the returned offsets without checking them, so
/// an implementation must return, from every call on a value and its
/// copies, the same [`Derived`] produced by [`Config::resolve`] (which the
/// private fields of [`Derived`] already ensure), and must not override
/// [`Params::hot`]. [`crate::static_config!`] and [`RuntimeParams`] implement it.
pub unsafe trait Params: Copy {
    /// Participant identity and liveness backend.
    type Pid: GetPid;
    /// Exact counter or estimator; fixes the arena layout.
    type Occupancy: OccupancyMode;
    /// The resolved parameters.
    fn derived(&self) -> &Derived;

    /// The hot-path parameters, by value.
    #[inline(always)]
    fn hot(&self) -> HotParams {
        self.derived().hot
    }
}

/// Parameters chosen at run time, with occupancy mode `O`: a reference to
/// resolved values.
///
/// A reference rather than the values themselves keeps a [`Cache`] view two
/// words wide, so it is passed in registers and copied freely; every hot
/// entry point loads the [`HotParams`] it needs once, into locals, before
/// its first fence (an owned copy inside the participant handle would have
/// to be copied wholesale, or re-read from memory after each fence).
///
/// [`Cache`]: crate::Cache
#[derive(Debug)]
pub struct RuntimeParams<'p, O: OccupancyMode, Pid: GetPid = PlatformGetPid> {
    derived: &'p Derived,
    _types: PhantomData<(O, Pid)>,
}

impl<O: OccupancyMode, Pid: GetPid> Clone for RuntimeParams<'_, O, Pid> {
    #[inline(always)]
    fn clone(&self) -> Self {
        *self
    }
}

impl<O: OccupancyMode, Pid: GetPid> Copy for RuntimeParams<'_, O, Pid> {}

impl<'p, O: OccupancyMode, Pid: GetPid> RuntimeParams<'p, O, Pid> {
    /// Parameters from `derived` (see [`Config::resolve`]); fails with
    /// [`Error::InvalidArgument`] if they select the other occupancy mode.
    pub const fn new(derived: &'p Derived) -> Result<Self, Error> {
        if derived.estimates_occupancy != O::ESTIMATES {
            return Err(Error::InvalidArgument);
        }
        Ok(Self {
            derived,
            _types: PhantomData,
        })
    }
}

// SAFETY: `derived` comes from Config::resolve (private fields) and is
// fixed for the value's lifetime; `hot` is not overridden.
unsafe impl<O: OccupancyMode, Pid: GetPid> Params for RuntimeParams<'_, O, Pid> {
    type Pid = Pid;
    type Occupancy = O;

    #[inline(always)]
    fn derived(&self) -> &Derived {
        self.derived
    }
}

/// Maps a `bool` const to an occupancy mode, so a static configuration can
/// select its mode from its own resolved constants on stable Rust.
#[doc(hidden)]
pub struct SelectOccupancy<const ESTIMATES: bool>;

#[doc(hidden)]
pub trait SelectOccupancyMode {
    type Mode: OccupancyMode;
}

impl SelectOccupancyMode for SelectOccupancy<false> {
    type Mode = crate::occupancy::Exact;
}

// Estimated occupancy needs float math from std (see occupancy.rs); a static
// configuration that selects it without feature `std` fails to compile here.
#[cfg(feature = "std")]
impl SelectOccupancyMode for SelectOccupancy<true> {
    type Mode = crate::occupancy::Estimated;
}

/// Defines a zero-sized compile-time configuration type implementing
/// [`Params`], plus a matching zero-initialised static storage type.
///
/// ```
/// shm_gen_cache::static_config! {
///     /// Two participants, eight buckets.
///     pub struct Tiny: shm_gen_cache::NoopGetPid {
///         participant_capacity: 2, bucket_count: 8,
///         max_key_size: 1, max_value_size: 1,
///         record_area_size: 32, max_occupancy: 8,
///         reservation_chunk_size: 8,
///     }
/// }
/// static STORAGE: <Tiny as shm_gen_cache::StaticParams>::Storage =
///     shm_gen_cache::CacheStorage::new();
/// ```
///
/// Unlisted fields take [`Config::DEFAULT`]'s values. An invalid
/// configuration is a compile-time error.
#[macro_export]
macro_rules! static_config {
    (
        $(#[$meta:meta])*
        $vis:vis struct $name:ident : $pid:ty { $($field:ident : $value:expr),* $(,)? }
    ) => {
        $(#[$meta])*
        #[derive(Clone, Copy, Debug, Default)]
        $vis struct $name;

        const _: () = {
            const DERIVED: $crate::Derived = match ($crate::Config {
                $($field: $value,)*
                ..$crate::Config::DEFAULT
            })
            .resolve()
            {
                Ok(derived) => derived,
                Err(_) => panic!(concat!("invalid shm_gen_cache configuration ", stringify!($name))),
            };

            // SAFETY: DERIVED comes from Config::resolve and is a constant;
            // `hot` is not overridden.
            unsafe impl $crate::Params for $name {
                type Pid = $pid;
                type Occupancy = <$crate::__private::SelectOccupancy<
                    { DERIVED.estimates_occupancy() },
                > as $crate::__private::SelectOccupancyMode>::Mode;

                #[inline(always)]
                fn derived(&self) -> &$crate::Derived {
                    const D: &$crate::Derived = &DERIVED;
                    D
                }
            }

            impl $crate::StaticParams for $name {
                const DERIVED: $crate::Derived = DERIVED;
                type Storage = $crate::CacheStorage<{ DERIVED.mapping_size() }>;
            }
        };
    };
}

/// Implemented by [`crate::static_config!`] types: compile-time access to the
/// resolved parameters and a storage type of exactly the mapping size.
pub trait StaticParams: Params + Default {
    /// The resolved parameters.
    const DERIVED: Derived;
    /// Zero-initialised, cache-line aligned storage for one cache.
    type Storage;
}
