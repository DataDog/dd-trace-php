// Unless explicitly stated otherwise all files in this repository are licensed under the Apache License Version 2.0.
// This product includes software developed at Datadog (https://www.datadoghq.com/). Copyright 2024-Present Datadog, Inc.

use datadog_profiling::alloc::r#virtual::{virtual_alloc, virtual_free};
use datadog_profiling::alloc::{pad_to, page_size, AllocError};
use std::alloc::Layout;
use std::cell::Cell;
use std::ops::Deref;
use std::sync::atomic::{AtomicU32, Ordering};
use std::{io, mem, ptr, slice};

/// PRIVATE TYPE.
/// The allocation header for an arena, which tracks information about the
/// allocation as well as holding the data directly.
#[repr(C)]
struct ArenaHeader {
    allocation_size: u32,
    rc: AtomicU32,
    len: AtomicU32,
    data: [mem::MaybeUninit<u8>; 0],
}

#[repr(C)]
pub struct ArenaSlice {
    // "borrows" the pointer. It cannot add new items.
    ptr: Option<ptr::NonNull<ArenaHeader>>,
}

impl Clone for ArenaSlice {
    fn clone(&self) -> Self {
        match self.ptr {
            None => Self { ptr: None },
            Some(nonnull) => {
                // SAFETY: since the ArenaVec holds a reference to the data, it
                // will still be alive.
                let header = unsafe { nonnull.as_ref() };

                // todo: should we consider overflows here and abort?
                header.rc.fetch_add(1, Ordering::SeqCst);

                Self { ptr: Some(nonnull) }
            }
        }
    }
}

impl ArenaHeader {
    #[track_caller]
    fn layout(capacity: usize) -> (Layout, usize) {
        _ = u32::try_from(capacity).expect("arena capacity to fit in u32");
        let array = Layout::array::<u8>(capacity).expect("arena array layout to succeed");
        Layout::new::<Self>()
            .extend(array)
            .expect("arena header layout to succeed")
    }
}

#[repr(C)]
pub struct ArenaVec {
    /// "owns" the header, meaning it is allowed to append new items, but it
    /// cannot mutate existing items. When appending, be careful to write the
    /// item before atomically increasing the length.
    header_ptr: Option<ptr::NonNull<ArenaHeader>>,

    /// The number of bytes allocated in the arena. This does not include the
    /// space occupied by the header. This is also duplicate information, the
    /// length is also stored in the header in an atomic way. Duplicating it
    /// means some operations do not need to involve atomics.
    length: Cell<u32>,

    /// The total number of bytes that can be stored in the arena. Unchanged
    /// after creation.
    capacity: u32,
}

impl Drop for ArenaVec {
    fn drop(&mut self) {
        if let Some(nonnull) = self.header_ptr.take() {
            // SAFETY: since the ArenaVec holds a reference to the data, it will
            // still be alive.
            let header = unsafe { nonnull.as_ref() };
            if header.rc.fetch_sub(1, Ordering::SeqCst) == 1 {
                // Safety: passing pointer and size un-changed.
                let _result =
                    unsafe { virtual_free(nonnull.cast(), header.allocation_size as usize) };

                #[cfg(debug_assertions)]
                if let Err(err) = _result {
                    panic!("failed to drop ArenaVec: {err:#}");
                }
            }
        }
    }
}

/// # Safety
/// The struct only holds a pointer to the real data. Since it owns the header,
/// it can be moved to another thread without issue.
unsafe impl Send for ArenaVec {}


/// # Safety
/// The struct only holds a pointer to the real data. It only exposes
/// thread-safe methods for reading data.
unsafe impl Send for ArenaSlice {}

static mut EMPTY_ARENA_HEADER: ArenaHeader = ArenaHeader {
    allocation_size: 0,
    rc: AtomicU32::new(1),
    len: AtomicU32::new(0),
    data: [],
};

impl ArenaVec {
    pub const fn new() -> Self {
        Self {
            header_ptr: None,
            length: Cell::new(0),
            capacity: 0,
        }
    }

    pub fn with_capacity_in_bytes(min_bytes: usize) -> io::Result<Self> {
        if min_bytes == 0 {
            return Ok(Self::new());
        }

        let page_size = page_size();
        // Need to ensure there is room for the header.
        let min_bytes = min_bytes.max(mem::size_of::<ArenaHeader>());
        match pad_to(min_bytes, page_size) {
            None => return Err(io::Error::new(
                io::ErrorKind::InvalidInput,
                format!("requested virtual allocation of {min_bytes} bytes could not be padded to the page size {page_size}"),
            )),
            Some(allocation_size) => unsafe {
                let unadjusted_capacity = match u32::try_from(allocation_size) {
                    Ok(cap) => cap,
                    Err(_err) => return Err(io::Error::new(
                        io::ErrorKind::InvalidInput,
                        format!("padded virtual allocation of {allocation_size} bytes did not fit in u32"),
                    )),
                };

                let nonnull = virtual_alloc(allocation_size)?.cast::<ArenaHeader>();
                let header = nonnull.as_ptr();
                ptr::addr_of_mut!((*header).allocation_size).write(unadjusted_capacity);
                ptr::addr_of_mut!((*header).rc).write(AtomicU32::new(1));
                ptr::addr_of_mut!((*header).len).write(AtomicU32::new(0));

                let header_size = mem::size_of::<ArenaHeader>() as u32;
                Ok(Self {
                    header_ptr: Some(nonnull),
                    length: Cell::new(0),
                    // SAFETY: points inside an allocation (non-null).
                    // will not underflow, min_bytes was .max'd with size of the header.
                    capacity: unadjusted_capacity - header_size,
                })
            }
        }
    }

    #[inline]
    pub fn len(&self) -> u32 {
        self.length.get()
    }

    #[inline]
    pub fn is_empty(&self) -> bool {
        self.len() == 0
    }

    pub fn try_reserve(&self, additional: u32) -> Result<ptr::NonNull<[u8]>, AllocError> {
        let len = self.len();
        // When all 3 u32 numbers are converted to u64, this cannot overflow.
        if u64::from(len) + u64::from(additional) > u64::from(self.capacity) {
            return Err(AllocError);
        } else {
            unsafe {
                // SAFETY: if there room for the reservation, there must be a
                // mapping backing the ArenaVec.
                let data_ptr = self.data_ptr().unwrap_unchecked().as_ptr();
                // SAFETY: this is in-bounds of the mapping, check math above.
                let reserved_addr = data_ptr.add(len as usize);
                let slice = ptr::slice_from_raw_parts_mut(reserved_addr, additional as usize);
                // SAFETY: it exists within the mapping, inherently non-null.
                Ok(ptr::NonNull::new_unchecked(slice))
            }
        }
    }

    /// # Safety
    /// Can only be called once after each successful try_reserve, and the
    /// `additional` memory must be the same as was given to try_reserve.
    pub unsafe fn commit(&self, additional: u32) {
        let new_len = self.length.get() + additional;
        self.length.set(new_len);
        // SAFETY: if the reservation succeeded, then there must be a mapping.
        let header_ptr = self.header_ptr.unwrap_unchecked().as_ptr();
        // SAFETY: the header is a valid ArenaHeader object, so projecting to
        // the len member is safe.
        let len_ptr = ptr::addr_of!((*header_ptr).len);
        // SAFETY: the len member never has a mutable reference to it, so it's
        // safe to create a const reference to it.
        (*len_ptr).store(new_len, Ordering::Release);
    }

    #[inline]
    pub(crate) fn data_ptr(&self) -> Option<ptr::NonNull<u8>> {
        match self.header_ptr {
            Some(nonnull_header) => {
                let header_ptr = nonnull_header.as_ptr();
                // SAFETY: since header is a valid mapping, projection to the
                // data member is safe.
                let data_ptr = unsafe { ptr::addr_of_mut!((*header_ptr).data).cast::<u8>() };
                Some(unsafe { ptr::NonNull::new_unchecked(data_ptr) })
            }
            None => None,
        }
    }

    pub fn slice(&self) -> ArenaSlice {
        match self.header_ptr {
            None => ArenaSlice { ptr: None },
            Some(header) => {
                unsafe {
                    // SAFETY:
                    let rc_ptr = ptr::addr_of!((*header.as_ptr()).rc);
                    // SAFETY:
                    (*rc_ptr).fetch_add(1, Ordering::Release);
                }
                ArenaSlice { ptr: Some(header) }
            }
        }
    }
}

impl ArenaSlice {
    fn header(&self) -> Option<&ArenaHeader> {
        match self.ptr.as_ref() {
            None => None,
            // SAFETY: slice has a reference count, will be alive.
            Some(nonull) => unsafe { Some(nonull.as_ref()) },
        }
    }
}

impl Deref for ArenaHeader {
    type Target = [u8];

    fn deref(&self) -> &Self::Target {
        let ptr = self.data.as_ptr().cast();
        let len = self.len.load(Ordering::Acquire) as usize;
        // SAFETY: ArenaHeader::layout() aligned it correctly, and  the first
        // `len` are properly initialized.
        unsafe { slice::from_raw_parts(ptr, len) }
    }
}

impl Deref for ArenaSlice {
    type Target = [u8];

    fn deref(&self) -> &Self::Target {
        match self.header() {
            None => &[],
            Some(header) => header.deref(),
        }
    }
}

impl Deref for ArenaVec {
    type Target = [u8];

    fn deref(&self) -> &Self::Target {
        // This is re-implemented instead of using the header's deref to avoid
        // having to load the atomic length.
        match self.data_ptr() {
            None => &[],

            // SAFETY: struct retains a refcount, so it's definitely alive or
            // something has already been screwed up.
            Some(nonnull) => {
                let ptr = nonnull.as_ptr();
                let len = self.len() as usize;
                // SAFETY: ArenaHeader::layout() aligned it correctly, and  the first
                // `len` are properly initialized.
                unsafe { slice::from_raw_parts(ptr, len) }
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_header_layout() {
        let (layout, data_offset) = ArenaHeader::layout(1);
        // no extra padding bytes
        assert_eq!(data_offset, mem::size_of::<ArenaHeader>());
        // One extra byte, since that's the capacity we asked for.
        assert_eq!(layout.size(), 1 + mem::size_of::<ArenaHeader>());
    }


    #[test]
    fn test_is_send() {
        fn is_send<S: Send>(_: &S) -> bool {
            true
        }
        let vec = ArenaVec::new();
        assert!(is_send(&vec));
        assert!(is_send(&vec.slice()));
    }
}
