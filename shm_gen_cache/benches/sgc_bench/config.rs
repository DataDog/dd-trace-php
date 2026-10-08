//! The cache configuration and the scenario matrix.

use shm_gen_cache::Config;

/// 64Ki buckets: each arena's index is 512 KiB and its used record area is
/// several MiB, so the working set lives in L2/SLC rather than L1, as a
/// production cache of this kind would. Keys up to 64 bytes and values up
/// to 512 bytes cover identifier-like keys and small serialized objects.
/// The record area keeps the library's default sizing (every bucket can
/// hold a maximal record), so rotation is driven by the occupancy target,
/// never by running out of record space.
pub const BUCKET_COUNT: u32 = 1 << 16;
pub const MAX_KEY_SIZE: u32 = 64;
pub const MAX_VALUE_SIZE: u32 = 512;
pub const MIN_KEY_SIZE: u32 = 16;
pub const MIN_VALUE_SIZE: u32 = 8;
pub const MAX_OCCUPANCY: u32 = BUCKET_COUNT * 7 / 10;
pub const PARTICIPANT_CAPACITY: u32 = 128;
pub const RECORD_AREA_SIZE: u32 = BUCKET_COUNT * (8 + 8 + MAX_KEY_SIZE + MAX_VALUE_SIZE);

/// Handed to the library at run time ([`Config::resolve`] at startup), as
/// a production caller would. A zero `reservation_chunk_size` selects the
/// default chunk (five maximal records); `always_exact_occupancy: false`
/// lets the configuration select the occupancy estimator.
pub const BENCH_CONFIG: Config = Config {
    participant_capacity: PARTICIPANT_CAPACITY,
    bucket_count: BUCKET_COUNT,
    max_key_size: MAX_KEY_SIZE,
    max_value_size: MAX_VALUE_SIZE,
    record_area_size: RECORD_AREA_SIZE,
    max_occupancy: MAX_OCCUPANCY,
    reservation_chunk_size: 0,
    always_exact_occupancy: false,
};

const _: () = assert!(MAX_OCCUPANCY == (BUCKET_COUNT as f64 * 0.7) as u32);

/// The lookup output capacity: the largest value.
pub const VALUE_BUFFER_CAPACITY: usize = MAX_VALUE_SIZE as usize;
pub const VALUE_BUFFER_WORDS: usize = VALUE_BUFFER_CAPACITY.div_ceil(8);
pub type ValueBuffer = shm_gen_cache::OutputBuffer<VALUE_BUFFER_WORDS>;

pub fn value_buffer() -> ValueBuffer {
    ValueBuffer::new(VALUE_BUFFER_CAPACITY)
}

/// The scenario matrix. Primary key sets are relative to capacity: one
/// generation admits `MAX_OCCUPANCY` (~45.9Ki) keys and the two generations
/// together hold between ~46Ki and ~92Ki distinct keys. 16Ki fits outright,
/// 64Ki sits at the effective capacity and 1Mi is ~11x it.
pub const UNIVERSE_SIZE: u32 = 1 << 20;
pub const MIXED_KEY_COUNTS: [u32; 3] = [1 << 14, 1 << 16, UNIVERSE_SIZE];
pub const SKEWS: [f64; 2] = [0.8, 1.1];
/// The `lookup_hit` set fits one generation; `lookup_miss` keys are never
/// inserted. The skew of misses matters only for the caller's own data.
pub const LOOKUP_HIT_KEYS: u32 = 1 << 15;
pub const LOOKUP_MISS_KEYS: u32 = 1 << 16;
pub const MISS_SKEW: f64 = 0.8;
