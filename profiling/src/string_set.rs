use core::hash;
use core::ops::Deref;
use libdd_alloc::{AllocError, Allocator, ChainAllocator, VirtualAllocator};
use libdd_profiling::profiles::collections::ThinStr;

type Hasher = hash::BuildHasherDefault<rustc_hash::FxHasher>;
type HashSet<K> = std::collections::HashSet<K, Hasher>;

/// Allocates and constructs a [`ThinStr`] in one step.
///
/// This combines [`ThinStr::try_allocate_for`] and [`ThinStr::try_from_str_in`]
/// for convenience. The returned [`ThinStr`] borrows from the allocation made
/// by `alloc`, so the allocator must outlive the returned reference.
fn try_new_thin_str_in<'a, A: Allocator>(s: &str, alloc: &'a A) -> Result<ThinStr<'a>, AllocError> {
    let obj = ThinStr::try_allocate_for(s, alloc)?;
    // SAFETY: `obj` was just allocated by us, no other references exist,
    // so we can safely create a `&mut [MaybeUninit<u8>]` from it.
    let uninit = unsafe { &mut *obj.as_ptr() };
    ThinStr::try_from_str_in(s, uninit)
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
        const SIZE_HINT: usize = 2 * 1024 * 1024;
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
    pub fn insert(&mut self, str: &str) -> ThinStr<'_> {
        let set = &mut self.strings;
        match set.get(str) {
            Some(interned_str) => *interned_str,
            None => {
                // No match. Make a new string in the arena, and fudge its
                // lifetime to appease the borrow checker.
                let new_str = {
                    let s = try_new_thin_str_in(str, &self.arena)
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
