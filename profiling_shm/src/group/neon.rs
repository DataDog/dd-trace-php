use core::arch::aarch64::*;

pub const WIDTH: usize = 16;

#[derive(Copy, Clone)]
pub struct BitMask(pub u16);

impl BitMask {
    #[inline]
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
        self.0 .0 &= self.0 .0 - 1;
        Some(bit)
    }
}

#[derive(Copy, Clone)]
pub struct Group(uint8x16_t);

impl Group {
    /// # Safety
    /// `ptr` must be valid for 16 bytes of unaligned reads.
    #[inline]
    pub unsafe fn load(ptr: *const u8) -> Self {
        Group(unsafe { vld1q_u8(ptr) })
    }

    /// Returns a bitmask with bit `i` set if `ctrl[i] == byte`.
    #[inline]
    pub fn match_byte(self, byte: u8) -> BitMask {
        unsafe {
            let cmp = vceqq_u8(self.0, vdupq_n_u8(byte)); // 0xFF or 0x00 per lane
            let bits = vshrq_n_u8(cmp, 7); // 0x01 or 0x00 per lane
            let lo = vget_low_u8(bits); // lanes 0–7
            let hi = vget_high_u8(bits); // lanes 8–15
                                         // Weight lane i by 2^i so that vaddv_u8 produces the bit-packed byte.
                                         // In little-endian memory: 0x8040201008040201u64 →
                                         //   byte 0 = 0x01, byte 1 = 0x02, …, byte 7 = 0x80.
            let weights = vcreate_u8(0x8040_2010_0804_0201_u64);
            let lo_bits = vaddv_u8(vmul_u8(lo, weights)) as u16;
            let hi_bits = vaddv_u8(vmul_u8(hi, weights)) as u16;
            BitMask(lo_bits | (hi_bits << 8))
        }
    }

    #[inline]
    pub fn match_empty(self) -> BitMask {
        self.match_byte(0x00)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    unsafe fn make_group(bytes: [u8; 16]) -> Group {
        unsafe { Group::load(bytes.as_ptr()) }
    }

    #[test]
    fn match_empty_all_zero() {
        let g = unsafe { make_group([0x00; 16]) };
        assert_eq!(g.match_empty().0, 0xFFFF);
    }

    #[test]
    fn match_empty_none() {
        let g = unsafe { make_group([0x80; 16]) };
        assert_eq!(g.match_empty().0, 0x0000);
    }

    #[test]
    fn match_byte_fingerprint() {
        let fp = 0xB7u8;
        let mut bytes = [0x00u8; 16];
        bytes[0] = fp;
        bytes[9] = fp;
        bytes[15] = fp;
        let g = unsafe { make_group(bytes) };
        let mask = g.match_byte(fp);
        assert_eq!(mask.0, (1u16 << 0) | (1u16 << 9) | (1u16 << 15));
    }
}
