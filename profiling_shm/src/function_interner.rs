use crate::atomic::{AtomicU32, AtomicU64, Ordering};

use crate::group::{Group, WIDTH};
use crate::hash::{h1, h2, hash_function};
use crate::{
    FunctionIndex, InternError, StringIndex, FN_CTRL_OFF, FN_DATA_OFF, FN_HT_CAP, FN_IDX_OFF,
    FUNCTION_COUNT_OFF, HEADER_OFF, MAX_FUNCTIONS,
};

#[derive(Clone, Copy)]
#[repr(transparent)]
pub(crate) struct FunctionHtSlot(u64);

impl FunctionHtSlot {
    #[inline]
    fn pack(name: u32, file: u32, function_index: u32) -> Self {
        Self((name as u64) | ((file as u64) << 21) | ((function_index as u64) << 42))
    }

    #[inline]
    fn name(self) -> u32 {
        (self.0 & 0x1F_FFFF) as u32
    }
    #[inline]
    fn file(self) -> u32 {
        ((self.0 >> 21) & 0x1F_FFFF) as u32
    }
    #[inline]
    fn function_index(self) -> u32 {
        ((self.0 >> 42) & 0xF_FFFF) as u32
    }
}

const _FN_SLOT_SIZE: [(); 8] = [(); core::mem::size_of::<FunctionHtSlot>()];

/// Intern a function.  Must be called while holding `fn_spinlock`.
///
/// # Safety
/// `seg` must be a valid pointer to the start of an initialized shared memory
/// region.
pub(crate) unsafe fn intern_function(
    seg: *mut u8,
    name: StringIndex,
    file: StringIndex,
) -> Result<FunctionIndex, InternError> {
    let function_count = &*(seg.add(HEADER_OFF + FUNCTION_COUNT_OFF) as *const AtomicU32);
    let count = function_count.load(Ordering::Relaxed) as usize;
    if count >= MAX_FUNCTIONS {
        return Err(InternError::OutOfMemory);
    }

    let hash = hash_function(name, file);
    let fingerprint = h2(hash);
    let start = h1(hash, FN_HT_CAP);

    let data_base = seg.add(FN_DATA_OFF) as *mut FunctionHtSlot;
    let ctrl_base = seg.add(FN_CTRL_OFF);

    let mut pos = start;
    let mut stride = 0usize;

    let empty_slot: usize = 'search: loop {
        let group = Group::load(ctrl_base.add(pos));

        for i in group.match_byte(fingerprint).iter() {
            let slot_idx = (pos + i) & (FN_HT_CAP - 1);
            let slot = *data_base.add(slot_idx);
            if slot.name() == name.0 && slot.file() == file.0 {
                return Ok(FunctionIndex(slot.function_index()));
            }
        }

        if let Some(i) = group.match_empty().lowest_set_bit() {
            break 'search (pos + i) & (FN_HT_CAP - 1);
        }

        stride += WIDTH;
        if stride >= FN_HT_CAP {
            return Err(InternError::OutOfMemory);
        }
        pos = (pos + stride) & (FN_HT_CAP - 1);
    };

    let new_index = count as u32;

    // Write hash-table slot
    data_base
        .add(empty_slot)
        .write(FunctionHtSlot::pack(name.0, file.0, new_index));

    // Update ctrl byte (and its mirror)
    set_ctrl(ctrl_base, FN_HT_CAP, empty_slot, fingerprint);

    // Publish packed (name, file) in the lock-free index array.
    // ptr::write initialises the slot; the Release on function_count synchronises with readers.
    let fn_idx_base = seg.add(FN_IDX_OFF) as *mut AtomicU64;
    let packed = (name.0 as u64) | ((file.0 as u64) << 32);
    core::ptr::write(fn_idx_base.add(count), AtomicU64::new(packed));

    function_count.store(new_index + 1, Ordering::Release);

    Ok(FunctionIndex(new_index))
}

/// Look up a function by index.  Lock-free.
///
/// # Safety
/// `seg` must be a valid pointer to an initialized shared memory region.
pub(crate) unsafe fn get_function(
    seg: *const u8,
    idx: FunctionIndex,
) -> Option<(StringIndex, StringIndex)> {
    let function_count = &*(seg.add(HEADER_OFF + FUNCTION_COUNT_OFF) as *const AtomicU32);
    if idx.0 >= function_count.load(Ordering::Acquire) {
        return None;
    }

    let fn_idx_base = seg.add(FN_IDX_OFF) as *const AtomicU64;
    let packed = (&*fn_idx_base.add(idx.0 as usize)).load(Ordering::Relaxed);
    let name = StringIndex(packed as u32);
    let file = StringIndex((packed >> 32) as u32);
    Some((name, file))
}

#[inline]
unsafe fn set_ctrl(ctrl: *mut u8, cap: usize, i: usize, byte: u8) {
    ctrl.add(i).write(byte);
    if i < WIDTH {
        ctrl.add(cap + i).write(byte);
    }
}
