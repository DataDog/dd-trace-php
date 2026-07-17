use std::ptr;

const PAGE_SHIFT: usize = 12;
const ALLOCATION_SHIFT: usize = 3;
const RADIX_BITS: usize = 9;
const RADIX_LEVELS: usize = 6;
const RADIX_ENTRIES: usize = 1 << RADIX_BITS;
const RADIX_MASK: u64 = RADIX_ENTRIES as u64 - 1;

struct RadixNode {
    entries: [*mut (); RADIX_ENTRIES],
}

impl RadixNode {
    fn new() -> Self {
        Self {
            entries: [ptr::null_mut(); RADIX_ENTRIES],
        }
    }
}

struct PageBitmap {
    words: [u64; 8],
}

impl PageBitmap {
    fn new() -> Self {
        Self { words: [0; 8] }
    }
}

pub(super) struct ShadowMap {
    root: Option<Box<RadixNode>>,
}

impl ShadowMap {
    pub(super) const fn new() -> Self {
        Self { root: None }
    }

    pub(super) fn set(&mut self, address: usize) {
        debug_assert_eq!(address & ((1 << ALLOCATION_SHIFT) - 1), 0);

        let page = (address >> PAGE_SHIFT) as u64;
        let mut node = self
            .root
            .get_or_insert_with(|| Box::new(RadixNode::new()))
            .as_mut() as *mut RadixNode;

        for level in (1..RADIX_LEVELS).rev() {
            let index = radix_index(page, level);
            // Safety: `node` starts in `self.root`; every subsequent node is a
            // boxed RadixNode retained by the map until it is dropped.
            let entry = unsafe { &mut (*node).entries[index] };
            if entry.is_null() {
                *entry = Box::into_raw(Box::new(RadixNode::new())).cast();
            }
            node = entry.cast();
        }

        let index = radix_index(page, 0);
        // Safety: same node ownership as above. Final-level entries exclusively
        // contain boxed PageBitmaps.
        let entry = unsafe { &mut (*node).entries[index] };
        if entry.is_null() {
            *entry = Box::into_raw(Box::new(PageBitmap::new())).cast();
        }
        let bitmap = unsafe { &mut *entry.cast::<PageBitmap>() };
        let (word, mask) = bitmap_position(address);
        bitmap.words[word] |= mask;
    }

    pub(super) fn take(&mut self, address: usize) -> bool {
        debug_assert_eq!(address & ((1 << ALLOCATION_SHIFT) - 1), 0);

        let page = (address >> PAGE_SHIFT) as u64;
        let mut node = match self.root.as_deref_mut() {
            Some(root) => root as *mut RadixNode,
            None => return false,
        };

        for level in (1..RADIX_LEVELS).rev() {
            // Safety: nodes are only mutated by the current thread and remain
            // boxed until the thread-local map is dropped.
            let entry = unsafe { (*node).entries[radix_index(page, level)] };
            if entry.is_null() {
                return false;
            }
            node = entry.cast();
        }

        let entry = unsafe { (*node).entries[radix_index(page, 0)] };
        if entry.is_null() {
            return false;
        }
        let bitmap = unsafe { &mut *entry.cast::<PageBitmap>() };
        let (word, mask) = bitmap_position(address);
        let tracked = bitmap.words[word] & mask != 0;
        bitmap.words[word] &= !mask;

        // ponytail: retain empty radix leaves; prune if long-lived heaps show
        // unbounded shadow metadata growth.
        tracked
    }
}

impl Drop for ShadowMap {
    fn drop(&mut self) {
        if let Some(mut root) = self.root.take() {
            // Safety: `set` builds exactly five node levels followed by boxed
            // PageBitmaps, all uniquely owned by this map.
            unsafe { drop_entries(&mut root, RADIX_LEVELS - 1) };
        }
    }
}

unsafe fn drop_entries(node: &mut RadixNode, node_levels: usize) {
    for entry in node.entries.iter_mut().filter(|entry| !entry.is_null()) {
        if node_levels == 0 {
            drop(Box::from_raw(entry.cast::<PageBitmap>()));
        } else {
            let mut child = Box::from_raw(entry.cast::<RadixNode>());
            drop_entries(&mut child, node_levels - 1);
        }
    }
}

#[inline]
fn radix_index(page: u64, level: usize) -> usize {
    ((page >> (level * RADIX_BITS)) & RADIX_MASK) as usize
}

#[inline]
fn bitmap_position(address: usize) -> (usize, u64) {
    let slot = (address & ((1 << PAGE_SHIFT) - 1)) >> ALLOCATION_SHIFT;
    (slot >> 6, 1 << (slot & 63))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn tracks_exact_pointer_bits() {
        let mut map = ShadowMap::new();
        let first = 0x1000;
        let adjacent = first + 8;
        let distant = usize::MAX & !7;

        map.set(first);
        map.set(adjacent);
        map.set(distant);

        assert!(map.take(first));
        assert!(!map.take(first));
        assert!(map.take(adjacent));
        assert!(map.take(distant));
        assert!(!map.take(0x2000));
    }
}
