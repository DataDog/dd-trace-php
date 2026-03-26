/// Fallback implementation using a u64 word, covering 8 control bytes at a time.
pub const WIDTH: usize = 8;

#[derive(Copy, Clone)]
pub struct BitMask(pub u8);

impl BitMask {
    pub fn lowest_set_bit(self) -> Option<usize> {
        if self.0 == 0 {
            None
        } else {
            Some(self.0.trailing_zeros() as usize)
        }
    }

    pub fn iter(self) -> BitMaskIter {
        BitMaskIter(self)
    }
}

pub struct BitMaskIter(BitMask);

impl Iterator for BitMaskIter {
    type Item = usize;

    #[inline]
    fn next(&mut self) -> Option<usize> {
        let bit = self.0.lowest_set_bit()?;
        self.0 .0 &= self.0 .0 - 1; // clear lowest set bit
        Some(bit)
    }
}

#[derive(Copy, Clone)]
pub struct Group(u64);

impl Group {
    /// # Safety
    /// `ptr` must be valid for 8 bytes of reads.
    #[inline]
    pub unsafe fn load(ptr: *const u8) -> Self {
        Group(unsafe { (ptr as *const u64).read_unaligned() })
    }

    /// Returns a bitmask with bit `i` set if `ctrl[i] == byte`.
    #[inline]
    pub fn match_byte(self, byte: u8) -> BitMask {
        // XOR so that matching bytes become 0x00.
        let x = self.0 ^ u64::from_ne_bytes([byte; 8]);
        // Detect zero bytes (no carry-borrow false positives because our ctrl
        // bytes are always 0x00 or 0x80..=0xFF, so after XOR we never get 0x01).
        let has_zero = x.wrapping_sub(0x0101_0101_0101_0101) & !x & 0x8080_8080_8080_8080;
        // Pack the 8 MSBs (one per byte) into a u8.
        let packed = has_zero.wrapping_mul(0x0002_0408_1020_4081_u64) >> 56;
        BitMask(packed as u8)
    }

    /// Bitmask of empty slots (ctrl == 0x00).
    #[inline]
    pub fn match_empty(self) -> BitMask {
        self.match_byte(0x00)
    }
}

#[cfg(test)]
mod tests {
    extern crate std;
    use super::*;

    fn make_group(bytes: [u8; 8]) -> Group {
        Group(u64::from_ne_bytes(bytes))
    }

    #[test]
    fn match_empty_all_zero() {
        let g = make_group([0x00; 8]);
        assert_eq!(g.match_empty().0, 0xFF);
    }

    #[test]
    fn match_empty_none() {
        let g = make_group([0x80; 8]);
        assert_eq!(g.match_empty().0, 0x00);
    }

    #[test]
    fn match_byte_fingerprint() {
        let fp = 0x91u8;
        let mut bytes = [0x00u8; 8];
        bytes[2] = fp;
        bytes[5] = fp;
        let g = make_group(bytes);
        let mask = g.match_byte(fp);
        assert_eq!(mask.0, (1 << 2) | (1 << 5));
    }

    #[test]
    fn lowest_set_bit_none() {
        assert_eq!(BitMask(0).lowest_set_bit(), None);
    }

    #[test]
    fn lowest_set_bit_some() {
        assert_eq!(BitMask(0b0001_0100).lowest_set_bit(), Some(2));
    }

    #[test]
    fn iter_collects_all_bits() {
        let bits: std::vec::Vec<usize> = BitMask(0b1010_0101).iter().collect();
        assert_eq!(bits, std::vec![0, 2, 5, 7]);
    }
}
