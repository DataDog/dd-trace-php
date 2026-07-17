use core::cell::{Cell, UnsafeCell};
use criterion::{black_box, criterion_group, criterion_main, BatchSize, Criterion};

// Compile the production tracker without linking the PHP extension executable.
#[allow(dead_code)]
#[path = "../src/profiling/live_heap.rs"]
mod live_heap;
use live_heap::{LiveHeapTracker, LocalLiveHeapTracker};

const BASE_ADDRESS: usize = 0x1000_0000;
const TRACKED_ALLOCATIONS: usize = 2048;
const ADDRESS_STRIDE: usize = 64;

fn address(index: usize) -> usize {
    BASE_ADDRESS + index % TRACKED_ALLOCATIONS * ADDRESS_STRIDE
}

fn untracked_address(index: usize) -> usize {
    address(index) + 8
}

struct Tracker {
    shared: LiveHeapTracker<[usize; 4]>,
    local: UnsafeCell<LocalLiveHeapTracker>,
}

impl Tracker {
    fn new() -> Self {
        Self {
            shared: LiveHeapTracker::new(),
            local: UnsafeCell::new(LocalLiveHeapTracker::new()),
        }
    }

    fn track(&self, ptr: usize, sample: [usize; 4]) -> bool {
        // Criterion invokes setup and measured routines sequentially.
        unsafe { (&mut *self.local.get()).track(&self.shared, ptr, sample) }
    }

    fn untrack(&self, ptr: usize) -> Option<[usize; 4]> {
        // SAFETY: same sequential access as `track`.
        unsafe { (&mut *self.local.get()).untrack(&self.shared, ptr) }
    }
}

fn populated_tracker() -> Tracker {
    let tracker = Tracker::new();
    for index in 0..TRACKED_ALLOCATIONS {
        assert!(tracker.track(address(index), [0; 4]));
    }
    tracker
}

fn benchmark(c: &mut Criterion) {
    let mut group = c.benchmark_group("heap_live_tracking");

    {
        let tracker = populated_tracker();
        let next = Cell::new(0);
        group.bench_function("allocate_tracked", |b| {
            b.iter_batched(
                || {
                    let index = next.get();
                    next.set(index + 1);
                    let ptr = untracked_address(index);
                    let _ = tracker.untrack(ptr);
                    (ptr, [0; 4])
                },
                |(ptr, sample)| black_box(tracker.track(ptr, sample)),
                BatchSize::PerIteration,
            )
        });
    }

    {
        let tracker = populated_tracker();
        let next = Cell::new(0);
        group.bench_function("free_tracked", |b| {
            b.iter_batched(
                || {
                    let index = next.get();
                    next.set(index + 1);
                    let ptr = untracked_address(index);
                    assert!(tracker.track(ptr, [0; 4]));
                    ptr
                },
                |ptr| black_box(tracker.untrack(ptr)),
                BatchSize::PerIteration,
            )
        });
    }

    {
        let tracker = populated_tracker();
        let next = Cell::new(0);
        group.bench_function("free_untracked", |b| {
            b.iter_batched(
                || {
                    let index = next.get();
                    next.set(index + 1);
                    let ptr = untracked_address(index);
                    let _ = tracker.untrack(ptr);
                    ptr
                },
                |ptr| black_box(tracker.untrack(ptr)),
                BatchSize::PerIteration,
            )
        });
    }

    group.finish();
}

criterion_group!(benches, benchmark);
criterion_main!(benches);
