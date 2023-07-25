use ahash::RandomState;
use std::collections::HashMap;
use std::ops::Range;

pub struct BorrowedStringTable<'a> {
    pub vec: Vec<&'a str>,
    pub map: HashMap<&'a str, usize, RandomState>,
}

impl<'a> Default for BorrowedStringTable<'a> {
    fn default() -> Self {
        /// The initial size of the Vec. At the time of writing, Vec would
        /// choose size 4. This is expected to be much too small for the
        /// use-case, so use a larger initial capacity to save a few
        /// re-allocations in the beginning.
        /// This is just an educated estimate, not a finely tuned value.
        const INITIAL_VEC_CAPACITY: usize = 1024 / std::mem::size_of::<&str>();

        /// A HashMap is less straight-forward, but it uses more memory for
        /// the same number of elements compared to a Vec, but not twice as
        /// much for our situation, so dividing by 2 should be okay, at least
        /// until further measurement is done.
        const INITIAL_MAP_CAPACITY: usize = INITIAL_VEC_CAPACITY / 2;

        let mut vec = Vec::with_capacity(INITIAL_VEC_CAPACITY);
        vec.push("");
        let mut map = HashMap::with_capacity_and_hasher(INITIAL_MAP_CAPACITY, Default::default());
        map.insert("", 0);
        Self { vec, map }
    }
}

impl<'a> super::StringTable for BorrowedStringTable<'a> {
    #[inline]
    fn len(&self) -> usize {
        self.vec.len()
    }

    #[inline]
    fn is_empty(&self) -> bool {
        self.vec.is_empty()
    }

    fn insert_full(&mut self, str: &str) -> (usize, bool) {
        match self.map.get(str) {
            None => {
                let id = self.vec.len();
                // Safety: DEFINITELY NOT SAFE. The caller _must_
                let borrowed = unsafe { std::mem::transmute(str) };
                self.vec.push(borrowed);

                self.map.insert(borrowed, id);
                debug_assert_eq!(self.map.len(), self.vec.len());
                (id, true)
            }
            Some(offset) => (*offset, false),
        }
    }

    #[inline]
    fn get_offset(&self, offset: usize) -> &str {
        self.vec[offset]
    }

    #[inline]
    fn get_range(&self, range: Range<usize>) -> &[&str] {
        &self.vec[range]
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    pub fn borrowed_string_table() {
        let set = BorrowedStringTable::<'static>::default();
        super::super::tests::basic(set);
    }
}
