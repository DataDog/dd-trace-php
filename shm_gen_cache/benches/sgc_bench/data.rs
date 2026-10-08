//! The key universe. Every key has exactly one value, fixed here; no
//! scenario ever inserts a different value for a key that may be present.
//!
//! Key and value bytes are a fixed function of the key's index
//! ([`fill_key`], [`fill_words`] with [`value_seed`]), and only their
//! lengths and the key's hash are stored. An operation generates its key
//! just before the lookup and its value just before an insert, into the
//! calling thread's buffers: a real caller holds the key it looks up and
//! has just computed the value it inserts, so neither comes from main
//! memory. Storing every key and value up front would make the harness's
//! own DRAM misses dominate insert-heavy runs.

use crate::sgc_bench::config::{
    MAX_KEY_SIZE, MAX_VALUE_SIZE, MIN_KEY_SIZE, MIN_VALUE_SIZE, UNIVERSE_SIZE,
};
use crate::sgc_bench::rng::{SplitMix64, fmix64, hash_bytes};

const KEY_BUFFER_SIZE: usize = MAX_KEY_SIZE as usize;
const VALUE_BUFFER_SIZE: usize = MAX_VALUE_SIZE as usize;

/// One key's stored description.
#[derive(Clone, Copy, Default)]
pub struct KeyRecord {
    pub hash: u64,
    pub value_len: u16,
    pub key_len: u8,
}

/// A thread's key buffer: always written whole.
#[repr(C, align(8))]
pub struct KeyBuffer([u8; KEY_BUFFER_SIZE]);

/// A thread's value buffer: written in whole words.
#[repr(C, align(8))]
pub struct ValueSource([u8; VALUE_BUFFER_SIZE]);

/// The buffers an operation generates its key and value into.
pub struct SourceBuffers {
    pub key: KeyBuffer,
    pub value: ValueSource,
}

impl SourceBuffers {
    pub fn new() -> Self {
        SourceBuffers {
            key: KeyBuffer([0; KEY_BUFFER_SIZE]),
            value: ValueSource([0; VALUE_BUFFER_SIZE]),
        }
    }
}

pub struct Dataset {
    pub records: Vec<KeyRecord>,
}

impl Dataset {
    /// Key `i`'s bytes, generated into `b`.
    #[inline(always)]
    pub fn key<'b>(&self, i: u32, b: &'b mut KeyBuffer) -> &'b [u8] {
        let len = self.records[i as usize].key_len as usize;
        fill_key(i, &mut b.0);
        &b.0[..len]
    }

    /// Key `i`'s value, generated into `b`.
    #[inline(always)]
    pub fn value<'b>(&self, i: u32, b: &'b mut ValueSource) -> &'b [u8] {
        let len = self.records[i as usize].value_len as usize;
        fill_words(value_seed(i), &mut b.0, len);
        &b.0[..len]
    }

    /// The universe's lengths and hashes: keys uniform in [16, 64] bytes;
    /// values log-uniform in [8, 512] bytes (most values small, a long tail
    /// of larger ones; mean ~120 bytes).
    pub fn build() -> Self {
        let mut rng = SplitMix64::new(0x5eed_5eed);
        let value_log_range = (f64::from(MAX_VALUE_SIZE) / f64::from(MIN_VALUE_SIZE)).ln();
        let records = (0..UNIVERSE_SIZE)
            .map(|_| {
                let key_len = (MIN_KEY_SIZE + rng.below(MAX_KEY_SIZE - MIN_KEY_SIZE + 1)) as u8;
                let v = f64::from(MIN_VALUE_SIZE) * (rng.unit() * value_log_range).exp();
                let value_len = MAX_VALUE_SIZE.min(v as u32) as u16;
                KeyRecord {
                    hash: 0,
                    value_len,
                    key_len,
                }
            })
            .collect();
        let mut d = Dataset { records };
        let mut buf = KeyBuffer([0; KEY_BUFFER_SIZE]);
        for i in 0..UNIVERSE_SIZE {
            let hash = hash_bytes(d.key(i, &mut buf));
            d.records[i as usize].hash = hash;
        }
        d
    }
}

const WORD_STEP: u64 = 0x9e37_79b9_7f4a_7c15;

/// Fills the first `n` bytes of `out`, rounded up to whole words, with
/// `seed, seed + k, seed + 2k, ...` in native byte order: the last word may
/// run up to 7 bytes past `n`. Only the keys' identity matters to the cache
/// (their hashes are stored), so the bytes need no randomness, and
/// generating them must stay much cheaper than a lookup: an add and a store
/// per 8 bytes, with no variable-length copy.
#[inline(always)]
fn fill_words(seed: u64, out: &mut [u8], n: usize) {
    let mut w = seed;
    for word in out.chunks_exact_mut(8).take(n.div_ceil(8)) {
        word.copy_from_slice(&w.to_ne_bytes());
        w = w.wrapping_add(WORD_STEP);
    }
}

#[inline(always)]
fn key_seed(i: u32) -> u64 {
    fmix64(u64::from(i) ^ 0x3c6e_f372_fe94_f82b)
}

#[inline(always)]
fn value_seed(i: u32) -> u64 {
    fmix64(u64::from(i) ^ 0xa54f_f53a_5f1d_36f1)
}

/// Key `i`, written as a whole `MAX_KEY_SIZE` buffer: a fixed number of
/// words avoids the mispredicted exit a loop over the key's own length
/// (16-64 bytes) takes on most keys, which costs lookups more than the
/// stores. Only the key's first `key_len` bytes are used. Its first 8 bytes
/// are a bijection of `i`, so keys are distinct.
#[inline(always)]
fn fill_key(i: u32, out: &mut [u8; KEY_BUFFER_SIZE]) {
    let id = fmix64(u64::from(i).wrapping_add(0x6a09_e667_f3bc_c909));
    out[..8].copy_from_slice(&id.to_ne_bytes());
    fill_words(key_seed(i), &mut out[8..], KEY_BUFFER_SIZE - 8);
}
