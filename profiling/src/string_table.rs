use ahash::RandomState;
use bumpalo::{collections, Bump};
use ouroboros::self_referencing;
use std::collections::HashMap;
use std::ops::Range;

// ouroboros will add a lot of functions to this struct, which we don't want
// to expose publicly, hence why the guts are wrapped into a private object.
#[self_referencing]
struct StringTableCell {
    owner: Bump,

    #[borrows(owner)]
    #[covariant]
    dependent: BorrowedStringTable<'this>,
}

pub struct StringTable {
    inner: StringTableCell,
}

impl StringTable {
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

    #[allow(unused)]
    #[inline]
    pub fn len(&self) -> usize {
        self.inner.with_dependent(|table| table.len())
    }

    #[allow(unused)]
    #[inline]
    pub fn is_empty(&self) -> bool {
        self.len() == 0
    }

    #[inline]
    pub fn insert(&mut self, str: &str) -> usize {
        self.insert_full(str).0
    }

    #[inline]
    pub fn insert_full(&mut self, str: &str) -> (usize, bool) {
        self.inner
            .with_dependent_mut(|table| table.insert_full(str))
    }

    #[inline]
    pub fn get_offset(&self, offset: usize) -> &str {
        self.inner.with_dependent(|table| table.get_offset(offset))
    }

    #[allow(unused)]
    #[inline]
    pub fn get_range(&self, range: Range<usize>) -> &[&str] {
        self.inner.with_dependent(|table| table.get_range(range))
    }
}

impl Default for StringTable {
    fn default() -> Self {
        Self::new()
    }
}

struct BorrowedStringTable<'b> {
    arena: &'b Bump,
    vec: Vec<&'b str>,
    map: HashMap<&'b str, usize, RandomState>,
}

impl<'b> BorrowedStringTable<'b> {
    #[inline]
    fn new(arena: &'b Bump) -> Self {
        let mut table = Self {
            arena,
            vec: Default::default(),
            map: Default::default(),
        };

        // string tables always have the empty string
        table.insert("");
        table
    }

    #[inline]
    fn len(&self) -> usize {
        self.vec.len()
    }

    #[inline]
    fn insert(&mut self, str: &str) -> usize {
        self.insert_full(str).0
    }

    fn insert_full(&mut self, str: &str) -> (usize, bool) {
        match self.map.get(str) {
            None => {
                let owned = collections::String::from_str_in(str, self.arena);

                /* Consume the string but retain a reference to its data in
                 * the arena. The reference is valid as long as the arena
                 * doesn't get reset. This is partly the reason for the unsafe
                 * marker on `StringTable::new`.
                 */
                let bumped_str = owned.into_bump_str();

                let id = self.vec.len();
                self.vec.push(bumped_str);

                self.map.insert(bumped_str, id);
                assert_eq!(self.vec.len(), self.map.len());
                (id, true)
            }
            Some(offset) => (*offset, false),
        }
    }

    #[inline]
    fn get_offset(&self, offset: usize) -> &str {
        self.vec[offset]
    }

    #[allow(unused)]
    #[inline]
    fn get_range(&self, range: Range<usize>) -> &[&str] {
        &self.vec[range]
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// Pass in an empty set, which should only include the empty string at 0.
    pub fn basic(mut set: StringTable) {
        // the empty string must always be included in the set at 0.
        let empty_str = set.get_offset(0);
        assert_eq!("", empty_str);

        let cases = &[
            (0, ""),
            (1, "local root span id"),
            (2, "span id"),
            (3, "trace endpoint"),
            (4, "samples"),
            (5, "count"),
            (6, "wall-time"),
            (7, "nanoseconds"),
            (8, "cpu-time"),
            (9, "<?php"),
            (10, "/srv/demo/public/index.php"),
            (11, "pid"),
        ];

        for (offset, str) in cases.iter() {
            let actual_offset = set.insert(str);
            assert_eq!(*offset, actual_offset);
        }

        // repeat them to ensure they aren't re-added
        for (offset, str) in cases.iter() {
            let actual_offset = set.insert(str);
            assert_eq!(*offset, actual_offset);
        }

        // let's fetch some offsets
        assert_eq!("", set.get_offset(0));
        assert_eq!("/srv/demo/public/index.php", set.get_offset(10));

        // Check a range too
        let slice = set.get_range(7..10);
        let expected_slice = &["nanoseconds", "cpu-time", "<?php"];
        assert_eq!(expected_slice, slice);
    }

    /// If this fails, bumpalo may have changed its allocation patterns, and
    /// [StringTable::new] may need adjusted.
    #[test]
    fn test_bump() {
        let arena = Bump::with_capacity(4000);
        assert_eq!(4096 - 64, arena.chunk_capacity());
    }

    #[test]
    fn owned_string_table() {
        // small size, to allow testing re-alloc.
        let set = StringTable::with_capacity(64);
        basic(set);
    }
}
