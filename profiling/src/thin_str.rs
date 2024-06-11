use crate::string_set::ArenaAllocator;
use datadog_alloc::AllocError;
use std::alloc::Layout;
use std::borrow::Borrow;
use std::hash;
use std::marker::PhantomData;
use std::ops::Deref;
use std::ptr::NonNull;

#[repr(C)]
struct StaticInlineString<const N: usize> {
    /// Stores the len of `data`.
    size: [u8; core::mem::size_of::<usize>()],
    data: [u8; N],
}

const USIZE_WIDTH: usize = core::mem::size_of::<usize>();

static EMPTY_INLINE_STRING: StaticInlineString<0> = StaticInlineString::<0> {
    size: [0; USIZE_WIDTH],
    data: [],
};

/// A struct which acts like a thin &str. It does this by storing the size
/// of the string just before the bytes of the string.
#[derive(Copy, Clone)]
#[repr(transparent)]
pub struct ThinStr<'a> {
    /// Points to the beginning of a struct which looks like this:
    /// ```
    /// #[repr(C)]
    /// struct InlineString {
    ///     /// Stores the len of `data`.
    ///     size: [u8; core::mem::size_of::<usize>()],
    ///     data: [u8],
    /// }
    /// ```
    size_ptr: NonNull<u8>,

    /// Since [ThinStr] doesn't hold a reference but acts like one, indicate
    /// this to the compiler with phantom data. This takes up no space.
    _marker: PhantomData<&'a str>,
}

impl ThinStr<'static> {
    pub fn new() -> ThinStr<'static> {
        let ptr = core::ptr::addr_of!(EMPTY_INLINE_STRING)
            .cast::<u8>()
            .cast_mut();
        Self {
            size_ptr: unsafe { NonNull::new_unchecked(ptr) },
            _marker: PhantomData,
        }
    }
}

impl<'a> ThinStr<'a> {
    // todo: move ArenaAllocator trait to `datadog_alloc` as a marker trait
    //       (meaning, remove the associated method and leave that in prof)?
    pub fn new_in(str: &str, arena: &'a impl ArenaAllocator) -> Result<Self, AllocError> {
        let inline_size = str.len() + USIZE_WIDTH;

        let layout = match Layout::from_size_align(inline_size, 1) {
            Ok(l) => l,
            Err(_) => return Err(AllocError),
        };
        let allocation = arena.allocate(layout)?.cast::<u8>().as_ptr();

        let size = allocation.cast::<[u8; USIZE_WIDTH]>();
        // SAFETY: writing into uninitialized new allocation at correct place.
        unsafe { size.write(str.len().to_ne_bytes()) };

        // SAFETY: the data pointer is just after the header, and the
        // allocation is at least that long.
        let data = unsafe { allocation.add(USIZE_WIDTH) };

        // SAFETY: the allocation is big enough, locations are distinct, and
        // the alignment is 1 (so it's always aligned), and the memory is safe
        // for writing.
        unsafe { core::ptr::copy_nonoverlapping(str.as_bytes().as_ptr(), data, str.len()) };

        Ok(Self {
            size_ptr: unsafe { NonNull::new_unchecked(allocation) },
            _marker: PhantomData,
        })
    }

    /// Reads the size prefix to get the length of the string.
    fn slice_len(&self) -> usize {
        // SAFETY: ThinStr points to the size prefix of the string.
        let size = unsafe { self.size_ptr.cast::<[u8; USIZE_WIDTH]>().as_ptr().read() };
        usize::from_ne_bytes(size)
    }

    /// Gets the layout of a ThinStr, such as to deallocate it.
    #[allow(unused)]
    #[inline]
    pub fn layout(&self) -> Layout {
        let len = self.slice_len();
        // SAFETY: since this object exists, its layout must be valid.
        unsafe { Layout::from_size_align_unchecked(len + USIZE_WIDTH, 1) }
    }
}

impl<'a> Deref for ThinStr<'a> {
    type Target = str;

    fn deref(&self) -> &Self::Target {
        let slice = {
            let len = self.slice_len();
            // SAFETY: data is located immediately after the header. There are
            // no padding bytes at play.
            let data = unsafe { self.size_ptr.as_ptr().add(USIZE_WIDTH) };
            // SAFETY: bytes are never handed out as mut, so const slices are
            // not going to break aliasing rules.
            unsafe { core::slice::from_raw_parts(data, len) }
        };

        // SAFETY: since this is a copy of a valid utf-8 string, then it must
        // also be valid utf-8.
        unsafe { core::str::from_utf8_unchecked(slice) }
    }
}

impl<'a> hash::Hash for ThinStr<'a> {
    fn hash<H: hash::Hasher>(&self, state: &mut H) {
        self.deref().hash(state)
    }
}

impl<'a> PartialEq for ThinStr<'a> {
    fn eq(&self, other: &Self) -> bool {
        self.deref().eq(other.deref())
    }
}

impl<'a> Eq for ThinStr<'a> {}

impl<'a> Borrow<str> for ThinStr<'a> {
    fn borrow(&self) -> &str {
        self.deref()
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use datadog_alloc::{Allocator, Global};
    use datadog_profiling::collections::string_table::wordpress_test_data;

    // Not really, please manually de-allocate strings when done with them.
    impl ArenaAllocator for Global {}

    #[test]
    fn test_allocation_and_deallocation() {
        let alloc = Global;

        let mut thin_strs: Vec<ThinStr> = wordpress_test_data::WORDPRESS_STRINGS
            .iter()
            .map(|str| {
                let thin_str = ThinStr::new_in(str, &alloc).unwrap();
                let actual = thin_str.deref();
                assert_eq!(*str, actual);
                thin_str
            })
            .collect();

        // This could detect out-of-bounds writes.
        for (thin_str, str) in thin_strs.iter().zip(wordpress_test_data::WORDPRESS_STRINGS) {
            let actual = thin_str.deref();
            assert_eq!(str, actual);
        }

        for thin_str in thin_strs.drain(..) {
            unsafe { alloc.deallocate(thin_str.size_ptr, thin_str.layout()) };
        }
    }
}
