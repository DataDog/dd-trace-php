// Copyright 2025-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0 OR BSD-3-Clause

use std::hash::{Hash, Hasher};

/// Very simple bitset which doesn't allocate, and doesn't change after it has
/// been created.
#[derive(Clone, Copy, Debug, Eq, PartialEq)]
#[repr(transparent)]
pub struct BitSet {
    bits: u32,
}

impl Hash for BitSet {
    fn hash<H: Hasher>(&self, state: &mut H) {
        let bits = self.bits;
        bits.count_ones().hash(state);
        bits.hash(state);
    }
}

impl BitSet {
    pub const MAX: usize = u32::BITS as usize;

    /// Creates a new bitset from the provided number.
    pub const fn new(bits: u32) -> Self {
        Self { bits }
    }

    #[inline]
    pub fn len(&self) -> usize {
        self.bits.count_ones() as usize
    }

    #[inline]
    pub fn is_empty(&self) -> bool {
        self.bits == 0
    }

    #[inline]
    pub fn contains(&self, bit: usize) -> bool {
        if bit < 32 {
            let mask = 1u32 << bit;
            let masked = self.bits & mask;
            masked != 0
        } else {
            false
        }
    }

    pub fn iter(&self) -> BitSetIter {
        BitSetIter::new(self)
    }
}

impl FromIterator<usize> for BitSet {
    /// Creates a new bitset from the iterator.
    ///
    /// # Panics
    ///
    /// Panics if an item is out of the range of the bitset e.g. [`u32::MAX`].
    fn from_iter<I: IntoIterator<Item = usize>>(iter: I) -> BitSet {
        let mut bits = 0;
        let mut insert = |bit| {
            // todo: add non-panic API
            assert!(bit < BitSet::MAX);
            bits |= 1u32 << bit;
        };
        for bit in iter {
            insert(bit);
        }
        BitSet { bits }
    }
}

pub struct BitSetIter {
    bitset: u32,
    offset: u32,
    end: u32,
}

impl BitSetIter {
    pub fn new(bitset: &BitSet) -> BitSetIter {
        let bitset = bitset.bits;
        let offset = 0;
        let end = {
            let num_bits = u32::BITS;
            let leading_zeros = bitset.leading_zeros();
            num_bits - leading_zeros
        };
        BitSetIter {
            bitset,
            offset,
            end,
        }
    }
}

impl Iterator for BitSetIter {
    type Item = usize;

    fn next(&mut self) -> Option<Self::Item> {
        while self.offset != self.end {
            let offset = self.offset;
            self.offset += 1;
            let mask = 1 << offset;
            let masked = self.bitset & mask;
            if masked != 0 {
                return Some(offset as usize);
            }
        }
        None
    }
}

impl ExactSizeIterator for BitSetIter {
    fn len(&self) -> usize {
        if self.offset < self.end {
            let shifted = self.bitset >> self.offset;
            shifted.count_ones() as usize
        } else {
            0
        }
    }
}

impl IntoIterator for BitSet {
    type Item = usize;
    type IntoIter = BitSetIter;
    fn into_iter(self) -> Self::IntoIter {
        BitSetIter::new(&self)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use proptest::prelude::*;
    use proptest::test_runner::{RngAlgorithm, TestRng};
    use std::collections::HashSet;

    #[test]
    fn bitset_full() {
        let bitset = BitSet::new(u32::MAX);
        assert_eq!(bitset.len(), BitSet::MAX);
        assert!(!bitset.is_empty());

        for i in 0..BitSet::MAX {
            assert!(bitset.contains(i));
        }

        for (offset, bit) in bitset.iter().enumerate() {
            assert_eq!(bit, offset);
        }

        let mut iter = bitset.iter();
        let mut len = BitSet::MAX;
        assert_eq!(len, iter.len());

        while let Some(_) = iter.next() {
            len -= 1;
            assert_eq!(len, iter.len());
        }
    }

    #[test]
    fn bitset_empty() {
        let bitset = BitSet::new(0);
        assert_eq!(0, bitset.len());
        assert!(bitset.is_empty());
        for i in 0..BitSet::MAX {
            assert!(!bitset.contains(i));
        }

        let mut iter = bitset.iter();
        let len = 0;
        assert_eq!(len, iter.len());
        assert_eq!(None, iter.next());
    }

    // There's nothing special about 27, just testing a single possible number.
    #[test]
    fn bitset_27() {
        let bitset = BitSet::new(1 << 27);
        assert_eq!(1, bitset.len());
        assert!(!bitset.is_empty());
        assert!(bitset.contains(27));

        let mut iter = bitset.iter();
        let len = 1;
        assert_eq!(len, iter.len());
        assert_eq!(Some(27), iter.next());
    }

    static IOTA: [usize; BitSet::MAX] = [
        0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24,
        25, 26, 27, 28, 29, 30, 31,
    ];

    proptest! {
        #[test]
        fn bitset_acts_like_a_special_hashset(
            oracle in proptest::sample::subsequence(&IOTA, 1..IOTA.len())
                .prop_map(HashSet::<usize>::from_iter),
        ) {
            let bitset1 = BitSet::from_iter(oracle.iter().cloned());
            prop_assert_eq!(bitset1.len(), oracle.len());

            // Items in the oracle exist in the bitset.
            for item in oracle.iter() {
                prop_assert!(bitset1.contains(*item));
            }

            // Test the other way around to check the iterator implementation.
            let mut i = 0;
            for item in bitset1.iter() {
                prop_assert!(oracle.contains(&item));
                i += 1;
            }
            // Make sure the iterator ran as many times as we expected.
            prop_assert_eq!(i, oracle.len(),
                "BitSet's iterator didn't have the expected number of iterations"
            );

            // Like regular sets, insertion order doesn't matter in bitsets.
            let mut shuffled = oracle.iter().copied().collect::<Vec<_>>();
            let mut rng = TestRng::deterministic_rng(RngAlgorithm::ChaCha);
            use rand::seq::SliceRandom;
            shuffled.shuffle(&mut rng);
            let bitset2 = BitSet::from_iter(shuffled.iter().cloned());

            prop_assert_eq!(
                bitset1, bitset2,
                "Insertion order unexpectedly mattered, diff in binary: {:b} vs {:b}",
                bitset1.bits, bitset2.bits
            );
        }
    }
}
