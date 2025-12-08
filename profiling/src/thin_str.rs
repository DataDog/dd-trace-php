use libdd_alloc::{AllocError, Allocator, ChainAllocator};
use std::alloc::Layout;
use std::borrow::Borrow;
use std::hash;
use std::marker::PhantomData;
use std::ops::Deref;
use std::ptr::NonNull;

/// A struct which acts like a thin &str. It does this by storing the size
/// of the string just before the bytes of the string.
#[derive(Copy, Clone)]
#[repr(transparent)]
pub struct ThinStr<'a> {
    thin_ptr: ThinPtr,

    /// Since [ThinStr] doesn't hold a reference but acts like one, indicate
    /// this to the compiler with phantom data. This takes up no space.
    _marker: PhantomData<&'a str>,
}

pub trait ArenaAllocator: Allocator {}

impl<A: Allocator + Clone> ArenaAllocator for ChainAllocator<A> {}

impl ThinStr<'static> {
    pub fn new() -> ThinStr<'static> {
        Self {
            thin_ptr: EMPTY_INLINE_STRING.as_thin_ptr(),
            _marker: PhantomData,
        }
    }
}

impl Default for ThinStr<'static> {
    fn default() -> Self {
        Self::new()
    }
}

impl<'a> ThinStr<'a> {
    // todo: move ArenaAllocator trait to `datadog_alloc` as a marker trait
    //       (meaning, remove the associated method and leave that in prof)?
    #[allow(dead_code)]
    pub fn try_from_str_in(str: &str, arena: &'a impl ArenaAllocator) -> Result<Self, AllocError> {
        let thin_ptr = ThinPtr::try_from_str_in(str, arena)?;
        let _marker = PhantomData;
        Ok(Self { thin_ptr, _marker })
    }

    /// Gets the layout of a ThinStr, such as to deallocate it.
    #[allow(unused)]
    #[inline]
    pub fn layout(&self) -> Layout {
        self.thin_ptr.layout()
    }
}

impl Deref for ThinStr<'_> {
    type Target = str;

    fn deref(&self) -> &Self::Target {
        let slice = {
            let len = self.thin_ptr.len();
            let data = self.thin_ptr.data().as_ptr();

            // SAFETY: bytes are never handed out as mut, so const slices are
            // not going to break aliasing rules, and this is the correct
            // lifetime for the data.
            unsafe { core::slice::from_raw_parts(data, len) }
        };

        // SAFETY: since this is a copy of a valid utf-8 string, then it must
        // also be valid utf-8.
        unsafe { core::str::from_utf8_unchecked(slice) }
    }
}

impl hash::Hash for ThinStr<'_> {
    fn hash<H: hash::Hasher>(&self, state: &mut H) {
        self.deref().hash(state)
    }
}

impl PartialEq for ThinStr<'_> {
    fn eq(&self, other: &Self) -> bool {
        self.deref().eq(other.deref())
    }
}

impl Eq for ThinStr<'_> {}

impl Borrow<str> for ThinStr<'_> {
    fn borrow(&self) -> &str {
        self.deref()
    }
}

#[derive(Clone, Copy)]
struct ThinPtr {
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
}

impl ThinPtr {
    /// Reads the size prefix to get the length of the string.
    const fn len(self) -> usize {
        // SAFETY: ThinStr points to the size prefix of the string.
        let size = unsafe { self.size_ptr.cast::<[u8; USIZE_WIDTH]>().as_ptr().read() };
        usize::from_ne_bytes(size)
    }

    /// Returns a pointer to the string data (not to the header).
    const fn data(self) -> NonNull<u8> {
        // SAFETY: ThinStr points to the size prefix of the string, and the
        // string data is located immediately after without padding.
        let ptr = unsafe { self.size_ptr.as_ptr().add(USIZE_WIDTH) };

        // SAFETY: derived from a NonNull, so it's also NonNull.
        unsafe { NonNull::new_unchecked(ptr) }
    }

    /// Gets the layout of a ThinStr, such as to deallocate it.
    #[allow(unused)]
    #[inline]
    fn layout(self) -> Layout {
        let len = self.len();
        // SAFETY: since this object exists, its layout must be valid.
        unsafe { Layout::from_size_align_unchecked(len + USIZE_WIDTH, 1) }
    }

    fn try_from_str_in(str: &str, arena: &impl Allocator) -> Result<Self, AllocError> {
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

        let size_ptr = unsafe { NonNull::new_unchecked(allocation) };
        Ok(ThinPtr { size_ptr })
    }
}

#[repr(C)]
struct StaticInlineString<const N: usize> {
    /// Stores the len of `data`.
    size: [u8; core::mem::size_of::<usize>()],
    data: [u8; N],
}

impl<const N: usize> StaticInlineString<N> {
    fn as_thin_ptr(&self) -> ThinPtr {
        let ptr = core::ptr::addr_of!(EMPTY_INLINE_STRING).cast::<u8>();
        // SAFETY: derived from static address, and ThinStr does not allow
        // modifications, so the mut-cast is also fine.
        let size_ptr = unsafe { NonNull::new_unchecked(ptr.cast_mut()) };
        ThinPtr { size_ptr }
    }
}

const USIZE_WIDTH: usize = core::mem::size_of::<usize>();

static EMPTY_INLINE_STRING: StaticInlineString<0> = StaticInlineString::<0> {
    size: [0; USIZE_WIDTH],
    data: [],
};

#[cfg(test)]
mod tests {
    use super::*;
    use libdd_alloc::Global;
    use libdd_profiling::collections::string_table::wordpress_test_data;

    // Not really, please manually de-allocate strings when done with them.
    impl ArenaAllocator for Global {}

    #[test]
    fn test_allocation_and_deallocation() {
        let alloc = Global;

        let mut thin_strs: Vec<ThinStr> = wordpress_test_data::WORDPRESS_STRINGS
            .iter()
            .map(|str| {
                let thin_str = ThinStr::try_from_str_in(str, &alloc).unwrap();
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
            unsafe { alloc.deallocate(thin_str.thin_ptr.size_ptr, thin_str.layout()) };
        }
    }
}
