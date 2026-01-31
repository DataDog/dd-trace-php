use crate::module_globals;
use crate::profiling::{ProfileIndex, SampleMessage};
use rtrb::{Consumer, Producer, RingBuffer};
use std::cell::UnsafeCell;
use std::collections::HashMap;
use std::mem::MaybeUninit;
use std::ptr;
use std::ptr::NonNull;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::{Mutex, MutexGuard};

const THREAD_QUEUE_CAPACITY: usize = 256;

#[derive(Copy, Clone)]
pub struct QueuePtr(NonNull<ThreadQueue>);

// SAFETY: pointers are only used for queue registration/lookup; the queue
// lifetime is managed explicitly by ginit/gshutdown ordering.
unsafe impl Send for QueuePtr {}
unsafe impl Sync for QueuePtr {}

impl QueuePtr {
    pub unsafe fn as_ref(&self) -> &ThreadQueue {
        self.0.as_ref()
    }
}

static REGISTRY: Mutex<Vec<QueuePtr>> = Mutex::new(Vec::new());
static mut REGISTRY_FORK_GUARD: MaybeUninit<MutexGuard<'static, Vec<QueuePtr>>> =
    MaybeUninit::uninit();
static mut REGISTRY_FORK_GUARD_HELD: bool = false;

pub struct DroppedSampleStats {
    dropped_count: AtomicU64,
    totals: Mutex<HashMap<ProfileIndex, Vec<i64>>>,
}

impl DroppedSampleStats {
    fn new() -> Self {
        Self {
            dropped_count: AtomicU64::new(0),
            totals: Mutex::new(HashMap::new()),
        }
    }

    fn record_drop(&self, message: &SampleMessage) {
        self.dropped_count.fetch_add(1, Ordering::Relaxed);
        let mut totals = self
            .totals
            .lock()
            .expect("dropped sample totals mutex poisoned");
        let entry = totals
            .entry(message.key.clone())
            .or_insert_with(|| vec![0; message.value.sample_values.len()]);
        if entry.len() < message.value.sample_values.len() {
            entry.resize(message.value.sample_values.len(), 0);
        }
        for (dst, value) in entry.iter_mut().zip(message.value.sample_values.iter()) {
            *dst = dst.saturating_add(*value);
        }
    }

    fn take(&self) -> (u64, HashMap<ProfileIndex, Vec<i64>>) {
        let dropped = self.dropped_count.swap(0, Ordering::Relaxed);
        let mut totals = self
            .totals
            .lock()
            .expect("dropped sample totals mutex poisoned");
        let snapshot = std::mem::take(&mut *totals);
        (dropped, snapshot)
    }
}

#[derive(Debug)]
pub struct EnqueueError;

impl std::fmt::Display for EnqueueError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        write!(f, "sample queue is full")
    }
}

impl std::error::Error for EnqueueError {}

pub struct ThreadQueue {
    producer: UnsafeCell<Producer<SampleMessage>>,
    consumer: UnsafeCell<Consumer<SampleMessage>>,
    drop_stats: DroppedSampleStats,
    drain_lock: Mutex<()>,
}

unsafe impl Sync for ThreadQueue {}

impl ThreadQueue {
    fn new() -> Self {
        let (producer, consumer) = RingBuffer::new(THREAD_QUEUE_CAPACITY);
        Self {
            producer: UnsafeCell::new(producer),
            consumer: UnsafeCell::new(consumer),
            drop_stats: DroppedSampleStats::new(),
            drain_lock: Mutex::new(()),
        }
    }

    pub fn enqueue(&self, message: SampleMessage) -> Result<(), EnqueueError> {
        let producer = unsafe { &mut *self.producer.get() };
        std::sync::atomic::fence(Ordering::Release);
        match producer.push(message) {
            Ok(()) => Ok(()),
            Err(rtrb::PushError::Full(message)) => {
                // On full, drop the new sample and record stats.
                // This should be rare, so we can afford the lock in record_drop.
                self.drop_stats.record_drop(&message);
                Err(EnqueueError)
            }
        }
    }

    pub fn drain_with<F>(&self, mut f: F)
    where
        F: FnMut(SampleMessage),
    {
        let _guard = self
            .drain_lock
            .lock()
            .expect("thread queue drain lock poisoned");
        let consumer = unsafe { &mut *self.consumer.get() };
        let limit = consumer.slots();
        for _ in 0..limit {
            match consumer.pop() {
                Ok(message) => {
                    std::sync::atomic::fence(Ordering::Acquire);
                    f(message)
                }
                Err(_) => break,
            }
        }
    }

    pub fn finalize_borrowed(&self) {
        let _guard = self
            .drain_lock
            .lock()
            .expect("thread queue drain lock poisoned");
        let consumer = unsafe { &mut *self.consumer.get() };
        let producer = unsafe { &mut *self.producer.get() };
        let limit = consumer.slots();
        for _ in 0..limit {
            match consumer.pop() {
                Ok(mut message) => {
                    message.value.make_owned();
                    // Should succeed since we only popped up to the snapshot size.
                    let _ = producer.push(message);
                }
                Err(_) => break,
            }
        }
    }

    pub fn take_drop_stats(&self) -> (u64, HashMap<ProfileIndex, Vec<i64>>) {
        self.drop_stats.take()
    }
}

pub unsafe fn ginit(globals_ptr: *mut module_globals::ProfilerGlobals) {
    let queue = Box::new(ThreadQueue::new());
    let queue_ptr = Box::into_raw(queue);
    (*globals_ptr).thread_queue = queue_ptr;
    register_queue(queue_ptr);
}

pub unsafe fn gshutdown(globals_ptr: *mut module_globals::ProfilerGlobals) {
    let queue_ptr = (*globals_ptr).thread_queue;
    if let Some(queue_ptr) = NonNull::new(queue_ptr) {
        deregister_queue(queue_ptr);
        drop(Box::from_raw(queue_ptr.as_ptr()));
        (*globals_ptr).thread_queue = std::ptr::null_mut();
    }
}

#[inline]
pub unsafe fn get_thread_queue() -> *mut ThreadQueue {
    let globals = module_globals::get_profiler_globals();
    (*globals).thread_queue
}

pub fn snapshot_registry() -> Vec<QueuePtr> {
    let registry = REGISTRY
        .lock()
        .expect("thread queue registry lock poisoned");
    registry.clone()
}

pub fn take_all_drop_stats() -> Vec<(u64, HashMap<ProfileIndex, Vec<i64>>)> {
    let registry = snapshot_registry();
    registry
        .into_iter()
        .map(|queue| unsafe { queue.0.as_ref().take_drop_stats() })
        .collect()
}

pub unsafe fn deactivate() {
    let queue = get_thread_queue();
    if let Some(queue) = queue.as_ref() {
        queue.finalize_borrowed();
    }
}

pub fn fork_prepare() {
    let guard = REGISTRY
        .lock()
        .expect("thread queue registry lock poisoned");
    // SAFETY: REGISTRY is a static, and we hold the lock across fork.
    unsafe {
        let guard = std::mem::transmute::<
            MutexGuard<'_, Vec<QueuePtr>>,
            MutexGuard<'static, Vec<QueuePtr>>,
        >(guard);
        ptr::addr_of_mut!(REGISTRY_FORK_GUARD).write(MaybeUninit::new(guard));
        REGISTRY_FORK_GUARD_HELD = true;
    }
}

pub unsafe fn fork_parent() {
    if REGISTRY_FORK_GUARD_HELD {
        // SAFETY: guard was written in fork_prepare.
        let guard = ptr::addr_of!(REGISTRY_FORK_GUARD).read().assume_init();
        drop(guard);
        REGISTRY_FORK_GUARD_HELD = false;
    }
}

pub unsafe fn fork_child() {
    let queue_ptr = get_thread_queue();
    let queue_ptr =
        QueuePtr(NonNull::new(queue_ptr).expect("thread queue pointer should not be null"));
    if REGISTRY_FORK_GUARD_HELD {
        // SAFETY: guard was written in fork_prepare.
        let guard_ptr =
            ptr::addr_of_mut!(REGISTRY_FORK_GUARD).cast::<MutexGuard<'static, Vec<QueuePtr>>>();
        (*guard_ptr).clear();
        (*guard_ptr).push(queue_ptr);
        let guard = ptr::addr_of!(REGISTRY_FORK_GUARD).read().assume_init();
        drop(guard);
        REGISTRY_FORK_GUARD_HELD = false;
    } else {
        let mut guard = REGISTRY
            .lock()
            .expect("thread queue registry lock poisoned");
        guard.clear();
        guard.push(queue_ptr);
    }
}

fn register_queue(queue_ptr: *mut ThreadQueue) {
    let mut registry = REGISTRY
        .lock()
        .expect("thread queue registry lock poisoned");
    let ptr = NonNull::new(queue_ptr).expect("thread queue pointer should not be null");
    registry.push(QueuePtr(ptr));
}

fn deregister_queue(queue_ptr: NonNull<ThreadQueue>) {
    let mut registry = REGISTRY
        .lock()
        .expect("thread queue registry lock poisoned");
    if let Some(index) = registry.iter().position(|ptr| ptr.0 == queue_ptr) {
        registry.swap_remove(index);
    }
}
