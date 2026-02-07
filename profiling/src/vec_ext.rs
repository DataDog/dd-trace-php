// Copyright 2025-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0 OR BSD-3-Clause

use std::collections::TryReserveError;
use std::ptr;

mod sealed {
    pub trait Sealed {}

    impl<T> Sealed for Vec<T> {}
}

pub trait VecExt<T>: sealed::Sealed {
    fn try_push(&mut self, val: T) -> Result<(), TryReserveError>;

    /// Appends all elements from `src` into `self`, using fallible
    /// allocation. This is similar to [`Vec::extend_from_slice`] except it
    /// returns an error instead of panicking on allocation failure.
    #[allow(unused)]
    fn try_extend_from_slice(&mut self, src: &[T]) -> Result<(), TryReserveError>
    where
        T: Copy;
}

impl<T> VecExt<T> for Vec<T> {
    /// Use [Vec::try_reserve] to reserve space for the additional item, and
    /// then write the item in the new slot. This is similar to [Vec::push]
    /// except it will fail if the collection cannot grow rather than panic.
    fn try_push(&mut self, value: T) -> Result<(), TryReserveError> {
        let len = self.len();
        self.try_reserve(1)?;
        // SAFETY: try_reserve ensures there is at least one item of capacity.
        let end = unsafe { self.as_mut_ptr().add(len) };
        // SAFETY: ensured by the Vec's own invariants, and that we are
        // writing into an initialized slot.
        unsafe { ptr::write(end, value) };
        // SAFETY: the len is less than or equal to the capacity due to the
        // try_reserve, and we initialized the new slot with the ptr::write.
        unsafe { self.set_len(len + 1) };
        Ok(())
    }

    fn try_extend_from_slice(&mut self, src: &[T]) -> Result<(), TryReserveError>
    where
        T: Copy,
    {
        let old_len = self.len();
        self.try_reserve(src.len())?;
        // SAFETY: try_reserve guarantees old_len + src.len() <= capacity.
        // T: Copy, so a bytewise copy is valid and no drop concerns exist.
        unsafe {
            ptr::copy_nonoverlapping(src.as_ptr(), self.as_mut_ptr().add(old_len), src.len());
            self.set_len(old_len + src.len());
        }
        Ok(())
    }
}
