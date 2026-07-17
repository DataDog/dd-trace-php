#[path = "live_heap/shadow.rs"]
mod shadow;

use dashmap::DashMap;
use rustc_hash::FxBuildHasher;
use shadow::ShadowMap;
use std::sync::atomic::{AtomicUsize, Ordering};

/// Maximum number of allocations to track for live heap profiling.
const MAX_SIZE: usize = 4096;

/// Tracks live heap samples by allocation address. FxHasher spreads sequential
/// ZendMM addresses across DashMap's shards without hashing already-randomized
/// pointer bytes with SipHash.
pub(crate) struct LiveHeapTracker<T> {
    allocations: DashMap<usize, T, FxBuildHasher>,
    count: AtomicUsize,
}

impl<T> LiveHeapTracker<T> {
    pub(crate) fn new() -> Self {
        Self {
            allocations: DashMap::with_hasher(FxBuildHasher),
            count: AtomicUsize::new(0),
        }
    }

    pub(crate) fn len(&self) -> usize {
        self.count.load(Ordering::Relaxed)
    }

    pub(crate) fn clear(&self) {
        self.allocations.clear();
        self.count.store(0, Ordering::Relaxed);
    }

    fn track(&self, ptr: usize, sample: T) -> bool {
        // Best-effort cap: in ZTS the count check and insert still race, so
        // the map can briefly exceed MAX_SIZE.
        if self.len() >= MAX_SIZE {
            return false;
        }

        if self.allocations.insert(ptr, sample).is_none() {
            self.count.fetch_add(1, Ordering::Relaxed);
        }
        true
    }

    fn untrack(&self, ptr: usize) -> Option<T> {
        let result = self.allocations.remove(&ptr).map(|(_, sample)| sample);
        if result.is_some() {
            self.count.fetch_sub(1, Ordering::Relaxed);
        }
        result
    }
}

impl<T: Clone> LiveHeapTracker<T> {
    pub(crate) fn snapshot(&self) -> Vec<T> {
        self.allocations
            .iter()
            .map(|entry| entry.value().clone())
            .collect()
    }
}

impl<T> Default for LiveHeapTracker<T> {
    fn default() -> Self {
        Self::new()
    }
}

pub(crate) struct LocalLiveHeapTracker {
    shadow: ShadowMap,
}

impl LocalLiveHeapTracker {
    pub(crate) const fn new() -> Self {
        Self {
            shadow: ShadowMap::new(),
        }
    }

    pub(crate) fn track<T>(&mut self, tracker: &LiveHeapTracker<T>, ptr: usize, sample: T) -> bool {
        if !tracker.track(ptr, sample) {
            return false;
        }
        self.shadow.set(ptr);
        true
    }

    pub(crate) fn untrack<T>(&mut self, tracker: &LiveHeapTracker<T>, ptr: usize) -> Option<T> {
        self.shadow
            .take(ptr)
            .then(|| tracker.untrack(ptr))
            .flatten()
    }
}

impl Default for LocalLiveHeapTracker {
    fn default() -> Self {
        Self::new()
    }
}
