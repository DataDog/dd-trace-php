use super::bump_owned::BorrowedStringTable;
use bumpalo::Bump;
use self_cell::self_cell;
use std::ops::Range;

// todo: use ouroboros instead?
self_cell!(
    struct StringTableCell {
        owner: Bump,

        #[covariant]
        dependent: BorrowedStringTable,
    }
);

pub struct OwnedStringTable {
    inner: StringTableCell,
}

impl Clone for OwnedStringTable {
    fn clone(&self) -> Self {
        let bytes = self
            .inner
            .with_dependent(|arena, _table| arena.allocated_bytes());

        use super::StringTable as StringTableTrait;
        let mut table = OwnedStringTable::with_capacity(bytes);
        let len = self.len();
        table.reserve(len);
        for str in self.get_range(0..len) {
            table.insert(str);
        }
        table
    }
}

impl OwnedStringTable {
    #[inline]
    pub fn new() -> Self {
        Self::with_capacity(4000)
    }

    #[inline]
    pub fn with_capacity(capacity: usize) -> Self {
        let inner = StringTableCell::new(Bump::with_capacity(capacity), |arena| {
            BorrowedStringTable::new(arena)
        });
        Self { inner }
    }

    #[inline]
    fn reserve(&mut self, additional: usize) {
        self.inner.with_dependent_mut(|_arena, table| {
            table.set.vec.reserve(additional);
            table.set.map.reserve(additional);
        })
    }
}

impl Default for OwnedStringTable {
    fn default() -> Self {
        Self::new()
    }
}

impl super::StringTable for OwnedStringTable {
    #[inline]
    fn len(&self) -> usize {
        self.inner.with_dependent(|_arena, set| set.len())
    }

    #[inline]
    fn insert_full(&mut self, str: &str) -> (usize, bool) {
        self.inner
            .with_dependent_mut(|_arena, set| set.insert_full(str))
    }

    #[inline]
    fn get_offset(&self, offset: usize) -> &str {
        self.inner
            .with_dependent(|_arena, set| set.get_offset(offset))
    }

    #[inline]
    fn get_range(&self, range: Range<usize>) -> &[&str] {
        self.inner
            .with_dependent(|_arena, set| set.get_range(range))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// If this fails, bumpalo may have changed its allocation patterns, and
    /// [OwnedStringTable::new] may need adjusted.
    #[test]
    fn test_bump() {
        let arena = Bump::with_capacity(4000);
        assert_eq!(4096 - 64, arena.chunk_capacity());
    }

    #[test]
    fn owned_string_table() {
        // small size, to allow testing re-alloc.
        let set = OwnedStringTable::with_capacity(64);
        super::super::tests::basic(set);
    }
}
