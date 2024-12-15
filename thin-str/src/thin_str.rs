// Copyright 2024-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

use super::{ConstStorage, Storage, ThinHeader, EMPTY};
use core::ops::Deref;
use core::{hash, marker, ptr};

/// A borrowed string which uses a "thin" pointer, so it uses fewer bytes than
/// a &str does. This requires the size to be stored elsewhere, so there are
/// trade-offs.
#[derive(Clone, Copy, Debug)]
pub struct ThinStr<'a> {
    /// Points to [Storage], except that we don't want a fat pointer (fat
    /// because of the DST), so we store a pointer to the header as raw bytes
    /// instead.
    ptr: ptr::NonNull<ThinHeader>,

    /// Since [ThinStr] acts like a reference type but doesn't hold one, we
    /// tell the compiler this info with a marker. This doesn't add any size
    /// to the struct.
    _marker: marker::PhantomData<&'a [u8]>,
}

impl ThinStr<'_> {
    /// Creates an empty ThinStr.
    pub fn new() -> Self {
        Self::from_const_storage(&EMPTY)
    }

    /// Creates a ThinStr from the provided pointer, but it must be a pointer
    /// to a valid [Storage] object.
    /// # Safety
    /// The thin pointer should point to a valid [Storage] object.
    pub const unsafe fn from_raw(ptr: ptr::NonNull<ThinHeader>) -> Self {
        let _marker = marker::PhantomData;
        Self { ptr, _marker }
    }

    /// Creates a ThinStr from a &[ConstStorage], which will usually be in
    /// static storage.
    pub const fn from_const_storage<const N: usize>(const_storage: &ConstStorage<N>) -> Self {
        let ptr = {
            let obj = const_storage.as_storage() as *const _ as *const ThinHeader;
            // SAFETY: this is just NonNull:from(&ConstStorage) with casts,
            // it's just that From::from isn't a const fn.
            unsafe { ptr::NonNull::new_unchecked(obj.cast_mut()) }
        };
        // SAFETY: ConstStorage objects are valid Storage objects.
        unsafe { Self::from_raw(ptr) }
    }
}

impl From<usize> for ThinHeader {
    fn from(value: usize) -> Self {
        let size = value.to_ne_bytes();
        Self { size }
    }
}

impl Default for ThinStr<'_> {
    fn default() -> Self {
        ThinStr::new()
    }
}

impl<'a> From<ThinStr<'a>> for &'a Storage {
    fn from(thin_str: ThinStr<'a>) -> &'a Storage {
        // SAFETY: the lifetime is accurate, and ThinStr points to a valid
        // Storage object, and no mutable references ever exist to Storage
        // (it is immutable).
        unsafe { Storage::from_header(thin_str.ptr.cast::<ThinHeader>()) }
    }
}

impl<'a> From<&ThinStr<'a>> for &'a Storage {
    fn from(thin_str: &ThinStr<'a>) -> &'a Storage {
        <&'a Storage>::from(*thin_str)
    }
}

impl<'a> From<&'a Storage> for ThinStr<'a> {
    fn from(storage: &'a Storage) -> ThinStr<'a> {
        let obj = ptr::NonNull::from(storage).cast::<ThinHeader>();
        // SAFETY: pointer to Storage conforms to layout required for ThinStr.
        unsafe { ThinStr::from_raw(obj) }
    }
}

impl Deref for ThinStr<'_> {
    type Target = str;

    fn deref(&self) -> &Self::Target {
        let storage: &Storage = self.into();
        storage.deref()
    }
}

unsafe impl Send for ThinStr<'static> {}

impl PartialEq for ThinStr<'_> {
    fn eq(&self, other: &Self) -> bool {
        self.deref() == other.deref()
    }
}

impl Eq for ThinStr<'_> {}

impl hash::Hash for ThinStr<'_> {
    fn hash<H: hash::Hasher>(&self, state: &mut H) {
        self.deref().hash(state)
    }
}

impl AsRef<str> for ThinStr<'_> {
    fn as_ref(&self) -> &str {
        self.deref()
    }
}

// This allows using &str as a key when looking up ThinStr keys.
impl core::borrow::Borrow<str> for ThinStr<'_> {
    fn borrow(&self) -> &str {
        self.deref()
    }
}

#[cfg(test)]
mod test {
    use super::*;

    #[test]
    fn test_empty_str() {
        let empty = ThinStr::new();
        assert_eq!(empty.deref(), "");

        let storage: &Storage = empty.into();
        assert_eq!(storage.deref(), "");
    }

    #[test]
    fn test_non_empty_str() {
        const PHP_OPEN_STR: &str = "<?php";
        static PHP_OPEN: ConstStorage<5> = ConstStorage::from_str(PHP_OPEN_STR);
        let thin = ThinStr::from_const_storage(&PHP_OPEN);
        assert_eq!(thin.deref(), PHP_OPEN_STR);

        let storage: &Storage = thin.into();
        assert_eq!(storage.deref(), PHP_OPEN_STR);
    }
}
