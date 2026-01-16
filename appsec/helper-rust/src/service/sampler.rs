use std::sync::atomic::{AtomicBool, AtomicU64, AtomicUsize, Ordering};
use std::sync::Arc;
use std::time::{Instant, SystemTime, UNIX_EPOCH};

const MAX_ITEMS: usize = 4096;
const CAPACITY: usize = 8192;

#[derive(Clone)]
pub(super) struct SchemaSampler {
    inner: Arc<SchemaSamplerInner>,
}

impl SchemaSampler {
    pub(super) fn new(sampling_period_secs: u32) -> Self {
        let start_time = Instant::now();
        let now_secs = Self::current_time_secs();

        Self {
            inner: Arc::new(SchemaSamplerInner {
                table: crossbeam_epoch::Atomic::new(Table::new()),
                rebuild_in_progress: AtomicBool::new(false),
                threshold: sampling_period_secs,
                time_bias: now_secs.saturating_sub(sampling_period_secs),
                _start_time: start_time,
            }),
        }
    }

    pub(super) fn should_sample(&self, key: u64) -> bool {
        let guard = crossbeam_epoch::pin();
        // Use Acquire here to ensure that the table is seen at least as
        // it was when it was fully constructed. If not, garbage data could make
        // us select a slot too advanced for a given key.
        let table_ptr = self.inner.table.load(Ordering::Acquire, &guard);
        let table = unsafe { table_ptr.as_ref().unwrap() };

        self.hit(table, key)
    }

    fn current_time_secs() -> u32 {
        SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .unwrap()
            .as_secs() as u32
    }

    fn hit(&self, table: &Table, number: u64) -> bool {
        let now = Self::current_time_secs().wrapping_sub(self.inner.time_bias);
        let report_threshold = now.wrapping_sub(self.inner.threshold);

        'another_slot: loop {
            let (entry, exists) = table.find_slot(number);

            if !exists {
                let old_size = table.size.fetch_add(1, Ordering::Relaxed);

                if old_size >= MAX_ITEMS
                    && self
                        .inner
                        .rebuild_in_progress
                        .compare_exchange(false, true, Ordering::Relaxed, Ordering::Relaxed)
                        .is_ok()
                {
                    let sampler = self.clone();
                    let report_threshold_copy = report_threshold;
                    std::thread::spawn(move || {
                        sampler.rebuild_table(report_threshold_copy);
                    });
                }

                if old_size >= CAPACITY {
                    // no space to add anything
                    table.size.fetch_sub(1, Ordering::Relaxed);
                    return false;
                }

                match entry
                    .key
                    .compare_exchange(0, number, Ordering::Relaxed, Ordering::Relaxed)
                {
                    Ok(_) => {
                        let desired_data = EntryData {
                            last_accessed: now,
                            last_reported: now,
                        };

                        match entry.compare_exchange_data(
                            EntryData {
                                last_accessed: 0,
                                last_reported: 0,
                            },
                            desired_data,
                            Ordering::Relaxed,
                            Ordering::Relaxed,
                        ) {
                            Ok(_) => return true,
                            Err(_) => {
                                // though we created the entry, another thread updated the data
                                return false;
                            }
                        }
                    }
                    Err(exp_number) => {
                        table.size.fetch_sub(1, Ordering::Relaxed);
                        if exp_number == number {
                            // another thread inserted the same number
                            // presumably between find_slot() and the CAS only a very
                            // small amount of time passed
                            return false;
                        }
                        continue 'another_slot;
                    }
                }
            }

            let mut cur_data = entry.load_data(Ordering::Relaxed);

            if cur_data.last_reported <= report_threshold {
                // potentially a hit
                let desired_data = EntryData {
                    last_accessed: now,
                    last_reported: now,
                };

                loop {
                    match entry.compare_exchange_data(
                        cur_data,
                        desired_data,
                        Ordering::Relaxed,
                        Ordering::Relaxed,
                    ) {
                        Ok(_) => return true,
                        Err(new_data) => {
                            // another thread just updated it
                            cur_data = new_data;
                            // was it a hit?
                            if cur_data.last_accessed == cur_data.last_reported {
                                // then this one should not be a hit
                                return false;
                            }

                            // the other thread did not register a hit
                            // we retry if our idea of time is ahead
                            if cur_data.last_accessed < now {
                                continue;
                            }
                            // otherwise we just return false
                            return false;
                        }
                    }
                }
            } else {
                // we just update the last accessed time
                let desired_data = EntryData {
                    last_accessed: now,
                    last_reported: cur_data.last_reported,
                };

                loop {
                    if cur_data.last_accessed >= now {
                        // we're behind the times
                        return false;
                    }

                    match entry.compare_exchange_data(
                        cur_data,
                        desired_data,
                        Ordering::Relaxed,
                        Ordering::Relaxed,
                    ) {
                        Ok(_) => return false,
                        Err(new_data) => {
                            cur_data = new_data;
                        }
                    }
                }
            }
        }
    }

    fn rebuild_table(&self, report_threshold: u32) {
        let guard = crossbeam_epoch::pin();
        let old_table_ptr = self.inner.table.load(Ordering::Acquire, &guard);
        let old_table = unsafe { old_table_ptr.as_ref().unwrap() };

        let new_table = Table::new();

        #[derive(Clone, Copy)]
        struct CopiableEntry {
            key: u64,
            data: EntryData,
        }

        let mut entries: Vec<CopiableEntry> = Vec::with_capacity(CAPACITY);

        for slot in 0..CAPACITY {
            let entry = &old_table.entries[slot];
            let key = entry.key.load(Ordering::Relaxed);
            let data = entry.load_data(Ordering::Relaxed);

            if key != 0 && data.last_reported >= report_threshold {
                entries.push(CopiableEntry { key, data });
            }
        }

        // most recent at the top
        entries.sort_by(|a, b| b.data.last_accessed.cmp(&a.data.last_accessed));

        let count = std::cmp::min(entries.len(), MAX_ITEMS * 2 / 3);
        for ce in entries.into_iter().take(count) {
            let (new_entry, _) = new_table.find_slot(ce.key);
            new_entry.key.store(ce.key, Ordering::Relaxed);
            new_entry.store_data(ce.data, Ordering::Relaxed);
        }
        new_table.size.store(count, Ordering::Relaxed);

        let new_table_owned = crossbeam_epoch::Owned::new(new_table);
        let old_ptr = self
            .inner
            .table
            .swap(new_table_owned, Ordering::Release, &guard);

        self.inner
            .rebuild_in_progress
            .store(false, Ordering::Relaxed);

        unsafe {
            guard.defer_destroy(old_ptr);
        }
    }
}

struct SchemaSamplerInner {
    table: crossbeam_epoch::Atomic<Table>,
    rebuild_in_progress: AtomicBool,
    threshold: u32,
    time_bias: u32, // avoid problems with wrap arounds
    _start_time: Instant,
}

struct Table {
    entries: [Entry; CAPACITY + 1],
    size: AtomicUsize,
}

impl Table {
    fn new() -> Self {
        let entries: [Entry; CAPACITY + 1] = [(); CAPACITY + 1].map(|_| Entry::new());
        Self {
            entries,
            size: AtomicUsize::new(0),
        }
    }

    fn find_slot(&self, number: u64) -> (&Entry, bool) {
        let hash = Self::hash(number);
        let orig_idx = (hash as usize) % CAPACITY;
        let mut idx = orig_idx;

        loop {
            let entry = &self.entries[idx];
            let key = entry.key.load(Ordering::Relaxed);

            if key == number {
                return (entry, true);
            } else if key == 0 {
                return (entry, false);
            }

            idx = (idx + 1) % CAPACITY;
            if idx == orig_idx {
                // should not happen... but if it does, return a fake entry
                return (&self.entries[CAPACITY], true);
            }
        }
    }

    fn hash(number: u64) -> u64 {
        number | 0x8000000000000000 // 0 is not a valid key
    }
}

#[repr(C, align(8))]
struct Entry {
    key: AtomicU64,
    data: AtomicU64,
}

impl Entry {
    fn new() -> Self {
        Self {
            key: AtomicU64::new(0),
            data: AtomicU64::new(0),
        }
    }

    fn load_data(&self, ordering: Ordering) -> EntryData {
        let raw = self.data.load(ordering);
        EntryData {
            last_accessed: (raw >> 32) as u32,
            last_reported: raw as u32,
        }
    }

    fn store_data(&self, data: EntryData, ordering: Ordering) {
        let raw = ((data.last_accessed as u64) << 32) | (data.last_reported as u64);
        self.data.store(raw, ordering);
    }

    fn compare_exchange_data(
        &self,
        current: EntryData,
        new: EntryData,
        success: Ordering,
        failure: Ordering,
    ) -> Result<EntryData, EntryData> {
        let current_raw = ((current.last_accessed as u64) << 32) | (current.last_reported as u64);
        let new_raw = ((new.last_accessed as u64) << 32) | (new.last_reported as u64);

        match self
            .data
            .compare_exchange(current_raw, new_raw, success, failure)
        {
            Ok(raw) => Ok(EntryData {
                last_accessed: (raw >> 32) as u32,
                last_reported: raw as u32,
            }),
            Err(raw) => Err(EntryData {
                last_accessed: (raw >> 32) as u32,
                last_reported: raw as u32,
            }),
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
#[repr(C, align(8))]
struct EntryData {
    last_accessed: u32,
    last_reported: u32,
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::atomic::AtomicU64;
    use std::thread;
    use std::time::Duration;

    #[test]
    fn test_basic_hit_functionality() {
        let sampler = SchemaSampler::new(1);

        assert!(sampler.should_sample(12345), "First hit should return true");
        assert!(
            !sampler.should_sample(12345),
            "Second hit should return false"
        );

        thread::sleep(Duration::from_secs(1));
        assert!(
            sampler.should_sample(12345),
            "After threshold, should return true"
        );

        assert!(
            !sampler.should_sample(12345),
            "Immediately after, should return false"
        );
    }

    #[test]
    fn test_multiple_entries() {
        let sampler = SchemaSampler::new(1);

        assert!(sampler.should_sample(1), "Entry 1 first hit");
        assert!(sampler.should_sample(2), "Entry 2 first hit");
        assert!(sampler.should_sample(3), "Entry 3 first hit");

        assert!(!sampler.should_sample(1), "Entry 1 second hit");
        assert!(!sampler.should_sample(2), "Entry 2 second hit");
        assert!(!sampler.should_sample(3), "Entry 3 second hit");

        assert!(sampler.should_sample(4), "Entry 4 first hit");
    }

    #[test]
    fn test_zero_key_hashes_correctly() {
        let sampler = SchemaSampler::new(1);

        // Key 0 hashes to 0x8000000000000000 due to hash function
        // The sampler treats it like any other key - the zero check
        // is done at the Service layer
        assert!(
            sampler.should_sample(0),
            "First hit for key 0 should return true"
        );
        assert!(
            !sampler.should_sample(0),
            "Second hit for key 0 should return false"
        );
    }

    #[test]
    fn test_near_capacity_rebuild() {
        let sampler = SchemaSampler::new(10);

        for i in 1..=MAX_ITEMS {
            assert!(
                sampler.should_sample(i as u64),
                "First hit for entry {} should succeed",
                i
            );
        }

        let guard = crossbeam_epoch::pin();
        let table_ptr = sampler.inner.table.load(Ordering::Acquire, &guard);
        let table = unsafe { table_ptr.as_ref().unwrap() };
        let size_before = table.size.load(Ordering::Relaxed);
        assert_eq!(size_before, MAX_ITEMS);
        drop(guard);

        assert!(
            sampler.should_sample((MAX_ITEMS + 1) as u64),
            "Inserting beyond MaxItems should trigger rebuild"
        );

        let deadline = std::time::Instant::now() + Duration::from_secs(2);
        let mut final_size = MAX_ITEMS + 1;
        while std::time::Instant::now() < deadline {
            let guard = crossbeam_epoch::pin();
            let table_ptr = sampler.inner.table.load(Ordering::Acquire, &guard);
            let table = unsafe { table_ptr.as_ref().unwrap() };
            final_size = table.size.load(Ordering::Relaxed);
            drop(guard);

            if final_size < MAX_ITEMS {
                break;
            }
            thread::sleep(Duration::from_millis(10));
        }

        assert!(
            final_size <= MAX_ITEMS * 2 / 3,
            "After rebuild, size should be reduced to ~2/3 of MaxItems, got {}",
            final_size
        );
    }

    #[test]
    fn test_concurrent_access() {
        let sampler = SchemaSampler::new(1);
        let total_hits = Arc::new(AtomicU64::new(0));
        let stop = Arc::new(AtomicBool::new(false));

        const NUM_THREADS: usize = 8;
        let mut handles = vec![];

        for _ in 0..NUM_THREADS {
            let sampler = sampler.clone();
            let total_hits = Arc::clone(&total_hits);
            let stop = Arc::clone(&stop);

            handles.push(thread::spawn(move || {
                while !stop.load(Ordering::Relaxed) {
                    if sampler.should_sample(1) {
                        total_hits.fetch_add(1, Ordering::Relaxed);
                    }
                    if sampler.should_sample(2) {
                        total_hits.fetch_add(1, Ordering::Relaxed);
                    }
                    if sampler.should_sample(3) {
                        total_hits.fetch_add(1, Ordering::Relaxed);
                    }
                }
            }));
        }

        for _ in 0..5 {
            thread::sleep(Duration::from_millis(500));
        }

        stop.store(true, Ordering::Relaxed);
        for handle in handles {
            handle.join().unwrap();
        }

        let hits = total_hits.load(Ordering::Relaxed);
        assert!(
            (3 * 2..=3 * 6).contains(&hits),
            "Expected between 6-18 hits (3 keys * 2-6 threshold periods), got {}",
            hits
        );
    }

    #[test]
    fn test_hash_with_high_bit() {
        let sampler = SchemaSampler::new(1);

        let key_without_high_bit = 0x1234567890ABCDEF;
        let key_with_high_bit = 0x9234567890ABCDEF;

        assert!(sampler.should_sample(key_without_high_bit));
        assert!(sampler.should_sample(key_with_high_bit));

        assert!(!sampler.should_sample(key_without_high_bit));
        assert!(!sampler.should_sample(key_with_high_bit));
    }

    #[test]
    fn test_sampling_disabled_when_period_zero() {
        let sampler = SchemaSampler::new(0);

        assert!(
            sampler.should_sample(12345),
            "First hit should always return true"
        );
        assert!(
            sampler.should_sample(12345),
            "With period=0, sampler is None, all hits return true"
        );
        assert!(
            sampler.should_sample(12345),
            "With period=0, sampler is None, all hits return true"
        );
    }
}
