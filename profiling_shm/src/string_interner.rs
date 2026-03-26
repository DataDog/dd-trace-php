use core::sync::atomic::{AtomicU32, Ordering};

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
/// memory region initialised by `ShmRegion::create()`.
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

    // Publish the offset in the lock-free index array
    let str_idx_base = seg.add(STR_IDX_OFF) as *mut AtomicU32;
    (&*str_idx_base.add(count)).store(new_offset as u32, Ordering::Relaxed);

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
/// memory region initialised by `ShmRegion::create()`.
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

    let mut written = 0usize;
    let mut slow = false;
    for seg_bytes in segments {
        if seg_bytes.is_empty() {
            continue;
        }
        if core::str::from_utf8(seg_bytes).is_err() {
            slow = true;
            break;
        }
        core::ptr::copy_nonoverlapping(
            seg_bytes.as_ptr(),
            write_base.add(written),
            seg_bytes.len(),
        );
        written += seg_bytes.len();
    }

    // --- Slow path: at least one segment contained invalid UTF-8 ---
    if slow {
        // Count the actual output length with U+FFFD replacements.
        let mut lossy_len = 0usize;
        for seg_bytes in segments {
            if seg_bytes.is_empty() {
                continue;
            }
            // Separators are always ASCII; only user segments can be invalid.
            let mut remaining = seg_bytes;
            loop {
                match core::str::from_utf8(remaining) {
                    Ok(s) => {
                        lossy_len += s.len();
                        break;
                    }
                    Err(e) => {
                        lossy_len += e.valid_up_to();
                        lossy_len += 3; // U+FFFD is 3 bytes
                        let skip = e.error_len().unwrap_or(1);
                        remaining = &remaining[e.valid_up_to() + skip..];
                    }
                }
            }
        }

        actual_len = lossy_len;

        if actual_len > MAX_STR_LEN {
            return Err(InternError::StrTooLong);
        }
        if used + 2 + actual_len > ARENA_CAPACITY {
            return Err(InternError::OutOfMemory);
        }

        new_offset = SEGMENT_SIZE - used - 2 - actual_len;
        let write_base = seg.add(new_offset + 2);
        let mut cursor = 0usize;

        for seg_bytes in segments {
            if seg_bytes.is_empty() {
                continue;
            }
            let mut remaining = seg_bytes;
            loop {
                match core::str::from_utf8(remaining) {
                    Ok(s) => {
                        core::ptr::copy_nonoverlapping(s.as_ptr(), write_base.add(cursor), s.len());
                        cursor += s.len();
                        break;
                    }
                    Err(e) => {
                        let valid = e.valid_up_to();
                        core::ptr::copy_nonoverlapping(
                            remaining.as_ptr(),
                            write_base.add(cursor),
                            valid,
                        );
                        cursor += valid;
                        // Write U+FFFD replacement character
                        write_base.add(cursor).write(0xEF);
                        write_base.add(cursor + 1).write(0xBF);
                        write_base.add(cursor + 2).write(0xBD);
                        cursor += 3;
                        let skip = e.error_len().unwrap_or(1);
                        remaining = &remaining[valid + skip..];
                    }
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
    (&*str_idx_base.add(count)).store(new_offset as u32, Ordering::Relaxed);

    arena_used.store((used + 2 + actual_len) as u32, Ordering::Relaxed);
    string_count.store(new_index + 1, Ordering::Release);

    Ok(StringIndex(new_index))
}

/// Look up a string by index.  Lock-free.
///
/// # Safety
/// `seg` must be a valid pointer to an initialised shared memory region.
/// The returned `&str` borrows from `seg`'s backing memory; the caller must
/// ensure the region outlives the reference.
pub(crate) unsafe fn get_str<'a>(seg: *const u8, idx: StringIndex) -> Option<&'a str> {
    let string_count = &*(seg.add(HEADER_OFF + STRING_COUNT_OFF) as *const AtomicU32);
    if idx.0 >= string_count.load(Ordering::Acquire) {
        return None;
    }

    let str_idx_base = seg.add(STR_IDX_OFF) as *const AtomicU32;
    let off = (&*str_idx_base.add(idx.0 as usize)).load(Ordering::Relaxed) as usize;

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
