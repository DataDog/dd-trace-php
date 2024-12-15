// Copyright 2024-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

use super::{ConstStorage, Storage, ThinHeader, ThinStr, EMPTY};
use allocator_api2::alloc::{AllocError, Allocator, Global, Layout};
use core::ops::Deref;
use core::{fmt, hash, mem, ptr};
use tagged_pointer::TaggedPtr;

const OWNED: usize = 0;
const BORROWED: usize = 1;

/// An owned string which use a "thin" pointer, so it uses fewer bytes than
/// a [Box]'d str. It cannot be resized unless it reallocates, but currently
/// there no apis for this. Currently, there are no APIs for mutable
/// operations on the string data at all.
#[derive(Debug)]
pub struct ThinString<A: Allocator = Global> {
    /// The alignment of [ThinHeader] means that [TaggedPtr] can use the least
    /// significant bit to store whether the string is owned or borrowed. Use
    /// the [OWNED] and [BORROWED] constants for this.
    tagged_ptr: TaggedPtr<ThinHeader, 1>,
    allocator: A,
}

unsafe impl<A: Allocator + Send> Send for ThinString<A> {}

impl<A: Allocator> ThinString<A> {
    /// Creates the empty ThinString.
    /// This will not allocate.
    pub fn new_in(allocator: A) -> Self {
        Self::from_const_storage_in(&EMPTY, allocator)
    }

    /// Create a ThinString from &static [ConstStorage]. Note that there's not
    /// _quite_ enough support to make this a const fn.
    /// This will not allocate.
    pub fn from_const_storage_in<const N: usize>(
        const_storage: &'static ConstStorage<N>,
        allocator: A,
    ) -> ThinString<A> {
        let header_ptr = ptr::NonNull::from(const_storage).cast::<ThinHeader>();
        ThinString {
            tagged_ptr: TaggedPtr::new(header_ptr, BORROWED),
            allocator,
        }
    }

    /// Create a ThinString from the given &str.
    /// This will allocate in the given allocator.
    /// # Panics
    /// This panics if allocation fails.
    pub fn from_str_in(string: &str, allocator: A) -> Self {
        Self::try_from_str_in(string, allocator).unwrap()
    }

    /// Tries to create a ThinString from the given &str. Fails only if
    /// allocation fails.
    pub fn try_from_str_in(string: &str, allocator: A) -> Result<Self, AllocError> {
        let header = Layout::new::<ThinHeader>();
        let data = Layout::for_value(string);
        let Ok((layout, offset)) = header.extend(data) else {
            return Err(AllocError);
        };
        // Padding is important here, since the str could be an odd number of
        // bytes, and `Layout::extend` doesn't automatically pad.
        let layout = layout.pad_to_align();

        // Sanity check some things to defend against refactoring.
        debug_assert_eq!(offset, mem::size_of::<ThinHeader>());
        debug_assert_eq!(offset, mem::size_of::<usize>());

        let obj = allocator.allocate(layout)?;
        let header = ThinHeader::from(string.len());
        let header_ptr = obj.cast::<ThinHeader>();

        // SAFETY: the memory is valid for writes and has a layout suitable
        // for a header. Drop isn't a concern since raw bytes don't need
        // dropped.
        unsafe { header_ptr.as_ptr().write(header) };

        // SAFETY: the offset is at the correct place from the base pointer,
        // and again, the memory is valid for writes and Drop is irrelevant.
        let data = unsafe { obj.cast::<u8>().as_ptr().add(offset) };

        // SAFETY: a new, non-zero sized allocated object must not overlap
        // with existing memory by definition. Both regions are at valid for
        // a read or write respectively of the specified length, and u8 is
        // always aligned.
        unsafe { ptr::copy_nonoverlapping(string.as_ptr(), data, string.len()) };

        // Double check alignment in case refactoring breaks assumptions.
        debug_assert_eq!(mem::align_of::<u16>(), mem::align_of::<ThinHeader>());

        let tagged_ptr = TaggedPtr::new(header_ptr, OWNED);

        Ok(ThinString {
            tagged_ptr,
            allocator,
        })
    }

    /// Creates a ThinStr that borrows from the ThinString.
    pub fn as_thin_str(&self) -> ThinStr {
        let storage: &Storage = self.into();
        ThinStr::from(storage)
    }
}

impl<A: Allocator + Default> Default for ThinString<A> {
    fn default() -> ThinString<A> {
        Self::new_in(A::default())
    }
}

impl<A: Allocator> PartialEq for ThinString<A> {
    fn eq(&self, other: &Self) -> bool {
        self.deref() == other.deref()
    }
}

impl<A: Allocator> Eq for ThinString<A> {}

impl<A: Allocator> hash::Hash for ThinString<A> {
    fn hash<H: hash::Hasher>(&self, state: &mut H) {
        self.deref().hash(state)
    }
}

impl<A: Allocator> Drop for ThinString<A> {
    fn drop(&mut self) {
        if self.tagged_ptr.tag() != OWNED {
            return;
        }

        let storage = <&Storage>::from(&*self);
        let layout = Layout::for_value(storage);

        unsafe {
            self.allocator
                .deallocate(self.tagged_ptr.ptr().cast(), layout)
        };
    }
}

impl<'a, A: Allocator> From<&'a ThinString<A>> for &'a Storage {
    fn from(thin_string: &'a ThinString<A>) -> Self {
        let obj = thin_string.tagged_ptr.ptr();
        // SAFETY: todo
        unsafe { Storage::from_header(obj) }
    }
}
impl<const N: usize> From<&'static ConstStorage<N>> for ThinString<Global> {
    fn from(const_storage: &'static ConstStorage<N>) -> Self {
        Self::from_const_storage_in(const_storage, Global)
    }
}

impl<'a, A: Allocator> From<&'a ThinString<A>> for &'a str {
    fn from(thin_string: &'a ThinString<A>) -> Self {
        let storage: &Storage = thin_string.into();
        storage.deref()
    }
}

impl<A: Allocator> Deref for ThinString<A> {
    type Target = str;

    fn deref(&self) -> &Self::Target {
        let storage: &Storage = self.into();
        storage.deref()
    }
}

impl<A: Allocator> AsRef<str> for ThinString<A> {
    fn as_ref(&self) -> &str {
        self.deref()
    }
}

impl fmt::Display for ThinString<Global> {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        self.deref().fmt(f)
    }
}

impl From<&str> for ThinString<Global> {
    fn from(string: &str) -> Self {
        Self::from_str_in(string, Global)
    }
}

#[cfg(feature = "std")]
extern crate std;

#[cfg(feature = "std")]
mod ext {

    use std::string::String;

    use super::*;

    impl<A: Allocator + 'static> From<ThinString<A>> for std::borrow::Cow<'static, str> {
        fn from(thin_string: ThinString<A>) -> Self {
            if thin_string.tagged_ptr.tag() == OWNED {
                let string = std::string::String::from(thin_string.as_ref());
                std::borrow::Cow::Owned(string)
            } else {
                // SAFETY: if the string is borrowed, it lives in static memory.
                let str = unsafe { mem::transmute::<&str, &str>(thin_string.as_ref()) };
                std::borrow::Cow::Borrowed(str)
            }
        }
    }

    impl From<String> for ThinString<Global> {
        fn from(string: String) -> Self {
            ThinString::from_str_in(string.as_str(), Global)
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use allocator_api2::alloc::Global;

    #[test]
    fn test_thin_strings() {
        let strs = [
            "",
            "woof",
            "datadog",
            "She sells sea shells by the sea shore.",
            "The quick brown fox jumps over the lazy dog.",
            r#"
I can't remember
The best haiku in the world:
This is a tribute.
"#,
        ];

        for str in strs {
            let thin_string = ThinString::try_from_str_in(str, Global).unwrap();
            assert_eq!(thin_string.deref(), str);
            let thin_str = thin_string.as_thin_str();
            assert_eq!(thin_str.deref(), str);
        }
    }

    #[test]
    fn test_naughty_strings() {
        // Naughty strings are still valid UTF-8, let's make sure we don't
        // screw up their len and such.
        for str in naughty_strings::BLNS {
            let str = *str; // unwrap the &&
            let thin_string = ThinString::try_from_str_in(str, Global).unwrap();
            assert_eq!(thin_string.deref(), str);
            let thin_str = thin_string.as_thin_str();
            assert_eq!(thin_str.deref(), str);
        }
    }

    #[test]
    fn test_const_storage() {
        static DATADOG: ConstStorage<7> = ConstStorage::from_str("datadog");

        let thin_string = ThinString::from_const_storage_in(&DATADOG, Global);
        assert_eq!(thin_string.deref(), "datadog");
        let thin_str = thin_string.as_thin_str();
        assert_eq!(thin_str.deref(), "datadog");
    }

    #[test]
    fn test_from_str() {
        let str = "hello world";
        let thin_string = ThinString::from(str);
        assert_eq!(thin_string.deref(), "hello world");
    }

    #[cfg(feature = "std")]
    #[test]
    fn test_from_string() {
        let string = std::string::String::from("hello world");
        let thin_string = ThinString::from(string);
        assert_eq!(thin_string.deref(), "hello world");
    }
}
