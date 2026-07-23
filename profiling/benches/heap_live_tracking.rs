use core::cell::UnsafeCell;
use core::time::Duration;
use criterion::{black_box, criterion_group, criterion_main, Criterion};
use std::time::Instant;

// Compile the production tracker without linking the PHP extension executable.
#[allow(dead_code)]
#[path = "../src/profiling/live_heap.rs"]
mod live_heap;
use live_heap::{LiveHeapTracker, LocalLiveHeapTracker};

const BASE_ADDRESS: usize = 0x1000_0000;
const TRACKED_ALLOCATIONS: usize = 2048;
const ADDRESS_STRIDE: usize = 64;
const BATCH_SIZE: u64 = 256;

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
        // SAFETY: Criterion invokes setup and measured routines sequentially.
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

fn measure_batched(
    iterations: u64,
    mut setup: impl FnMut(usize),
    mut routine: impl FnMut(usize),
    mut cleanup: impl FnMut(usize),
) -> Duration {
    let mut elapsed = Duration::ZERO;
    let mut completed = 0;

    while completed < iterations {
        let count = BATCH_SIZE.min(iterations - completed);
        for offset in 0..count {
            setup((completed + offset) as usize);
        }

        let start = Instant::now();
        for offset in 0..count {
            routine((completed + offset) as usize);
        }
        elapsed += start.elapsed();

        for offset in 0..count {
            cleanup((completed + offset) as usize);
        }
        completed += count;
    }

    elapsed
}

fn benchmark(c: &mut Criterion) {
    let mut group = c.benchmark_group("heap_live_tracking");

    {
        let tracker = populated_tracker();
        group.bench_function("allocate_tracked", |b| {
            b.iter_custom(|iterations| {
                measure_batched(
                    iterations,
                    |index| {
                        let _ = tracker.untrack(untracked_address(index));
                    },
                    |index| {
                        black_box(tracker.track(untracked_address(index), [0; 4]));
                    },
                    |index| {
                        let _ = tracker.untrack(untracked_address(index));
                    },
                )
            })
        });
    }

    {
        let tracker = populated_tracker();
        group.bench_function("free_tracked", |b| {
            b.iter_custom(|iterations| {
                measure_batched(
                    iterations,
                    |index| {
                        assert!(tracker.track(untracked_address(index), [0; 4]));
                    },
                    |index| {
                        black_box(tracker.untrack(untracked_address(index)));
                    },
                    |index| {
                        let _ = tracker.untrack(untracked_address(index));
                    },
                )
            })
        });
    }

    {
        let tracker = populated_tracker();
        group.bench_function("free_untracked", |b| {
            b.iter_custom(|iterations| {
                measure_batched(
                    iterations,
                    |index| {
                        let _ = tracker.untrack(untracked_address(index));
                    },
                    |index| {
                        black_box(tracker.untrack(untracked_address(index)));
                    },
                    |_| {},
                )
            })
        });
    }

    group.finish();
}

criterion_group!(benches, benchmark);
criterion_main!(benches);
