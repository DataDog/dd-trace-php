// Copyright 2024-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

#![no_std]

mod thin_str;
mod thin_string;

use core::ops::Deref;
use core::{mem, ptr};

pub use crate::thin_str::*;
pub use crate::thin_string::*;

/// [Storage] is the data layout that [ThinStr] and [ThinString] point at.
/// The data is immutable after construction to avoid accidentally creating
/// mutable references.
#[repr(C)]
pub struct Storage {
    header: ThinHeader,
    /// The bytes of the strings are stored here. They need to be a valid str.
    data: str,
}

/// The alignment is so that tagged pointers can use the least significant bit
/// for storing other things if they wish. However, it's intentionally minimal
/// so that strings can be packed tightly into arenas. Wasting a single
/// byte when there's string with an odd len is fine--C strings waste a byte
/// on the null terminator all the time.
#[repr(C, align(2))]
#[derive(Clone, Copy)]
pub struct ThinHeader {
    /// Create with [usize::to_ne_bytes] and restore it with
    /// [usize::from_ne_bytes].
    size: [u8; mem::size_of::<usize>()],
}

/// Represents a [Storage] with a known-at-compile-time len for the str.
#[repr(C)]
#[derive(Clone, Copy)]
pub struct ConstStorage<const N: usize> {
    header: ThinHeader,
    data: [u8; N],
}

pub static EMPTY: ConstStorage<0> = ConstStorage::from_str("");

impl Storage {
    /// # Safety
    /// - The pointer to the ThinHeader needs to actually be a thin pointer to
    ///   a valid Storage object, which includes that it is properly aligned
    ///   and initialized.
    /// - No mutable references should exist to the storage.
    /// - The lifetime needs to ensure that the storage lives at least this
    ///   long.
    const unsafe fn from_header<'a>(header_ptr: ptr::NonNull<ThinHeader>) -> &'a Storage {
        let obj = header_ptr.cast::<u8>().as_ptr();
        let len = {
            // SAFETY: based on this function's own safety requirements, this
            // should already be 1) valid for reads, 2) properly aligned, and
            // 3) properly initialized.
            let header = unsafe { header_ptr.as_ptr().read() };
            usize::from_ne_bytes(header.size)
        };

        // Weird trick to get a fat pointer. The metadata from the slice will
        // get used to create the metadata in the fat Storage pointer. This
        // weird cast is documented:
        // https://github.com/rust-lang/reference/blob/d6d24b9b548f62a50461bac85ce278d80437ab05/src/expressions/operator-expr.md?plain=1#L555
        let fat = ptr::slice_from_raw_parts(obj, len) as *const Storage;

        // SAFETY: based on this function's own safety requirements, this is
        // safe to turn into a reference.
        unsafe { &*fat }
    }
}

impl<'a> From<&'a Storage> for &'a str {
    fn from(storage: &'a Storage) -> &'a str {
        &storage.data
    }
}

impl Deref for Storage {
    type Target = str;

    fn deref(&self) -> &Self::Target {
        &self.data
    }
}

impl<const N: usize> ConstStorage<N> {
    pub const fn from_str(str: &str) -> ConstStorage<N> {
        if str.len() != N {
            panic!("string length mismatch");
        }
        let size = N.to_ne_bytes();
        let header = ThinHeader { size };
        let data = unsafe { ptr::read(str.as_ptr().cast::<[u8; N]>()) };
        ConstStorage::<N> { header, data }
    }

    pub const fn as_storage(&self) -> &Storage {
        let header_ptr = {
            let ptr = self as *const Self as *const ThinHeader;
            // SAFETY: Storage is immutable, and self means the data is alive
            // and not null.
            unsafe { ptr::NonNull::new_unchecked(ptr.cast_mut()) }
        };
        // SAFETY: the layouts of Storage and ConstStorage are compatible,
        // ConstStorage only holds valid strs, and the lifetime is valid.
        unsafe { Storage::from_header(header_ptr) }
    }
}
