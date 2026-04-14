use core::arch::x86_64 as x86;

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
pub struct Group(x86::__m128i);

impl Group {
    /// # Safety
    /// `ptr` must be valid for 16 bytes of unaligned reads.
    #[inline]
    pub unsafe fn load(ptr: *const u8) -> Self {
        Group(unsafe { x86::_mm_loadu_si128(ptr as *const x86::__m128i) })
    }

    #[inline]
    pub fn match_byte(self, byte: u8) -> BitMask {
        unsafe {
            let cmp = x86::_mm_cmpeq_epi8(self.0, x86::_mm_set1_epi8(byte as i8));
            BitMask(x86::_mm_movemask_epi8(cmp) as u16)
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
        // SSE2 is guaranteed on x86_64 (baseline feature); no runtime check needed.
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
        let fp = 0xA3u8;
        let mut bytes = [0x00u8; 16];
        bytes[3] = fp;
        bytes[11] = fp;
        let g = unsafe { make_group(bytes) };
        let mask = g.match_byte(fp);
        assert_eq!(mask.0, (1u16 << 3) | (1u16 << 11));
    }
}
