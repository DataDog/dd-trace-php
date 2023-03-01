use bumpalo::{collections, Bump};
use std::ops::Range;

pub struct BorrowedStringTable<'b> {
    arena: &'b Bump,
    pub set: super::borrowed::BorrowedStringTable<'b>,
}

impl<'b> BorrowedStringTable<'b> {
    #[inline]
    pub fn new(arena: &'b Bump) -> Self {
        Self {
            arena,
            set: Default::default(),
        }
    }
}

impl<'b> super::StringTable for BorrowedStringTable<'b> {
    #[inline]
    fn len(&self) -> usize {
        self.set.len()
    }

    fn insert_full(&mut self, str: &str) -> (usize, bool) {
        match self.set.map.get(str) {
            None => {
                let owned = collections::String::from_str_in(str, self.arena);

                /* Consume the string but retain a reference to its data in
                 * the arena. The reference is valid as long as the arena
                 * doesn't get reset. This is partly the reason for the unsafe
                 * marker on `StringTable::new`.
                 */
                let bumped_str = owned.into_bump_str();

                let id = self.set.vec.len();
                self.set.vec.push(bumped_str);

                self.set.map.insert(bumped_str, id);
                assert_eq!(self.set.vec.len(), self.set.map.len());
                (id, true)
            }
            Some(offset) => (*offset, false),
        }
    }

    #[inline]
    fn get_offset(&self, offset: usize) -> &str {
        self.set.get_offset(offset)
    }

    #[inline]
    fn get_range(&self, range: Range<usize>) -> &[&str] {
        self.set.get_range(range)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    pub fn bump_owned_string_table() {
        // small size, to allow testing re-alloc.
        let bump = Bump::with_capacity(16);
        let set = BorrowedStringTable::new(&bump);
        super::super::tests::basic(set);
    }
}
