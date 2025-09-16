// Copyright 2025-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0 OR BSD-3-Clause

use core::slice::Iter;
use std::hash::{Hash, Hasher};
use std::mem::{ManuallyDrop, MaybeUninit};
use std::ops::{Deref, Range};

pub struct InlineVec<T, const N: usize> {
    // This is intended to be on the stack, so large lengths don't make sense.
    // Picking u8 also ensures no wasted bytes if you use small types for T.
    len: u8,
    values: [MaybeUninit<T>; N],
}

const fn assert_capacity(n: usize) {
    if n > u8::MAX as usize {
        panic!("InlineVec only supports up to u8::MAX capacities");
    }
}

impl<T, const N: usize> Default for InlineVec<T, N> {
    fn default() -> Self {
        InlineVec::new()
    }
}

impl<T, const N: usize> InlineVec<T, N> {
    pub const fn new() -> Self {
        assert_capacity(N);
        Self {
            len: 0,
            // SAFETY: a MaybeUninit<[MaybeUninit<T>; N]> is the same as an
            //                       [MaybeUninit<T>; N] when len=0.
            values: unsafe { MaybeUninit::uninit().assume_init() },
        }
    }

    /// # Safety
    /// There must be unused capacity when called.
    pub const unsafe fn push_unchecked(&mut self, value: T) {
        self.as_mut_ptr().add(self.len()).write(value);
        self.len += 1;
    }

    pub const fn from<const M: usize>(values: [T; M]) -> Self {
        if N > u8::MAX as usize {
            panic!("InlineVec only supports up to u8::MAX capacities");
        }
        if M > N {
            panic!("InlineVec::new requires an array no larger than the underlying capacity");
        }

        // Steal the guts out of the array.
        let mut vec = Self::new();
        let src = values.as_ptr();
        let dst = vec.values.as_mut_ptr().cast();
        // SAFETY: can't be overlapping, we've stack-allocated dst.
        unsafe { core::ptr::copy_nonoverlapping(src, dst, M) };
        core::mem::forget(values);
        vec.len = M as u8;
        vec
    }

    pub const fn as_slice(&self) -> &[T] {
        // SAFETY: the first N items are initialized.
        unsafe { core::slice::from_raw_parts(self.as_ptr(), self.len()) }
    }

    // Not const yet, see Rust issue 137737.
    pub fn iter(&self) -> Iter<'_, T> {
        self.as_slice().iter()
    }

    pub const fn is_empty(&self) -> bool {
        self.len == 0
    }

    pub const fn len(&self) -> usize {
        self.len as usize
    }

    pub const fn as_ptr(&self) -> *const T {
        self.values.as_ptr().cast()
    }

    pub const fn as_mut_ptr(&mut self) -> *mut T {
        self.values.as_mut_ptr().cast()
    }

    pub const fn try_push(&mut self, value: T) -> Result<(), T> {
        if self.len as usize != N {
            // SAFETY: we've ensured there is unused capacity first.
            unsafe { self.push_unchecked(value) };
            Ok(())
        } else {
            Err(value)
        }
    }
}

impl<T, const N: usize> Deref for InlineVec<T, N> {
    type Target = [T];

    fn deref(&self) -> &Self::Target {
        self.as_slice()
    }
}

impl<T: core::fmt::Debug, const N: usize> core::fmt::Debug for InlineVec<T, N> {
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        self.deref().fmt(f)
    }
}

impl<T: Clone, const N: usize> Clone for InlineVec<T, N> {
    fn clone(&self) -> Self {
        let mut cloned = Self::new();
        cloned.clone_from(self);
        cloned
    }

    fn clone_from(&mut self, source: &Self) {
        if core::mem::needs_drop::<T>() {
            let base = self.as_mut_ptr();
            for i in 0..self.len() {
                // SAFETY: have exclusive access from &mut self, and we're
                // doing pointer math within bounds.
                unsafe { base.add(i).drop_in_place() }
            }
        }
        self.len = 0;
        for item in source.iter() {
            // SAFETY: N=N, so there's room, and we've cleared the vec already.
            unsafe { self.push_unchecked(item.clone()) }
        }
    }
}

impl<T: Copy, const N: usize> Copy for InlineVec<T, N> {}

pub struct InlineVecIter<T, const N: usize> {
    start: usize,
    vec: ManuallyDrop<InlineVec<T, N>>,
}

impl<T, const N: usize> InlineVecIter<T, N> {
    fn live_range(&self) -> Range<usize> {
        self.start..self.vec.len()
    }
}

impl<T, const N: usize> Drop for InlineVecIter<T, N> {
    fn drop(&mut self) {
        if core::mem::needs_drop::<T>() {
            let base = self.vec.as_mut_ptr();
            for i in self.live_range() {
                unsafe { base.add(i).drop_in_place() }
            }
        }
    }
}

impl<T, const N: usize> From<InlineVec<T, N>> for InlineVecIter<T, N> {
    fn from(vec: InlineVec<T, N>) -> Self {
        Self {
            start: 0,
            vec: ManuallyDrop::new(vec),
        }
    }
}

impl<T, const N: usize> Iterator for InlineVecIter<T, N> {
    type Item = T;

    fn next(&mut self) -> Option<Self::Item> {
        let live_range = self.live_range();
        if !live_range.is_empty() {
            let item = unsafe { self.vec.as_mut_ptr().add(live_range.start).read() };
            self.start += 1;
            Some(item)
        } else {
            None
        }
    }

    fn size_hint(&self) -> (usize, Option<usize>) {
        let len = self.live_range().len();
        (len, Some(len))
    }

    fn count(self) -> usize {
        self.live_range().len()
    }
}

impl<T, const N: usize> ExactSizeIterator for InlineVecIter<T, N> {
    fn len(&self) -> usize {
        self.live_range().len()
    }
}

impl<T, const N: usize> IntoIterator for InlineVec<T, N> {
    type Item = T;
    type IntoIter = InlineVecIter<T, N>;

    fn into_iter(self) -> Self::IntoIter {
        InlineVecIter::from(self)
    }
}

//
// impl<T, const N: usize> From<InlineVec<T, N>> for ArrayVec<T, N> {
//     fn from(vec: InlineVec<T, N>) -> Self {
//         ArrayVec::from_iter(InlineVecIter::from(vec))
//     }
// }

unsafe impl<T: Send, const N: usize> Send for InlineVec<T, N> {}
unsafe impl<T: Sync, const N: usize> Sync for InlineVec<T, N> {}

impl<T: Hash, const N: usize> Hash for InlineVec<T, N> {
    fn hash<H: Hasher>(&self, state: &mut H) {
        self.deref().hash(state)
    }
}

impl<T: PartialEq, const N: usize> PartialEq for InlineVec<T, N> {
    fn eq(&self, other: &Self) -> bool {
        self.deref().eq(other.deref())
    }
}

impl<T: Eq, const N: usize> Eq for InlineVec<T, N> {}

#[cfg(test)]
mod tests {
    use super::*;

    const TEST_INLINE_VEC: InlineVec<bool, 2> = {
        let mut vec = InlineVec::from([false]);
        if vec.try_push(true).is_err() {
            panic!("expected to be able push another item into the vec")
        }
        vec
    };

    #[test]
    fn test_inlinevec_const() {
        assert_eq!(TEST_INLINE_VEC.as_slice(), &[false, true]);

        let vec: Vec<_> = TEST_INLINE_VEC.iter().copied().collect();
        assert_eq!(vec.as_slice(), &[false, true]);
    }
}
