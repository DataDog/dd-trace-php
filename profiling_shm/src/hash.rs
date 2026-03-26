use core::hash::Hasher;

// Same algorithm as rustc_hash::hash_bytes (FxHasher v2.1).
//
// We cannot use rustc_hash directly: its hash_bytes uses try_into().unwrap() on
// slices whose lengths LLVM cannot statically prove equal N, leaving
// core::panicking symbols in the binary even with fat LTO + build-std=core.
//
// split_first_chunk / split_last_chunk (stable since 1.77) return &[T; N]
// directly, so u64::from_le_bytes takes the array by value with no bounds
// check at all.  LLVM sees the array length as a compile-time constant and
// eliminates all None branches.
//
// Little-endian byte order matches rustc_hash exactly, which lets us use
// rustc_hash as a reference oracle in correctness tests.
pub fn hash_bytes(s: &[u8]) -> u64 {
    const SEED1: u64 = 0x243f6a8885a308d3;
    const SEED2: u64 = 0x13198a2e03707344;
    const PREVENT_TRIVIAL_ZERO_COLLAPSE: u64 = 0xa4093822299f31d0;

    #[inline(always)]
    fn multiply_mix(x: u64, y: u64) -> u64 {
        let full = (x as u128) * (y as u128);
        (full as u64) ^ ((full >> 64) as u64)
    }

    // Split a &[u8; 16] into its two u64 halves.
    // split_first_chunk::<8>() on a 16-byte slice always returns Some;
    // LLVM constant-folds the length check and removes the None branch.
    #[inline(always)]
    fn pair_from(chunk: &[u8; 16]) -> (u64, u64) {
        // Deref &[u8; 16] → &[u8] so slice methods are available.
        let (lo, rest) = chunk
            .as_slice()
            .split_first_chunk::<8>()
            .unwrap_or_else(|| unreachable!());
        let (hi, _) = rest
            .split_first_chunk::<8>()
            .unwrap_or_else(|| unreachable!());
        (u64::from_le_bytes(*lo), u64::from_le_bytes(*hi))
    }

    let len = s.len();
    let mut s0 = SEED1;
    let mut s1 = SEED2;

    if len <= 16 {
        if len >= 8 {
            // Both calls always return Some in this branch (len >= 8).
            if let Some((head, _)) = s.split_first_chunk::<8>() {
                s0 ^= u64::from_le_bytes(*head);
            }
            if let Some((_, tail)) = s.split_last_chunk::<8>() {
                s1 ^= u64::from_le_bytes(*tail);
            }
        } else if len >= 4 {
            if let Some((head, _)) = s.split_first_chunk::<4>() {
                s0 ^= u32::from_le_bytes(*head) as u64;
            }
            if let Some((_, tail)) = s.split_last_chunk::<4>() {
                s1 ^= u32::from_le_bytes(*tail) as u64;
            }
        } else if len > 0 {
            s0 ^= s[0] as u64;
            s1 ^= ((s[len - 1] as u64) << 8) | s[len / 2] as u64;
        }
    } else {
        // Replicate rustc_hash's `while off < len - 16` loop without computing
        // (len-1)/16*16 as a slice bound (LLVM can't prove that's <= len through
        // the masking arithmetic).  Instead, loop while data.len() > 16: LLVM
        // trivially proves data.len() > 16 implies data.len() >= 16, so
        // split_first_chunk::<16>() is always Some and has no panic path.
        // The loop stops with exactly 1-16 bytes remaining, which the suffix
        // read below covers — intentionally overlapping the last chunk when
        // (len - 16) % 16 != 0, matching rustc_hash's "can partially overlap".
        let mut data = s;
        while data.len() > 16 {
            if let Some((chunk, rest)) = data.split_first_chunk::<16>() {
                let (x, y) = pair_from(chunk);
                let t = multiply_mix(s0 ^ x, PREVENT_TRIVIAL_ZERO_COLLAPSE ^ y);
                s0 = s1;
                s1 = t;
                data = rest;
            }
        }
        // Tail: last 16 bytes (may overlap with the chunks above).
        // Always Some because len > 16.
        if let Some((_, suffix)) = s.split_last_chunk::<16>() {
            let (x, y) = pair_from(suffix);
            s0 ^= x;
            s1 ^= y;
        }
    }

    multiply_mix(s0, s1) ^ (len as u64)
}

pub fn hash_function(name: crate::StringIndex, file: crate::StringIndex) -> u64 {
    let mut h = rustc_hash::FxHasher::default();
    h.write_u64((name.0 as u64) | ((file.0 as u64) << 32));
    h.finish()
}

/// Slot index within the table (low bits of hash). `cap` must be a power of two.
#[inline]
pub fn h1(hash: u64, cap: usize) -> usize {
    hash as usize & (cap - 1)
}

/// 7-bit fingerprint with MSB forced to 1: FULL ∈ 0x80..=0xFF (128 values).
/// MSB=1 distinguishes FULL from EMPTY (0x00, MSB=0) without any branch.
#[inline]
pub fn h2(hash: u64) -> u8 {
    ((hash >> 57) as u8) | 0x80
}

#[cfg(test)]
mod tests {
    extern crate std;
    use super::*;
    use core::hash::Hasher as _;

    // Oracle: recover the raw hash_bytes value that rustc_hash computes
    // internally.  FxHasher::write(s) does:
    //   self.hash = (0 + hash_bytes_internal(s)).wrapping_mul(K)
    // so finish() == hash_bytes_internal(s).wrapping_mul(K).
    // We compare our hash_bytes(s) * K against that to avoid needing the
    // modular inverse of K.
    const FX_K: u64 = 0xf1357aea2e62a9c5; // K from rustc_hash (64-bit)

    fn oracle(s: &[u8]) -> u64 {
        // Returns hash_bytes_internal(s) * K.
        let mut h = rustc_hash::FxHasher::default();
        h.write(s);
        h.finish()
    }

    fn our_as_fx(s: &[u8]) -> u64 {
        // Replicate FxHasher::write(s).finish():
        //   add_to_hash(hash_bytes(s))  →  0.wrapping_add(v).wrapping_mul(K)
        //   finish()                    →  self.hash.rotate_left(26)
        hash_bytes(s).wrapping_mul(FX_K).rotate_left(26)
    }

    // ── hash_bytes correctness ────────────────────────────────────────────────

    #[test]
    fn hash_bytes_empty() {
        assert_eq!(our_as_fx(&[]), oracle(&[]));
    }

    #[test]
    fn hash_bytes_len_1_to_3() {
        // lo/mid/hi branch
        for len in 1usize..=3 {
            let s: std::vec::Vec<u8> = (0..len as u8).collect();
            assert_eq!(our_as_fx(&s), oracle(&s), "len={len}");
        }
    }

    #[test]
    fn hash_bytes_len_4_to_7() {
        // 4-byte branch
        for len in 4usize..=7 {
            let s: std::vec::Vec<u8> = (0..len as u8).collect();
            assert_eq!(our_as_fx(&s), oracle(&s), "len={len}");
        }
    }

    #[test]
    fn hash_bytes_len_8_to_16() {
        // 8-byte branch (head + overlapping tail when len < 16)
        for len in 8usize..=16 {
            let s: std::vec::Vec<u8> = (0..len as u8).collect();
            assert_eq!(our_as_fx(&s), oracle(&s), "len={len}");
        }
    }

    #[test]
    fn hash_bytes_len_17_to_48() {
        // bulk loop + suffix; also exercises the overlap question:
        // for len=17 the loop processes bytes[0..16] and the suffix is
        // bytes[1..17] — they overlap by 15 bytes, matching rustc_hash's
        // "can partially overlap with suffix" design.
        for len in 17usize..=48 {
            let s: std::vec::Vec<u8> = (0..len as u8).collect();
            assert_eq!(our_as_fx(&s), oracle(&s), "len={len}");
        }
    }

    #[test]
    fn hash_bytes_bulk_suffix_overlap() {
        // Explicitly verify the overlapping-suffix behaviour matches rustc_hash.
        // len=17: loop processes bytes[0..16], suffix is bytes[1..17].
        // len=32: loop processes bytes[0..16], suffix is bytes[16..32] (no overlap).
        // len=33: loop processes bytes[0..32], suffix is bytes[17..33].
        for len in [17usize, 32, 33, 47, 48, 49] {
            let s: std::vec::Vec<u8> = (0..len as u8).collect();
            assert_eq!(our_as_fx(&s), oracle(&s), "len={len}");
        }
    }

    #[test]
    fn hash_bytes_well_known_strings() {
        // The 8 pre-interned strings used by ShmRegion::create().
        for s in [
            "",
            "end_timestamp_ns",
            "local_root_span_id",
            "trace_endpoint",
            "span_id",
            "thread_id",
            "thread_name",
            "[oom]",
        ] {
            assert_eq!(
                our_as_fx(s.as_bytes()),
                oracle(s.as_bytes()),
                "string={s:?}"
            );
        }
    }

    #[cfg(test)]
    mod prop_tests {
        extern crate std;
        use super::*;

        #[test]
        fn prop_hash_bytes_matches_rustc_hash() {
            bolero::check!()
                .with_type::<std::vec::Vec<u8>>()
                .for_each(|s| {
                    assert_eq!(our_as_fx(s), oracle(s));
                });
        }
    }

    // ── h2 ───────────────────────────────────────────────────────────────────

    #[test]
    fn h2_always_has_msb_set() {
        for i in 0u64..=255 {
            let fp = h2(i << 57);
            assert!(fp >= 0x80, "h2 produced {fp:#04x} for shift {i}");
        }
        assert!(h2(u64::MAX) >= 0x80);
        assert!(h2(0) >= 0x80);
    }

    #[test]
    fn h2_never_equals_empty() {
        for i in 0u64..=255 {
            assert_ne!(h2(i << 57), 0x00);
        }
        assert_ne!(h2(u64::MAX), 0x00);
        assert_ne!(h2(0), 0x00);
    }
}
