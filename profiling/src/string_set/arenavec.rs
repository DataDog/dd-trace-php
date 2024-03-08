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

#[repr(transparent)]
struct HeaderPtr {
    ptr: ptr::NonNull<ArenaHeader>,
}

impl Deref for HeaderPtr {
    type Target = ArenaHeader;

    fn deref(&self) -> &Self::Target {
        // SAFETY: kept alive by refcount, no mutable references are ever made
        // to the header.
        unsafe { &self.ptr.as_ref() }
    }
}

impl Clone for HeaderPtr {
    fn clone(&self) -> Self {
        self.deref().rc.fetch_add(1, Ordering::Acquire);
        Self { ptr: self.ptr }
    }
}

/// # Safety
/// Refcount keeps the item alive.
unsafe impl Send for HeaderPtr {}

impl Drop for HeaderPtr {
    fn drop(&mut self) {
        let header = self.deref();
        if header.rc.fetch_sub(1, Ordering::SeqCst) == 1 {
            // Safety: passing pointer and size un-changed.
            let _result = unsafe { virtual_free(self.ptr.cast(), header.allocation_size as usize) };

            #[cfg(debug_assertions)]
            if let Err(err) = _result {
                panic!("failed to drop ArenaVec: {err:#}");
            }
        }
    }
}

#[derive(Clone)]
#[repr(C)]
pub struct ArenaSlice {
    // "borrows" the pointer. It cannot add new items.
    ptr: Option<HeaderPtr>,
}

impl ArenaSlice {
    /// Return a pointer to the beginning of the data (skips the header).
    /// Returns null if there isn't a mapping.
    pub fn base_ptr(&self) -> *const u8 {
        match &self.ptr {
            None => ptr::null(),
            // SAFETY: todo
            Some(header_ptr) => unsafe { ptr::addr_of!((*header_ptr.ptr.as_ptr()).data).cast() },
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
    ///
    /// Dangles when capacity=0.
    ///
    /// Must never create a mutable reference to the header!
    header_ptr: mem::ManuallyDrop<HeaderPtr>,

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
        if self.capacity != 0 {
            // SAFETY: dropped only if capacity is non-zero, compiler enforces
            // this is called only once, unless the user invokes unsafe drop
            // as well, and it's their responsibility to get that right.
            unsafe { mem::ManuallyDrop::drop(&mut self.header_ptr) }
        }
    }
}

impl ArenaVec {
    pub const fn new() -> Self {
        Self {
            header_ptr: mem::ManuallyDrop::new(HeaderPtr {
                ptr: ptr::NonNull::dangling(),
            }),
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

                let allocation = virtual_alloc(allocation_size)?;

                #[cfg(target_os = "linux")]
                libc::prctl(
                    libc::PR_SET_VMA,
                    libc::PR_SET_VMA_ANON_NAME,
                    allocation.as_ptr(),
                    allocation_size,
                    b"arenavec\0".as_ptr()
                );

                let nonnull = allocation.cast::<ArenaHeader>();
                let header = nonnull.as_ptr();
                ptr::addr_of_mut!((*header).allocation_size).write(unadjusted_capacity);
                ptr::addr_of_mut!((*header).rc).write(AtomicU32::new(1));
                ptr::addr_of_mut!((*header).len).write(AtomicU32::new(0));

                let header_size = mem::size_of::<ArenaHeader>() as u32;
                Ok(Self {
                    header_ptr: mem::ManuallyDrop::new(HeaderPtr { ptr: nonnull }),
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

    #[inline]
    pub fn capacity(&self) -> u32 {
        self.capacity
    }

    /// Tries to reserve `additional` bytes. Returns a fatptr to it on success.
    pub fn try_reserve(&self, additional: u32) -> Result<ptr::NonNull<[u8]>, AllocError> {
        let len = self.len();
        // When all 3 u32 numbers are converted to u64, this cannot overflow.
        if u64::from(len) + u64::from(additional) > u64::from(self.capacity) {
            return Err(AllocError);
        } else {
            unsafe {
                // SAFETY: if there is capacity for the reservation, then this
                // pointer is not-null and  is in-bounds of the mapping.
                let reserved_addr = self.base_ptr_mut().add(len as usize);
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
        let header = self.header_ptr.deref();
        header.len.store(new_len, Ordering::Release);
    }

    /// Returns a pointer to the beginning of the item storage (skips header).
    /// The caller must be sure to not mutate existing already inserted through
    /// this pointer!
    #[inline]
    pub(crate) fn base_ptr(&self) -> *const u8 {
        // SAFETY: reducing to const and not modifying existing items.
        unsafe { self.base_ptr_mut() }
    }

    /// # Safety
    /// Calling this function is safe but since doing things with it is
    /// dangerous, it's marked unsafe anyway to alert the caller. Items which
    /// already exist in the arena must not be modified.
    unsafe fn base_ptr_mut(&self) -> *mut u8 {
        if self.capacity != 0 {
            let nonnull = self.header_ptr.ptr;
            ptr::addr_of_mut!((*nonnull.as_ptr()).data).cast::<u8>()
        } else {
            ptr::null_mut()
        }
    }

    pub fn slice(&self) -> ArenaSlice {
        let ptr = (self.capacity != 0).then(|| HeaderPtr::clone(&*self.header_ptr));
        ArenaSlice { ptr }
    }
}

impl Deref for ArenaSlice {
    type Target = [u8];

    fn deref(&self) -> &Self::Target {
        match &self.ptr {
            Some(header) => {
                let header_ptr = header.ptr.as_ptr();
                // SAFETY: projection to data member is safe on valid pointer.
                let base_ptr = unsafe { ptr::addr_of!((*header_ptr).data).cast::<u8>() };
                let len = header.len.load(Ordering::Acquire) as usize;
                // SAFETY: ArenaHeader::layout() aligned it correctly, and  the first
                // `len` are properly initialized.
                unsafe { slice::from_raw_parts(base_ptr, len) }
            }
            None => &[],
        }
    }
}

impl Deref for ArenaVec {
    type Target = [u8];

    fn deref(&self) -> &Self::Target {
        // This is re-implemented instead of using the header's deref to avoid
        // having to load the atomic length.
        let base_ptr = self.base_ptr();
        if !base_ptr.is_null() {
            // SAFETY: cannot be misaligned, and first `len` are initialized.
            unsafe { slice::from_raw_parts(base_ptr, self.len() as usize) }
        } else {
            &[]
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
