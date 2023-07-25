use super::bump_owned::BorrowedStringTable;
use bumpalo::Bump;
use ouroboros::self_referencing;
use std::ops::Range;

#[self_referencing]
struct StringTableCell {
    owner: Bump,

    #[borrows(owner)]
    #[covariant]
    dependent: BorrowedStringTable<'this>,
}

pub struct OwnedStringTable {
    inner: StringTableCell,
}

impl OwnedStringTable {
    #[inline]
    pub fn new() -> Self {
        Self::with_capacity(4000)
    }

    #[inline]
    pub fn with_capacity(capacity: usize) -> Self {
        let arena = Bump::with_capacity(capacity);
        let inner = StringTableCell::new(arena, |arena| BorrowedStringTable::new(arena));
        Self { inner }
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
        self.inner.with_dependent(|table| table.len())
    }

    #[inline]
    fn insert_full(&mut self, str: &str) -> (usize, bool) {
        self.inner
            .with_dependent_mut(|table| table.insert_full(str))
    }

    #[inline]
    fn get_offset(&self, offset: usize) -> &str {
        self.inner.with_dependent(|table| table.get_offset(offset))
    }

    #[inline]
    fn get_range(&self, range: Range<usize>) -> &[&str] {
        self.inner.with_dependent(|table| table.get_range(range))
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
