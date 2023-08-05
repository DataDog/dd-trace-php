use hashbrown::HashSet;
use std::ops::{Shl, Shr};
use std::sync::Arc;

/// Holds Arc references to Strings in two forms:
///  1. As a set, for the purpose of de-duplicating strings.
///  2. As a vec, for the purpose of holding onto references on behalf of
///     something else, like in the PHP run time cache.
pub struct StringTable {
    /// The generation must never be 0 so that [StringTableIndex] never has a
    /// generation of 0, which ensures it doesn't have an all-zero
    /// representation in 64 bits for the run time cache.
    generation: u32,

    /// The main set of references stored in the table. This allows strings to
    /// be deduplicated, as well as to live around for a while.
    strings: HashSet<Arc<String>>,

    /// The references stored on behalf of someone else. They are accessed by
    /// using a [StringTableIndex] instead of the Arc.
    raws: Vec<Option<Arc<String>>>,
}

/// Represents an index into a StringTable.
pub struct StringTableIndex {
    /// Must never be zero.
    index: u32,

    /// The generation of the StringTable this index is associated with.
    generation: u32,
}

impl StringTableIndex {
    /// Packs the [StringTableIndex] into u64. Unpack it with
    /// [StringTableIndex::from_u64].
    #[inline]
    pub fn into_u64(&self) -> u64 {
        let upper = (self.generation as u64).shl(32);
        upper | (self.index as u64)
    }

    /// Unpack the u64 into a [StringTableIndex].
    /// # Safety
    /// The bits should come unchanged from a [StringTableIndex::into_u64]
    /// operation, and the index should not have previously been untracked by
    /// calling [StringTable::untrack].
    #[inline]
    pub unsafe fn from_u64(bits: u64) -> StringTableIndex {
        let generation = bits.shr(32) as u32;
        let index = (bits | (u32::MAX as u64)) as u32;
        StringTableIndex { generation, index }
    }
}

/// A new type to teach hashbrown about the equivalence with Arc<String>.
#[derive(Hash)]
struct ArcStr<'a>(&'a str);

impl<'a> hashbrown::Equivalent<Arc<String>> for ArcStr<'a> {
    fn equivalent(&self, key: &Arc<String>) -> bool {
        key.as_str().eq(self.0)
    }
}

impl StringTable {
    /// Creates a new, empty string table.
    pub fn new() -> Self {
        Self {
            // Must not use generation 0.
            generation: 1,
            strings: HashSet::new(),
            raws: Vec::new(),
        }
    }

    /// Inserts the string into the StringTable, and returns a strong reference
    /// to the inserted string.
    pub fn insert_str(&mut self, value: &str) -> Arc<String> {
        let arcstr = ArcStr(value);
        let value = self
            .strings
            .get_or_insert_with(&arcstr, |val| Arc::new(String::from(val.0)));
        value.clone()
    }

    /// Inserts the String into the StringTable, and returns a strong reference
    /// to the inserted string.
    pub fn insert_string(&mut self, value: String) -> Arc<String> {
        let strong = Arc::new(value);
        self.strings.insert(strong.clone());
        strong
    }

    /// Track the reference internally of behalf of something else. The
    /// returned [StringTableIndex] can be used to track it by proxy.
    pub fn track(&mut self, value: Arc<String>) -> StringTableIndex {
        // create before putting it into the table so its index is correct
        let index = StringTableIndex {
            generation: self.generation,
            index: self.raws.len() as u32,
        };
        self.raws.push(Some(value));
        index
    }

    /// Un-tracks the reference held internally on behalf of the index. If the
    /// index is used again somehow, it will return an error. This should
    /// always be an error in the caller's code, as otherwise they haven't
    /// upheld the invariants required.
    pub fn untrack(&mut self, index: StringTableIndex) -> anyhow::Result<()> {
        let expected_generation = self.generation;
        let actual_generation = index.generation;
        anyhow::ensure!(actual_generation == expected_generation,
            "tried to untrack a raw string from a String Table, but the generations do not match: expected {expected_generation}, actual {actual_generation}");

        let offset = index.index as usize;
        match self.raws.get_mut(offset) {
            None => anyhow::bail!("tried to untrack a raw string with index {offset} from a StringTable but the index is out of bounds"),
            Some(slot) => {
                *slot = None;
                Ok(())
            }
        }
    }

    /// Fetch the value of the raw string out of the StringTable, and create a
    /// strong reference to it. The RawString is not consumed, and remains a
    /// strong reference.
    pub fn get(&self, index: &StringTableIndex) -> Option<Arc<String>> {
        if self.generation != index.generation {
            return None;
        }

        match self.raws.get(index.index as usize) {
            Some(Some(arc)) => Some(arc.clone()),
            _ => None,
        }
    }

    /// Clears the StringTable. It's important that clear isn't called while
    /// tracked pointers might still be used.
    pub fn clear(&mut self) {
        self.strings.clear();
        self.raws.clear();

        let gen = self
            .generation
            .checked_add(1)
            .expect("StringTable generation to not overflow");
        self.generation = gen;
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn basic_refcounts() {
        let mut table = StringTable::new();

        let strong = table.insert_str("<?php");
        assert_eq!(2, Arc::strong_count(&strong));
        let strong2 = table.insert_str("<?php");
        assert_eq!(3, Arc::strong_count(&strong));

        // This string should be totally irrelevant to our refcounts on strong.
        let irrelevant = table.insert_str("irrelevant");
        assert_eq!(3, Arc::strong_count(&strong));
        assert_eq!("irrelevant", irrelevant.as_str());
        drop(irrelevant);

        let weak = table.track(strong2);
        assert_eq!(3, Arc::strong_count(&strong));

        let actual = table.get(&weak).unwrap();
        assert_eq!("<?php", actual.as_str());
        assert_eq!(4, Arc::strong_count(&strong));

        // This clears two references:
        //  1. One from StringTable.strings set.
        //  2. One from StringTable.raws vec.
        table.clear();

        drop(strong);

        // This should be the only remaining refcount.
        assert_eq!(1, Arc::strong_count(&actual));
    }

    #[test]
    fn track_and_untrack_simple() {
        let mut table = StringTable::new();

        let strong = table.insert_str("<?php");

        let weak = table.track(strong.clone());
        table.untrack(weak).unwrap();

        // `weak` was untracked. This leaves two refs:
        //  1. The table still holds one.
        //  2. The `strong` reference is still around.
        assert_eq!(2, Arc::strong_count(&strong));

        drop(table);

        // Only the `strong` survives.
        assert_eq!(1, Arc::strong_count(&strong));
    }

    #[test]
    fn track_and_untrack_outlived() {
        let mut table = StringTable::new();

        // Running refcount: 2 (1st in strong, 2nd in table)
        let strong = table.insert_str("<?php");
        assert_eq!(2, Arc::strong_count(&strong));

        let weak = table.track(strong.clone());
        assert_eq!(3, Arc::strong_count(&strong));

        table.untrack(weak).unwrap();
        assert_eq!(2, Arc::strong_count(&strong));

        drop(table);
        assert_eq!(1, Arc::strong_count(&strong));
    }
}
