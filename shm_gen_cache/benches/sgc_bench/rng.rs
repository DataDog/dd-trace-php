//! Randomness, hashing and sampling. All of it runs before timing starts,
//! and all of it is deterministic: the same seeds give the same keys,
//! values and operation streams on every run and platform.

const GOLDEN_GAMMA: u64 = 0x9e37_79b9_7f4a_7c15;

/// The 64-bit finalizer of MurmurHash3: a full-avalanche bijection.
#[inline(always)]
pub const fn fmix64(mut x: u64) -> u64 {
    x ^= x >> 33;
    x = x.wrapping_mul(0xff51_afd7_ed55_8ccd);
    x ^= x >> 33;
    x = x.wrapping_mul(0xc4ce_b9fe_1a85_ec53);
    x ^= x >> 33;
    x
}

/// SplitMix64.
pub struct SplitMix64 {
    state: u64,
}

impl SplitMix64 {
    pub const fn new(seed: u64) -> Self {
        SplitMix64 { state: seed }
    }

    #[inline]
    pub fn next_u64(&mut self) -> u64 {
        self.state = self.state.wrapping_add(GOLDEN_GAMMA);
        let mut z = self.state;
        z = (z ^ (z >> 30)).wrapping_mul(0xbf58_476d_1ce4_e5b9);
        z = (z ^ (z >> 27)).wrapping_mul(0x94d0_49bb_1331_11eb);
        z ^ (z >> 31)
    }

    /// Uniform in `[0, bound)` by Lemire's multiply-shift reduction of the
    /// high 32 bits; the bias is irrelevant at these sizes.
    #[inline]
    pub fn below(&mut self, bound: u32) -> u32 {
        (((self.next_u64() >> 32) * u64::from(bound)) >> 32) as u32
    }

    /// Uniform in `[0, 1)` with 53 random bits.
    #[inline]
    pub fn unit(&mut self) -> f64 {
        (self.next_u64() >> 11) as f64 * (1.0 / (1u64 << 53) as f64)
    }
}

/// Word-at-a-time hash with a full-avalanche bijective mixer per word. Its
/// cost is irrelevant (hashes are precomputed); what matters is that every
/// key bit reaches the low bits the cache uses to pick a bucket. Words are
/// read in native byte order, the trailing partial word zero-padded.
pub fn hash_bytes(bytes: &[u8]) -> u64 {
    let len = bytes.len() as u64;
    let mut h = 0x243f_6a88_85a3_08d3 ^ len.wrapping_mul(GOLDEN_GAMMA);
    let mut words = bytes.chunks_exact(8);
    for w in &mut words {
        let w = u64::from_ne_bytes(w.try_into().expect("8-byte chunk"));
        h = fmix64(h ^ w).wrapping_add(GOLDEN_GAMMA);
    }
    let tail = words.remainder();
    if !tail.is_empty() {
        let mut w = [0u8; 8];
        w[..tail.len()].copy_from_slice(tail);
        h = fmix64(h ^ u64::from_ne_bytes(w)).wrapping_add(GOLDEN_GAMMA);
    }
    fmix64(h ^ len)
}

/// Fisher-Yates, from the back, drawing with [`SplitMix64::below`].
pub fn shuffle(v: &mut [u32], seed: u64) {
    let mut rng = SplitMix64::new(seed);
    for i in (2..=v.len()).rev() {
        let j = rng.below(i as u32) as usize;
        v.swap(i - 1, j);
    }
}

/// `first, first + 1, ..., first + count - 1`.
pub fn iota_from(first: u32, count: u32) -> Vec<u32> {
    (first..first + count).collect()
}

/// Zipf(s) over ranks 1..n, sampled in O(1) with Walker/Vose alias tables.
/// `rank_to_key` is shuffled by the caller, so popularity is uncorrelated
/// with key content, hash and dataset position.
pub struct ZipfSampler {
    keys: Vec<u32>,
    threshold: Vec<u32>,
    alias: Vec<u32>,
}

impl ZipfSampler {
    pub fn new(rank_to_key: Vec<u32>, s: f64) -> Self {
        let n = rank_to_key.len();
        let mut threshold = vec![0u32; n];
        let mut alias = vec![0u32; n];
        let mut p: Vec<f64> = (0..n).map(|i| 1.0 / ((i + 1) as f64).powf(s)).collect();
        // Summed in rank order, so the rounding is fixed.
        let mut sum = 0.0;
        for &pi in &p {
            sum += pi;
        }
        let mut small: Vec<u32> = Vec::new();
        let mut large: Vec<u32> = Vec::new();
        for (i, pi) in p.iter_mut().enumerate() {
            *pi = *pi * n as f64 / sum;
            if *pi < 1.0 {
                small.push(i as u32);
            } else {
                large.push(i as u32);
            }
        }
        while let (Some(&lo), Some(&hi)) = (small.last(), large.last()) {
            small.pop();
            let (lo_us, hi_us) = (lo as usize, hi as usize);
            threshold[lo_us] = to_threshold(p[lo_us]);
            alias[lo_us] = hi;
            p[hi_us] -= 1.0 - p[lo_us];
            if p[hi_us] < 1.0 {
                large.pop();
                small.push(hi);
            }
        }
        // Columns left in `large`, then rounding leftovers in `small`, are
        // always taken.
        for &i in large.iter().chain(small.iter()) {
            threshold[i as usize] = u32::MAX;
            alias[i as usize] = i;
        }
        ZipfSampler {
            keys: rank_to_key,
            threshold,
            alias,
        }
    }

    /// The key drawn by the random word `r`: its high half picks a column,
    /// its low half is the coin.
    #[inline]
    pub fn sample(&self, r: u64) -> u32 {
        let n = self.keys.len() as u64;
        let column = (((r >> 32) * n) >> 32) as usize;
        let coin = r as u32;
        let i = if coin < self.threshold[column] {
            column
        } else {
            self.alias[column] as usize
        };
        self.keys[i]
    }

    /// The key at popularity rank `rank` (0 = most popular).
    pub fn key_of_rank(&self, rank: usize) -> u32 {
        self.keys[rank]
    }
}

fn to_threshold(p: f64) -> u32 {
    let scaled = p * 4_294_967_296.0;
    if scaled >= 4_294_967_295.0 {
        u32::MAX
    } else {
        scaled as u32
    }
}
