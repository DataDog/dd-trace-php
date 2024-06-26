use datadog_alloc::{AllocError, Allocator, ChainAllocator, Global};
use std::alloc::Layout;
use std::borrow::Borrow;
use std::fmt::{Debug, Formatter};
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

pub struct ThinString<A: Allocator = Global> {
    thin_ptr: ThinPtr,
    allocator: A,
}

pub trait ArenaAllocator: Allocator {}

impl<A: Allocator + Clone> ArenaAllocator for ChainAllocator<A> {}

impl<A: Allocator + Clone> Clone for ThinString<A> {
    fn clone(&self) -> Self {
        Self::from_str_in(self.deref(), self.allocator.clone())
    }
}

impl<A: Allocator> Drop for ThinString<A> {
    fn drop(&mut self) {
        let ptr = self.thin_ptr.size_ptr;
        // Don't drop the empty string.
        let empty_str = core::ptr::addr_of!(EMPTY_INLINE_STRING).cast::<u8>();
        if ptr.as_ptr() == empty_str.cast_mut() {
            return;
        }
        let layout = self.thin_ptr.layout();
        unsafe { self.allocator.deallocate(ptr, layout) }
    }
}

impl<A: Allocator> ThinString<A> {
    pub fn try_from_str_in(str: &str, allocator: A) -> Result<Self, AllocError> {
        let thin_ptr = ThinPtr::try_from_str_in(str, &allocator)?;
        Ok(Self {
            thin_ptr,
            allocator,
        })
    }

    pub fn from_str_in(str: &str, allocator: A) -> Self {
        Self::try_from_str_in(str, allocator).unwrap()
    }

    pub fn new_in(allocator: A) -> Self {
        let thin_ptr = EMPTY_INLINE_STRING.as_thin_ptr();
        Self {
            thin_ptr,
            allocator,
        }
    }
}

impl ThinString {
    pub fn new() -> Self {
        let thin_ptr = EMPTY_INLINE_STRING.as_thin_ptr();
        let allocator = Global;
        Self {
            thin_ptr,
            allocator,
        }
    }
}

impl From<&str> for ThinString {
    fn from(value: &str) -> Self {
        Self::try_from_str_in(value, Global).unwrap()
    }
}

impl From<&String> for ThinString {
    fn from(value: &String) -> Self {
        Self::try_from_str_in(value, Global).unwrap()
    }
}

impl From<String> for ThinString {
    fn from(value: String) -> Self {
        Self::try_from_str_in(&value, Global).unwrap()
    }
}

impl<'a> From<ThinStr<'a>> for ThinString {
    fn from(value: ThinStr<'a>) -> Self {
        Self::try_from_str_in(value.deref(), Global).unwrap()
    }
}

impl<'a> From<ThinStr<'a>> for &'a str {
    fn from(value: ThinStr<'a>) -> Self {
        // SAFETY: ThinStr<'a> into &'a str is sound (lifetime has no change).
        unsafe { value.thin_ptr.into_str() }
    }
}

unsafe impl<A: Allocator + Send> Send for ThinString<A> {}

impl Default for ThinString {
    fn default() -> Self {
        Self::new()
    }
}

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

impl Debug for ThinString {
    fn fmt(&self, f: &mut Formatter<'_>) -> std::fmt::Result {
        self.deref().fmt(f)
    }
}

impl<'a> Debug for ThinStr<'a> {
    fn fmt(&self, f: &mut Formatter<'_>) -> std::fmt::Result {
        self.deref().fmt(f)
    }
}

impl<A: Allocator> Deref for ThinString<A> {
    type Target = str;

    fn deref(&self) -> &Self::Target {
        self.thin_ptr.deref()
    }
}

impl<'a> Deref for ThinStr<'a> {
    type Target = str;

    fn deref(&self) -> &Self::Target {
        self.thin_ptr.deref()
    }
}

impl<A: Allocator> hash::Hash for ThinString<A> {
    fn hash<H: hash::Hasher>(&self, state: &mut H) {
        self.deref().hash(state)
    }
}

impl<'a> hash::Hash for ThinStr<'a> {
    fn hash<H: hash::Hasher>(&self, state: &mut H) {
        self.deref().hash(state)
    }
}

impl<A: Allocator> PartialEq for ThinString<A> {
    fn eq(&self, other: &Self) -> bool {
        self.deref().eq(other.deref())
    }
}

impl<'a> PartialEq for ThinStr<'a> {
    fn eq(&self, other: &Self) -> bool {
        self.deref().eq(other.deref())
    }
}

impl<A: Allocator> Eq for ThinString<A> {}
impl<'a> Eq for ThinStr<'a> {}

impl<A: Allocator> Borrow<str> for ThinString<A> {
    fn borrow(&self) -> &str {
        self.deref()
    }
}

impl<'a> Borrow<str> for ThinStr<'a> {
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

    fn try_from_str_in(str: &str, alloc: &impl Allocator) -> Result<Self, AllocError> {
        let inline_size = str.len() + USIZE_WIDTH;

        let layout = match Layout::from_size_align(inline_size, 1) {
            Ok(l) => l,
            Err(_) => return Err(AllocError),
        };
        let allocation = alloc.allocate(layout)?.cast::<u8>().as_ptr();

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

    /// # Safety
    /// The caller must ensure the lifetime is correctly bound.
    unsafe fn into_str<'a>(self) -> &'a str {
        let slice = {
            let len = self.len();
            let data = self.data().as_ptr();

            // SAFETY: bytes are never handed out as mut, so const slices are
            // not going to break aliasing rules. Lifetime enforcement must
            // be taken care of by the caller.
            core::slice::from_raw_parts(data, len)
        };

        // SAFETY: since this is a copy of a valid utf-8 string, then it must
        // also be valid utf-8.
        core::str::from_utf8_unchecked(slice)
    }
}

impl Deref for ThinPtr {
    type Target = str;

    fn deref(&self) -> &Self::Target {
        // SAFETY: the self lifetime is correct.
        unsafe { self.into_str() }
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
    use core::mem::size_of;
    use datadog_profiling::collections::string_table::wordpress_test_data;

    #[test]
    fn test_sizes() {
        let word = size_of::<NonNull<u8>>();
        assert_eq!(word, size_of::<ThinStr>());
        assert_eq!(word, size_of::<ThinString>());

        // niche optimization should apply too
        assert_eq!(word, size_of::<Option<ThinStr>>());
        assert_eq!(word, size_of::<Option<ThinString>>());
    }

    #[test]
    fn test_deallocation_of_empty_since_it_is_special_cased() {
        let thin_string = ThinString::new();
        drop(thin_string);
    }

    #[test]
    fn test_allocation_and_deallocation() {
        let thin_strs: Vec<ThinString> = wordpress_test_data::WORDPRESS_STRINGS
            .iter()
            .map(|str| {
                let thin_string = ThinString::from(*str);
                let actual = thin_string.deref();
                assert_eq!(*str, actual);
                thin_string
            })
            .collect();

        // This could detect out-of-bounds writes.
        for (thin_string, str) in thin_strs.iter().zip(wordpress_test_data::WORDPRESS_STRINGS) {
            let actual = thin_string.deref();
            assert_eq!(str, actual);
        }
    }
}
