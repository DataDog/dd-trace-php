mod arenavec;

use arenavec::*;
use core::ops::Deref;
use core::{borrow, hash, marker, mem, ptr, slice, str};
use datadog_profiling::collections::InternError;
use std::io;

#[repr(C)]
struct LengthPrefixedStringHeader {
    length: [u8; 2],
}

// todo: when the ability to make fat pointers to DSTs from raw parts becomes
//       available, switch to that.
#[allow(unused)]
#[repr(C)]
struct LengthPrefixedString {
    length: [u8; 2],
    data: [u8],
}

/// WARNING! Definitely not safe generally! Keep this as private type.
#[derive(Copy, Clone)]
struct LengthPrefixedStr(*const LengthPrefixedStringHeader);

/// # SAFETY
/// NOT INHERENTLY SAFE as it's just a pointer! The container using the
/// LengthPrefixedStr needs to abstract over it to ensure it's safe.
unsafe impl Send for LengthPrefixedStr {}

impl LengthPrefixedStr {
    #[inline]
    unsafe fn from_str_in(src: &str, nonnull: ptr::NonNull<[u8]>) -> Self {
        let base_ptr = nonnull.as_ptr().cast::<u8>();
        let header = base_ptr.cast::<LengthPrefixedStringHeader>();
        let len_bytes = (src.len() as u16).to_be_bytes();
        // SAFETY:
        let len_ptr = ptr::addr_of_mut!((*header).length);
        // SAFETY:
        ptr::copy_nonoverlapping(&len_bytes, len_ptr, 1);
        // SAFETY:
        let bytes_ptr = len_ptr.cast::<u8>().add(2);
        ptr::copy_nonoverlapping(src.as_ptr(), bytes_ptr, src.len());
        Self(header)
    }
}

impl Deref for LengthPrefixedStr {
    type Target = str;

    fn deref(&self) -> &Self::Target {
        let header = self.0.cast::<LengthPrefixedStringHeader>();
        // SAFETY: no mutable references are created for these strings, and
        // the pointer is valid as long as encapsulation is correct.
        let len = u16::from_ne_bytes(unsafe { (*header).length });
        // SAFETY: header is repr(C), and str data comes immediately after it.
        let ptr = unsafe { header.add(1) }.cast::<u8>();
        // SAFETY: u8 slices cannot be misaligned, the string was created from
        // a valid &str in the first place, and no mut references ever exist
        // for this type.
        let slice = unsafe { slice::from_raw_parts(ptr, len as usize) };
        // SAFETY: was created from &str in the first place.
        unsafe { str::from_utf8_unchecked(slice) }
    }
}

impl PartialEq for LengthPrefixedStr {
    fn eq(&self, other: &Self) -> bool {
        self.deref() == other.deref()
    }
}

impl Eq for LengthPrefixedStr {}

impl borrow::Borrow<str> for LengthPrefixedStr {
    fn borrow(&self) -> &str {
        self
    }
}

impl hash::Hash for LengthPrefixedStr {
    fn hash<H: hash::Hasher>(&self, state: &mut H) {
        self.deref().hash(state)
    }
}

type Hasher = hash::BuildHasherDefault<rustc_hash::FxHasher>;

pub struct StringSet {
    arena: ArenaVec,
    generation: u32,
    set: hashbrown::HashSet<LengthPrefixedStr, Hasher>,
}

struct ZeroSizedBuildHasher<H>(marker::PhantomData<fn() -> H>);
impl<H> ZeroSizedBuildHasher<H> {
    const fn make() -> hash::BuildHasherDefault<H> {
        // SAFETY: both zero-sized types.
        unsafe { mem::transmute(Self(marker::PhantomData)) }
    }
}

#[repr(C)]
pub struct StringHandle {
    generation: u32,
    offset: u32,
}

impl StringSet {
    pub const fn new() -> Self {
        let hasher = ZeroSizedBuildHasher::make();
        Self {
            arena: ArenaVec::new(),
            generation: 1,
            set: hashbrown::HashSet::with_hasher(hasher),
        }
    }

    pub fn with_arena_capacity(min_bytes: usize) -> io::Result<Self> {
        Ok(Self {
            arena: ArenaVec::with_capacity_in_bytes(min_bytes)?,
            generation: 1,
            set: hashbrown::HashSet::with_hasher(Hasher::default()),
        })
    }

    pub fn clear(&mut self) {
        // Don't let generation=0 occur. Handles with gen0 are guaranteed to
        // be stale.
        self.generation = self.generation.checked_add(1).unwrap_or(1);
    }

    pub fn fetch(&self, handle: StringHandle) -> Option<&str> {
        if handle.generation != self.generation {
            return None;
        }

        let base_ptr = self.arena.base_ptr();
        if !base_ptr.is_null() {
            let item_ptr = unsafe { base_ptr.add(handle.offset as usize) }.cast();
            // SAFETY: todo
            Some(unsafe { mem::transmute::<&str, &str>(LengthPrefixedStr(item_ptr).deref()) })
        } else {
            None
        }
    }

    pub fn intern<S>(&mut self, s: &S) -> Result<StringHandle, InternError>
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
                let prefixed_str = unsafe { LengthPrefixedStr::from_str_in(str, uninit_mem) };
                // Will succeed, since try_reserve succeed above.
                self.set.insert(prefixed_str);
                prefixed_str
            }
            Some(prefixed_str) => *prefixed_str,
        };

        let base_ptr = self.arena.base_ptr();
        let item_ptr = prefixed_str.0.cast::<u8>();
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
            generation: 0,
        }
    }
}

pub struct StringSetReader {
    arena: ArenaSlice,
    generation: u32,
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_clear() {
        let mut set = StringSet::with_arena_capacity(32).unwrap();
        let handle = set.intern("").unwrap();
        let empty = set.fetch(handle).unwrap();
        set.clear();
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
}
