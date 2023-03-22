use std::ops::Range;

mod borrowed;
mod bump_owned;
mod owned;

pub use borrowed::*;
pub use owned::*;

pub trait StringTable {
    fn len(&self) -> usize;

    #[inline]
    fn is_empty(&self) -> bool {
        self.len() == 0
    }

    #[inline]
    fn insert(&mut self, item: &str) -> usize {
        self.insert_full(item).0
    }

    fn insert_full(&mut self, item: &str) -> (usize, bool);

    fn get_offset(&self, offset: usize) -> &str;
    fn get_range(&self, range: Range<usize>) -> &[&str];
}

#[cfg(test)]
mod tests {
    use super::*;

    /// Pass in an empty set, which should only include the empty string at 0.
    pub fn basic<S: StringTable>(mut set: S) {
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
}
