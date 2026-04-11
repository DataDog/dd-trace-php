use crate::atomic::{AtomicU32, Ordering};

use crate::group::{Group, WIDTH};
use crate::hash::{h1, h2, hash_bytes};
use crate::{
    InternError, StrRope5, StringIndex, ARENA_CAPACITY, ARENA_USED_OFF, HEADER_OFF, MAX_STRINGS,
    MAX_STR_LEN, SEGMENT_SIZE, STRING_COUNT_OFF, STR_CTRL_OFF, STR_DATA_OFF, STR_HT_CAP,
    STR_IDX_OFF,
};

#[repr(C)]
pub(crate) struct StringHtSlot {
    pub packed: u64,
}

impl StringHtSlot {
    #[inline]
    pub fn pack(string_index: u32, arena_offset: usize, len: usize) -> u64 {
        // arena_offset occupies 29 bits (bits 21–49), fitting values 0..=2^29-1.
        // The arena grows backward from SEGMENT_SIZE (= 2^29), so the largest
        // offset ever stored is SEGMENT_SIZE - 2 = 2^29 - 2, which just fits
        // (the -2 is 2 bytes for the length prefix).
        ((len as u64) << 50) | ((arena_offset as u64) << 21) | (string_index as u64)
    }

    #[inline]
    pub fn unpack(packed: u64) -> (u32, usize, usize) {
        let len = (packed >> 50) as usize;
        let arena_offset = ((packed >> 21) & 0x1FFF_FFFF) as usize;
        let string_index = (packed & 0x1F_FFFF) as u32;
        (string_index, arena_offset, len)
    }
}

// Compile-time size checks (no panic; compile error if wrong)
const _STR_SLOT_SIZE: [(); 8] = [(); core::mem::size_of::<StringHtSlot>()];

/// Intern a string.  Must be called while holding `str_spinlock`.
///
/// # Safety
/// `seg` must be a valid pointer to the start of a `SEGMENT_SIZE`-byte shared
/// memory region initialized by `ShmRegion::create()`.
pub(crate) unsafe fn intern_str(seg: *mut u8, s: &str) -> Result<StringIndex, InternError> {
    let bytes = s.as_bytes();

    if bytes.len() > MAX_STR_LEN {
        return Err(InternError::StrTooLong);
    }

    let string_count = &*(seg.add(HEADER_OFF + STRING_COUNT_OFF) as *const AtomicU32);
    let arena_used = &*(seg.add(HEADER_OFF + ARENA_USED_OFF) as *const AtomicU32);

    let count = string_count.load(Ordering::Relaxed) as usize;
    if count >= MAX_STRINGS {
        return Err(InternError::OutOfMemory);
    }

    let used = arena_used.load(Ordering::Relaxed) as usize;
    if used + 2 + bytes.len() > ARENA_CAPACITY {
        return Err(InternError::OutOfMemory);
    }

    let hash = hash_bytes(bytes);
    let fingerprint = h2(hash);
    let start = h1(hash, STR_HT_CAP);

    let data_base = seg.add(STR_DATA_OFF) as *mut StringHtSlot;
    let ctrl_base = seg.add(STR_CTRL_OFF);

    let mut pos = start;
    let mut stride = 0usize;

    // Probe the table; break-with-value gives the insertion slot index.
    let empty_slot: usize = 'search: loop {
        let group = Group::load(ctrl_base.add(pos));

        // Check each slot whose fingerprint byte matches
        for i in group.match_byte(fingerprint).iter() {
            let slot_idx = (pos + i) & (STR_HT_CAP - 1);
            let (string_index, off, slot_len) =
                StringHtSlot::unpack((*data_base.add(slot_idx)).packed);
            if slot_len == bytes.len() {
                let stored = core::slice::from_raw_parts(seg.add(off + 2), slot_len);
                if stored == bytes {
                    return Ok(StringIndex(string_index));
                }
            }
        }

        // An empty slot means the string is not in the table
        if let Some(i) = group.match_empty().lowest_set_bit() {
            break 'search (pos + i) & (STR_HT_CAP - 1);
        }

        stride += WIDTH;
        if stride >= STR_HT_CAP {
            // Defensive: full traversal with no empty slot found; table is full
            // (should not happen given load-factor pre-check above)
            return Err(InternError::OutOfMemory);
        }
        pos = (pos + stride) & (STR_HT_CAP - 1);
    };

    // Write string bytes into the arena (grows backward from SEGMENT_SIZE)
    let new_offset = SEGMENT_SIZE - used - 2 - bytes.len();
    (seg.add(new_offset) as *mut u16).write_unaligned(bytes.len() as u16);
    core::ptr::copy_nonoverlapping(bytes.as_ptr(), seg.add(new_offset + 2), bytes.len());

    // Write the hash-table slot
    let new_index = count as u32;
    (*data_base.add(empty_slot)).packed = StringHtSlot::pack(new_index, new_offset, bytes.len());

    // Update ctrl byte (and its mirror if near the start)
    set_ctrl(ctrl_base, STR_HT_CAP, empty_slot, fingerprint);

    // Publish the offset in the lock-free index array.
    // ptr::write initialises the slot; the Release on string_count synchronises with readers.
    let str_idx_base = seg.add(STR_IDX_OFF) as *mut AtomicU32;
    core::ptr::write(str_idx_base.add(count), AtomicU32::new(new_offset as u32));

    // Increment counters; Release on string_count so readers see the offset
    arena_used.store((used + 2 + bytes.len()) as u32, Ordering::Relaxed);
    string_count.store(new_index + 1, Ordering::Release);

    Ok(StringIndex(new_index))
}

/// Intern a rope of up to 5 byte slices as a single string.  Must be called
/// while holding `str_spinlock`.
///
/// Writes segments speculatively into the arena (no commit until the end),
/// hashes the contiguous result, then probes the hash table. If the string
/// already exists the speculative write is abandoned. Non-UTF-8 bytes are
/// replaced with U+FFFD on a slow path.
///
/// # Safety
/// `seg` must be a valid pointer to the start of a `SEGMENT_SIZE`-byte shared
/// memory region initialized by `ShmRegion::create()`.
pub(crate) unsafe fn intern_rope(
    seg: *mut u8,
    rope: &StrRope5,
) -> Result<StringIndex, InternError> {
    let opt_len = rope.optimistic_len();

    if opt_len > MAX_STR_LEN {
        return Err(InternError::StrTooLong);
    }

    let string_count = &*(seg.add(HEADER_OFF + STRING_COUNT_OFF) as *const AtomicU32);
    let arena_used = &*(seg.add(HEADER_OFF + ARENA_USED_OFF) as *const AtomicU32);

    let count = string_count.load(Ordering::Relaxed) as usize;
    if count >= MAX_STRINGS {
        return Err(InternError::OutOfMemory);
    }

    let used = arena_used.load(Ordering::Relaxed) as usize;

    // --- Fast path: assume all segments are valid UTF-8 ---
    // `actual_len` will be overwritten by the slow path if needed.
    let mut actual_len = opt_len;

    if used + 2 + opt_len > ARENA_CAPACITY {
        return Err(InternError::OutOfMemory);
    }

    let mut new_offset = SEGMENT_SIZE - used - 2 - opt_len;
    let write_base = seg.add(new_offset + 2);

    let segments = rope.leaves;

    // Optimistically copy all leaves into the arena as raw bytes.
    // No per-leaf UTF-8 validation: a single utf8_chunks() pass over the
    // concatenated buffer handles everything, including multibyte characters
    // that straddle leaf boundaries.
    let mut cursor = 0usize;
    for seg_bytes in segments {
        core::ptr::copy_nonoverlapping(seg_bytes.as_ptr(), write_base.add(cursor), seg_bytes.len());
        cursor += seg_bytes.len();
    }

    // Validate the full concatenation in one pass.
    // If the buffer is all valid UTF-8, the fast path is complete.
    // If not, measure the repaired length and rewrite with U+FFFD replacements.
    //
    // When actual_len > opt_len the repair region overlaps the raw region (both
    // end at write_base + opt_len, but repair starts earlier).  The repair pass
    // uses ptr::copy (memmove) for valid chunks so overlapping forward copies
    // are handled correctly.  The write cursor never overtakes the read cursor
    // because utf8_chunks() emits invalid sequences of at most 3 bytes
    // (invariant: write_offset ≤ read_offset + delta,
    // delta = actual_len − opt_len).
    {
        let raw = core::slice::from_raw_parts(write_base as *const u8, opt_len);

        let mut lossy_len = 0usize;
        let mut is_valid = true;
        for chunk in raw.utf8_chunks() {
            lossy_len += chunk.valid().len();
            if !chunk.invalid().is_empty() {
                lossy_len += 3; // U+FFFD is 3 bytes
                is_valid = false;
            }
        }

        if !is_valid {
            actual_len = lossy_len;

            if actual_len > MAX_STR_LEN {
                return Err(InternError::StrTooLong);
            }
            if used + 2 + actual_len > ARENA_CAPACITY {
                return Err(InternError::OutOfMemory);
            }

            new_offset = SEGMENT_SIZE - used - 2 - actual_len;
            let repair_base = seg.add(new_offset + 2);

            let mut cursor = 0usize;
            for chunk in raw.utf8_chunks() {
                let valid = chunk.valid();
                // ptr::copy (memmove) handles the overlap between the raw buffer
                // and the repair buffer when actual_len > opt_len.
                core::ptr::copy(valid.as_ptr(), repair_base.add(cursor), valid.len());
                cursor += valid.len();
                if !chunk.invalid().is_empty() {
                    // One U+FFFD (EF BF BD) per maximal ill-formed subsequence.
                    repair_base.add(cursor).write(0xEF);
                    repair_base.add(cursor + 1).write(0xBF);
                    repair_base.add(cursor + 2).write(0xBD);
                    cursor += 3;
                }
            }
        }
    }

    // Write the length prefix as a single 2-byte store.
    (seg.add(new_offset) as *mut u16).write_unaligned(actual_len as u16);

    // Hash the contiguous bytes now in the arena.
    let arena_bytes = core::slice::from_raw_parts(seg.add(new_offset + 2), actual_len);
    let hash = hash_bytes(arena_bytes);
    let fingerprint = h2(hash);
    let start = h1(hash, STR_HT_CAP);

    let data_base = seg.add(STR_DATA_OFF) as *mut StringHtSlot;
    let ctrl_base = seg.add(STR_CTRL_OFF);

    let mut pos = start;
    let mut stride = 0usize;

    let empty_slot: usize = 'search: loop {
        let group = Group::load(ctrl_base.add(pos));

        for i in group.match_byte(fingerprint).iter() {
            let slot_idx = (pos + i) & (STR_HT_CAP - 1);
            let (string_index, off, slot_len) =
                StringHtSlot::unpack((*data_base.add(slot_idx)).packed);
            if slot_len == actual_len {
                let stored = core::slice::from_raw_parts(seg.add(off + 2), slot_len);
                if stored == arena_bytes {
                    // Already interned — abandon speculative write (arena_used not updated).
                    return Ok(StringIndex(string_index));
                }
            }
        }

        if let Some(i) = group.match_empty().lowest_set_bit() {
            break 'search (pos + i) & (STR_HT_CAP - 1);
        }

        stride += WIDTH;
        if stride >= STR_HT_CAP {
            return Err(InternError::OutOfMemory);
        }
        pos = (pos + stride) & (STR_HT_CAP - 1);
    };

    let new_index = count as u32;

    (*data_base.add(empty_slot)).packed = StringHtSlot::pack(new_index, new_offset, actual_len);

    set_ctrl(ctrl_base, STR_HT_CAP, empty_slot, fingerprint);

    let str_idx_base = seg.add(STR_IDX_OFF) as *mut AtomicU32;
    core::ptr::write(str_idx_base.add(count), AtomicU32::new(new_offset as u32));

    arena_used.store((used + 2 + actual_len) as u32, Ordering::Relaxed);
    string_count.store(new_index + 1, Ordering::Release);

    Ok(StringIndex(new_index))
}

/// Look up a string by index.  Lock-free.
///
/// # Safety
/// `seg` must be a valid pointer to an initialized shared memory region.
/// The returned `&str` borrows from `seg`'s backing memory; the caller must
/// ensure the region outlives the reference.
pub(crate) unsafe fn get_str<'a>(seg: *const u8, idx: StringIndex) -> Option<&'a str> {
    let string_count = &*(seg.add(HEADER_OFF + STRING_COUNT_OFF) as *const AtomicU32);
    if idx.0 >= string_count.load(Ordering::Acquire) {
        return None;
    }

    let str_idx_base = seg.add(STR_IDX_OFF) as *const AtomicU32;
    let off = (*str_idx_base.add(idx.0 as usize)).load(Ordering::Relaxed) as usize;

    let len = (seg.add(off) as *const u16).read_unaligned() as usize;
    let slice = core::slice::from_raw_parts(seg.add(off + 2), len);
    Some(core::str::from_utf8_unchecked(slice))
}

#[inline]
unsafe fn set_ctrl(ctrl: *mut u8, cap: usize, i: usize, byte: u8) {
    ctrl.add(i).write(byte);
    if i < WIDTH {
        ctrl.add(cap + i).write(byte);
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::{MAX_STRINGS, MAX_STR_LEN, SEGMENT_SIZE};

    #[test]
    fn pack_unpack_roundtrip_max_values() {
        let string_index = (MAX_STRINGS - 1) as u32;
        let arena_offset = SEGMENT_SIZE - 2; // largest offset ever stored
        let len = MAX_STR_LEN;

        let packed = StringHtSlot::pack(string_index, arena_offset, len);
        let (si, off, l) = StringHtSlot::unpack(packed);

        assert_eq!(si, string_index);
        assert_eq!(off, arena_offset);
        assert_eq!(l, len);
    }

    #[test]
    fn pack_unpack_roundtrip_zero_values() {
        let packed = StringHtSlot::pack(0, 0, 0);
        let (si, off, l) = StringHtSlot::unpack(packed);
        assert_eq!(si, 0);
        assert_eq!(off, 0);
        assert_eq!(l, 0);
    }

    #[test]
    fn pack_unpack_roundtrip_distinct_values() {
        // Use distinct values for each field to catch field-overlap bugs.
        let string_index = 0x1234_u32;
        let arena_offset = 0x0ABC_DEF0_usize;
        let len = 0x3FFF_usize;

        let packed = StringHtSlot::pack(string_index, arena_offset, len);
        let (si, off, l) = StringHtSlot::unpack(packed);

        assert_eq!(si, string_index);
        assert_eq!(off, arena_offset);
        assert_eq!(l, len);
    }
}
