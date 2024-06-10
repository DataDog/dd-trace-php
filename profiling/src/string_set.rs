use core::hash;
use core::marker::PhantomData;
use core::ops::Deref;
use core::ptr::NonNull;
use datadog_alloc::{AllocError, Allocator, ChainAllocator, VirtualAllocator};
use std::alloc::Layout;
use std::borrow::Borrow;

trait ArenaAllocator: Allocator {}
impl<A: Allocator + Clone> ArenaAllocator for ChainAllocator<A> {}

type Hasher = hash::BuildHasherDefault<rustc_hash::FxHasher>;
type HashSet<K> = std::collections::HashSet<K, Hasher>;

#[repr(C)]
struct StaticInlineString<const N: usize> {
    /// Stores the len of `data`.
    size: [u8; core::mem::size_of::<usize>()],
    data: [u8; N],
}

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

const USIZE_WIDTH: usize = core::mem::size_of::<usize>();

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
    fn new_in(str: &str, arena: &'a impl ArenaAllocator) -> Result<Self, AllocError> {
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

/// Holds unique strings and provides [StringId]s that correspond to the order
/// that the strings were inserted.
pub struct StringSet {
    /// The bytes of each string stored in `strings` are allocated here.
    arena: ChainAllocator<VirtualAllocator>,

    /// The unordered hash set of unique strings.
    /// The static lifetime is a lie, it is tied to the `arena`, which is only
    /// moved if the string set is moved e.g.
    /// [StringSet::into_lending_iterator].
    /// References to the underlying strings should generally not be handed,
    /// but if they are, they should be bound to the string set's lifetime or
    /// the lending iterator's lifetime.
    strings: HashSet<ThinStr<'static>>,
}

impl Default for StringSet {
    fn default() -> Self {
        Self::new()
    }
}

impl StringSet {
    /// Creates a new string set, which initially holds the empty string and
    /// no others.
    pub fn new() -> Self {
        // Keep this in the megabyte range. It's virtual, so we do not need
        // to worry much about unused amounts, but asking for wildly too much
        // up front, like in gigabyte+ range, is not good either.
        const SIZE_HINT: usize = 4 * 1024 * 1024;
        let arena = ChainAllocator::new_in(SIZE_HINT, VirtualAllocator {});

        let mut strings = HashSet::with_hasher(Hasher::default());
        // The initial capacities for Rust's hash map (and set) currently go
        // like this: 3, 7, 14, 28.
        // The smaller values definitely can cause too much reallocation when
        // walking a real stack for the first time. This memory isn't virtual,
        // though, so we don't reach too high.
        strings.reserve(16);

        // Always hold the empty string as item 0. Do not insert it via
        // `StringSet::insert` because that will try to allocate zero-bytes
        // from the allocator, which is sketchy. This prevents zero-sized
        // allocations at runtime.
        strings.insert(ThinStr::new());

        Self { arena, strings }
    }

    /// Returns the number of strings currently held in the string set.
    #[inline]
    #[allow(clippy::len_without_is_empty, unused)]
    pub fn len(&self) -> usize {
        self.strings.len()
    }

    /// Adds the string to the string set if it isn't present already, and
    /// returns a reference to the newly inserted string.
    ///
    /// # Panics
    /// This panics if the allocator fails to allocate. This could happen for
    /// a few reasons:
    ///  - It failed to acquire a chunk.
    pub fn insert(&mut self, str: &str) -> ThinStr {
        let set = &mut self.strings;
        match set.get(str) {
            Some(interned_str) => *interned_str,
            None => {
                // No match. Make a new string in the arena, and fudge its
                // lifetime to appease the borrow checker.
                let new_str = {
                    let s = ThinStr::new_in(str, &self.arena)
                        .expect("allocation for StringSet::insert to succeed");

                    // SAFETY: all references to this value get re-narrowed to
                    // the lifetime of the string set. The string set will
                    // keep the arena alive, making the access safe.
                    unsafe { core::mem::transmute::<ThinStr<'_>, ThinStr<'static>>(s) }
                };

                // Add it to the set.
                self.strings.insert(new_str);

                new_str
            }
        }
    }

    /// Returns the amount of bytes used by the arena allocator used to hold
    /// string data. Note that the string set uses more memory than is in the
    /// arena.
    #[inline]
    pub fn arena_used_bytes(&self) -> usize {
        self.arena.used_bytes()
    }

    /// Creates a `&str` from the `thin_str`, binding it to the lifetime of
    /// the set.
    ///
    /// # Safety
    /// The `thin_str` must live in this string set.
    #[inline]
    pub unsafe fn get_thin_str(&self, thin_str: ThinStr) -> &str {
        // todo: debug_assert it exists in the memory region?
        // SAFETY: see function's safety conditions.
        unsafe { core::mem::transmute(thin_str.deref()) }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use datadog_alloc::Global;
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
