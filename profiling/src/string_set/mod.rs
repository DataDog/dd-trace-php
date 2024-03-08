mod arenavec;

use arenavec::*;
use core::ops::Deref;
use datadog_profiling::collections::{InternError, LengthPrefixedStr, ZeroSizedHashBuilder};
use std::cell::UnsafeCell;
use std::{borrow, hash, io, mem};

type Hasher = hash::BuildHasherDefault<rustc_hash::FxHasher>;

#[repr(transparent)]
pub struct StringSetCell {
    /// Interior mutability allows [StringSetCell] to be used in a thread-local
    /// variable or other const contexts without having to deal with bothersome
    /// mutability concerns. For this to be safe, there are important rules:
    ///  1. Do not provide references to members to the outside.
    ///  2. The type must not be [Sync]. It's not designed for multi-threading.
    cell: UnsafeCell<StringSet>,
}

impl Default for StringSetCell {
    fn default() -> Self {
        Self::new()
    }
}

impl StringSetCell {
    pub const fn new() -> Self {
        Self {
            cell: UnsafeCell::new(StringSet::new()),
        }
    }

    pub fn with_arena_capacity(min_bytes: u32) -> io::Result<Self> {
        Ok(Self {
            cell: UnsafeCell::new(StringSet::with_arena_capacity(min_bytes as usize)?),
        })
    }

    /// Returns how full the arena is, as a value from 0 to 1.0. For the
    /// case of zero capacity, it will return 1.0.
    pub fn arena_fullness(&self) -> f64 {
        // SAFETY: no references are ever returned out of local scope
        // (unique reference is guaranteed), and the pointer is definitely
        // valid (cell always contains a value).
        let set = unsafe { &mut *self.cell.get() };
        let capacity = set.arena.capacity();
        if capacity == 0 {
            1.0
        } else {
            set.arena.len() as f64 / set.arena.capacity() as f64
        }
    }

    pub fn is_same_generation(&self, handle: StringHandle) -> bool {
        // SAFETY: no references are ever returned out of local scope
        // (unique reference is guaranteed), and the pointer is definitely
        // valid (cell always contains a value).
        let set = unsafe { &mut *self.cell.get() };
        set.generation == handle.generation
    }

    /// Fetches the &str associated with the handle, copying it into a String.
    /// Returning a reference here is not safe. If you want a reference, then
    /// consider using [StringSetCell::reader] to get a reader which can safely
    /// return references.
    pub fn fetch(&self, handle: StringHandle) -> Option<String> {
        // SAFETY: no references are ever returned out of local scope
        // (unique reference is guaranteed), and the pointer is definitely
        // valid (cell always contains a value).
        let set = unsafe { &mut *self.cell.get() };
        set.fetch(handle).map(String::from)
    }

    /// Inserts the string into the set if it doesn't exist already, and then
    /// returns a handle which can be used by a [StringSetReader] to read it
    /// back into a reference.
    pub fn insert<S>(&self, value: &S) -> Result<StringHandle, InternError>
    where
        S: ?Sized + borrow::Borrow<str>,
    {
        // SAFETY: no references are ever returned out of local scope
        // (unique reference is guaranteed), and the pointer is definitely
        // valid (cell always contains a value).
        let set = unsafe { &mut *self.cell.get() };
        set.insert(value)
    }

    /// Obtains a reader object for the set. This can look up [StringHandle]s
    /// and turn them into `&str`s in a thread-safe way.
    pub fn reader(&self) -> StringSetReader {
        // SAFETY: no references are ever returned out of local scope
        // (unique reference is guaranteed), and the pointer is definitely
        // valid (cell always contains a value).
        let set = unsafe { &*self.cell.get() };
        set.reader()
    }

    /// Attempts to make a new generation of the set using the given capacity.
    /// The previous set will be dropped before the new set is created so the
    /// resources can be re-used by the OS if no other references exist.
    ///
    /// On error, the state of the object is in a memory-safe state, but
    /// generally difficult to use. It could still have the old set, or the
    /// old set could be gone and a zero-capacity one could be there in its
    /// place.
    pub fn new_generation_with_capacity(&self, capacity: u32) -> io::Result<()> {
        let ptr = self.cell.get();

        // SAFETY: no references are ever returned out of local scope, and the
        // pointer is definitely valid as cell always contains a value, so
        // moving the value out is safe, though respect the CAUTION caveat
        // below.
        let old_set = unsafe { ptr.read() };
        // CAUTION: at this point, the cell holds the same bits as old_set
        // even though it's been moved out. Be sure to fix it up before
        // returning, even for error cases.

        let Some(new_gen) = old_set.generation.checked_add(1) else {
            // Failed to make new generation so put the old one back and error.
            // SAFETY: previous object was read out first.
            unsafe { ptr.write(old_set) };
            return Err(io::Error::from(io::ErrorKind::Other));
        };
        let capacity = capacity as usize;

        // Drop the old set before making the new one for resource reclamation.
        drop(old_set);

        match StringSet::with_arena_capacity(capacity) {
            Ok(mut new_set) => {
                new_set.generation = new_gen;
                // SAFETY: previous object was read out first.
                unsafe { ptr.write(new_set) };
                Ok(())
            }
            Err(err) => {
                // The old set is gone, put an empty one to keep object safety.
                // SAFETY: previous object was read out first.
                unsafe {
                    ptr.write(StringSet {
                        generation: new_gen,
                        ..StringSet::new()
                    })
                }
                Err(err)
            }
        }
    }
}

pub struct StringSet {
    arena: ArenaVec,
    generation: u32,
    set: hashbrown::HashSet<LengthPrefixedStr, Hasher>,
}

/// # Safety
/// The pointers contained in the set point to memory in the arena, which is
/// stored in a virtual memory allocation. The allows the set to be moved to
/// another thread without issues.
unsafe impl Send for StringSet {}

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
#[repr(C)]
pub struct StringHandle {
    /// The generation of the string set that this handle was created from.
    generation: u32,

    /// Offset to the beginning of the string's data, not its length-prefix.
    offset: u32,
}

impl StringSet {
    pub const fn new() -> Self {
        Self {
            arena: ArenaVec::new(),
            generation: 1,
            set: hashbrown::HashSet::with_hasher(ZeroSizedHashBuilder::make()),
        }
    }

    pub fn with_arena_capacity(min_bytes: usize) -> io::Result<Self> {
        Ok(Self {
            arena: ArenaVec::with_capacity_in_bytes(min_bytes)?,
            generation: 1,
            set: hashbrown::HashSet::with_hasher(Hasher::default()),
        })
    }

    pub fn fetch(&self, handle: StringHandle) -> Option<&str> {
        if handle.generation != self.generation {
            return None;
        }

        let base_ptr = self.arena.base_ptr();
        if !base_ptr.is_null() {
            let item_ptr = unsafe { base_ptr.add(handle.offset as usize) };
            let header_ptr = item_ptr.cast::<LengthPrefixedStr>();
            // SAFETY: repr(transparent) to compatible type.
            let prefixed_str: LengthPrefixedStr = unsafe { mem::transmute(header_ptr) };
            // SAFETY: align lifetime to the set's (which is the same as the
            // arena's, which is the true lifetime).
            Some(unsafe { mem::transmute::<&str, &str>(prefixed_str.deref()) })
        } else {
            None
        }
    }

    pub fn insert<S>(&mut self, s: &S) -> Result<StringHandle, InternError>
    where
        S: ?Sized + borrow::Borrow<str>,
    {
        let str = s.borrow();
        let prefixed_str = match self.set.get(str) {
            None => {
                let Ok(len) = u16::try_from(str.len()) else {
                    return Err(InternError::LargeString(str.len()));
                };

                let needed_bytes = u32::from(len) + 2;
                // Reserve from both collections before adding to either.
                self.set.try_reserve(1)?;
                let uninit_mem = self.arena.try_reserve(needed_bytes)?;
                // SAFETY: todo
                let prefixed_str = unsafe { LengthPrefixedStr::from_str_in(str, uninit_mem) };
                // SAFETY: todo
                unsafe { self.arena.commit(needed_bytes) };
                // Will succeed, since try_reserve succeed above.
                self.set.insert(prefixed_str);
                prefixed_str
            }
            Some(prefixed_str) => *prefixed_str,
        };

        let base_ptr = self.arena.base_ptr();
        let item_ptr = prefixed_str.data_ptr().as_ptr().cast::<u8>();
        // won't be negative, will fit in u32
        let offset = unsafe { item_ptr.offset_from(base_ptr) as usize as u32 };

        Ok(StringHandle {
            generation: self.generation,
            offset,
        })
    }

    pub fn reader(&self) -> StringSetReader {
        StringSetReader {
            arena: self.arena.slice(),
            generation: self.generation,
        }
    }
}

pub struct StringSetReader {
    arena: ArenaSlice,
    generation: u32,
}

impl StringSetReader {
    /// Looks up the handle and returns the string if found.
    ///
    /// # Safety
    /// The handle must originate from the set that this reader originates
    /// from. The generation can be mis-matched, but if there are two sets,
    /// handles are not interchangeable and almost certainly will cause
    /// undefined behavior and lead to segmentation faults if handles from
    /// one are used in the other.
    ///
    /// One allowed case to use a handle that originates from elsewhere is to
    /// have a [StringHandle] with a generation of zero. Generation zero does
    /// not ever occur, and so it can be used to create a known-to-be-stale
    /// handle. This is useful for items which are zero-initialized.
    pub unsafe fn fetch(&self, handle: StringHandle) -> Option<&str> {
        if handle.generation != self.generation {
            return None;
        }

        let base_ptr = self.arena.base_ptr();
        if base_ptr.is_null() {
            return None;
        }

        debug_assert!(handle.offset >= 2);

        // SAFETY: as long as the safety condition of the function is respected
        // and this belongs to the set, then the handle offset is the beginning
        // of the string data, so to get to the length, this  needs to be
        // subtracted off. Since the base_ptr is not null (checked above), and
        // inserted strings are never modified during the lifetime of the
        // mapping, and the mapping is being held alive by refcount, this is
        // therefore safe... as long as repr(C) shenanigans have not occured.
        let item_ptr = base_ptr.add((handle.offset - 2) as usize);
        // SAFETY: The representation of the LengthPrefixedStr is a pointer to
        // the length header which was safely derived above.
        let str = mem::transmute::<*const u8, LengthPrefixedStr>(item_ptr);
        // SAFETY: the lifetime of the string is the lifetime of the mapping,
        // which is at least the lifetime of the self reference.
        Some(mem::transmute::<&str, &str>(str.deref()))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_clear() {
        let set = StringSetCell::with_arena_capacity(32).unwrap();
        let handle = set.insert("").unwrap();
        let reader = set.reader();

        // SAFETY: only one set exists for the test, so the handles must
        // originate from the same set.
        let empty = unsafe { reader.fetch(handle).unwrap() };
        assert_eq!("", empty);

        set.new_generation_with_capacity(32).unwrap();

        // SAFETY: only one set exists for the test, so the handles must
        // originate from the same set. The reader is also holding the
        // mapping alive.
        assert_eq!(Some(""), unsafe { reader.fetch(handle) });

        // SAFETY: same set, but a new generation means this will safely fail.
        assert!(unsafe { set.reader().fetch(handle) }.is_none());
    }

    #[test]
    fn test_is_send() {
        fn is_send<S: Send>(_: &S) -> bool {
            true
        }
        let set = StringSet::new();
        assert!(is_send(&set));
        assert!(is_send(&set.reader()));
    }

    /// Testing that a StringSet can be made in a const context, which can
    /// avoid lazy initialization in thread-locals, for instance.
    #[test]
    fn test_const_fn() {
        thread_local! {
            static STRING_SET: StringSetCell = const { StringSetCell::new() };
        }

        // Will fail, zero-sized capacity.
        let handle = STRING_SET.with(|cell| cell.insert("hello"));
        assert!(handle.is_err());

        let (hello, world, reader) = STRING_SET.with(|cell| {
            // Make room for some strings.
            cell.new_generation_with_capacity(64).unwrap();

            let hello = cell.insert("hello").unwrap();
            let world = cell.insert("world").unwrap();

            // Insert the same strings again and ensure the handles are the
            // same as they were before (deduplication is working).
            let hello2 = cell.insert("hello").unwrap();
            let world2 = cell.insert("world").unwrap();
            assert_eq!(hello, hello2);
            assert_eq!(world, world2);

            let set = unsafe { &*cell.cell.get() };
            assert_eq!(2, set.set.len());

            (hello, world, cell.reader())
        });

        // SAFETY: only one set exists for the test, so the handles must
        // originate from the same set.
        unsafe {
            assert_eq!(Some("hello"), reader.fetch(hello));
            assert_eq!(Some("world"), reader.fetch(world));
        }
    }
}
