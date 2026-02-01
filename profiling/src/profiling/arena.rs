use crate::module_globals;
use core::ptr::{self, NonNull};
use core::slice;
use core::sync::atomic::{AtomicPtr, AtomicUsize, Ordering};
use crossbeam_utils::CachePadded;
use libdd_alloc::{AllocError, Allocator, Layout, VirtualAllocator};
use log::debug;
use std::marker::PhantomData;

const CHUNK_SIZE: usize = 128 * 1024;
const CHUNK_ROTATE_THRESHOLD: usize = CHUNK_SIZE - 4096;

#[derive(Debug)]
struct Chunk {
    next: AtomicPtr<Chunk>,
    data: NonNull<u8>,
}

impl Chunk {
    fn new() -> Result<NonNull<Chunk>, AllocError> {
        let layout = Layout::from_size_align(CHUNK_SIZE, core::mem::align_of::<usize>())
            .map_err(|_| AllocError)?;
        let allocation = VirtualAllocator {}.allocate(layout)?;
        let data = NonNull::new(allocation.as_ptr().cast::<u8>()).ok_or(AllocError)?;
        let chunk = Box::new(Chunk {
            next: AtomicPtr::new(ptr::null_mut()),
            data,
        });
        Ok(unsafe { NonNull::new_unchecked(Box::into_raw(chunk)) })
    }
}

#[derive(Debug)]
pub struct ChunkedArena {
    refcount: CachePadded<AtomicUsize>,
    head: AtomicPtr<Chunk>,
    tail: AtomicPtr<Chunk>,
    tail_used: AtomicUsize,
    total_used: AtomicUsize,
}

impl ChunkedArena {
    fn new() -> Result<NonNull<ChunkedArena>, AllocError> {
        let chunk = Chunk::new()?;
        let ptr = chunk.as_ptr();
        let arena = Box::new(ChunkedArena {
            refcount: CachePadded::new(AtomicUsize::new(1)),
            head: AtomicPtr::new(ptr),
            tail: AtomicPtr::new(ptr),
            tail_used: AtomicUsize::new(0),
            total_used: AtomicUsize::new(0),
        });
        Ok(unsafe { NonNull::new_unchecked(Box::into_raw(arena)) })
    }

    fn incref(ptr: NonNull<ChunkedArena>) {
        let arena = unsafe { ptr.as_ref() };
        arena.refcount.fetch_add(1, Ordering::Relaxed);
    }

    unsafe fn decref(ptr: NonNull<ChunkedArena>) {
        let arena = ptr.as_ref();
        if arena.refcount.fetch_sub(1, Ordering::Release) == 1 {
            std::sync::atomic::fence(Ordering::Acquire);
            drop(Box::from_raw(ptr.as_ptr()));
        }
    }

    fn should_rotate(&self) -> bool {
        self.total_used.load(Ordering::Relaxed) >= CHUNK_ROTATE_THRESHOLD
    }

    fn reserve(&mut self, len: usize) -> Result<(FrameSlice, *mut u8), AllocError> {
        if len > CHUNK_SIZE {
            return Err(AllocError);
        }

        let mut tail_ptr = NonNull::new(self.tail.load(Ordering::Acquire)).ok_or(AllocError)?;
        let mut used = self.tail_used.load(Ordering::Relaxed);
        if used + len > CHUNK_SIZE {
            let new_chunk = Chunk::new()?;
            unsafe {
                tail_ptr
                    .as_ref()
                    .next
                    .store(new_chunk.as_ptr(), Ordering::Release);
            }
            self.tail.store(new_chunk.as_ptr(), Ordering::Release);
            self.tail_used.store(0, Ordering::Relaxed);
            tail_ptr = new_chunk;
            used = 0;
        }

        let chunk = unsafe { tail_ptr.as_ref() };
        let dst = unsafe { chunk.data.as_ptr().add(used) };
        self.tail_used.store(used + len, Ordering::Relaxed);
        self.total_used.fetch_add(len, Ordering::Relaxed);
        Ok((
            FrameSlice {
                chunk: tail_ptr,
                offset: used,
                len,
            },
            dst,
        ))
    }

    pub fn append(&mut self, bytes: &[u8]) -> Result<FrameSlice, AllocError> {
        let (slice, dst) = self.reserve(bytes.len())?;
        unsafe {
            ptr::copy_nonoverlapping(bytes.as_ptr(), dst, bytes.len());
        }
        Ok(slice)
    }

    pub fn alloc_uninit(&mut self, len: usize) -> Result<(FrameSlice, *mut u8), AllocError> {
        self.reserve(len)
    }
}

impl Drop for ChunkedArena {
    fn drop(&mut self) {
        let layout = Layout::from_size_align(CHUNK_SIZE, core::mem::align_of::<usize>()).unwrap();
        let mut chunk_ptr = self.head.load(Ordering::Acquire);
        while let Some(chunk) = NonNull::new(chunk_ptr) {
            unsafe {
                let next = chunk.as_ref().next.load(Ordering::Acquire);
                VirtualAllocator {}.deallocate(chunk.as_ref().data, layout);
                drop(Box::from_raw(chunk.as_ptr()));
                chunk_ptr = next;
            }
        }
    }
}

#[derive(Debug, Clone)]
pub struct ArenaConsumer {
    ptr: NonNull<ChunkedArena>,
}

impl ArenaConsumer {
    fn from_raw(ptr: NonNull<ChunkedArena>) -> Self {
        ChunkedArena::incref(ptr);
        Self { ptr }
    }
}

impl ArenaConsumer {
    pub(crate) fn ptr(&self) -> NonNull<ChunkedArena> {
        self.ptr
    }
}

impl Drop for ArenaConsumer {
    fn drop(&mut self) {
        unsafe {
            ChunkedArena::decref(self.ptr);
        }
    }
}

// SAFETY: ArenaConsumer is a refcounted pointer to ChunkedArena. The arena is
// append-only; once a FrameSlice is allocated, its bytes are immutable. All
// mutation is via atomic counters and pointer updates, so sharing across
// threads does not create data races.
unsafe impl Send for ArenaConsumer {}

// SAFETY: Reading slices from the arena is read-only and bounded by the
// FrameSlice metadata. Internal mutation is synchronized with atomics.
unsafe impl Sync for ArenaConsumer {}

#[derive(Debug, Clone, Copy)]
pub struct FrameSlice {
    chunk: NonNull<Chunk>,
    offset: usize,
    len: usize,
}

impl FrameSlice {
    pub unsafe fn as_bytes(&self) -> &[u8] {
        let chunk = self.chunk.as_ref();
        let ptr = chunk.data.as_ptr().add(self.offset);
        slice::from_raw_parts(ptr, self.len)
    }
}

pub fn get_or_create_current_arena_consumer() -> Result<ArenaConsumer, AllocError> {
    // SAFETY: must be called from a PHP thread after ginit.
    let globals = unsafe { module_globals::get_profiler_globals() };
    if globals.is_null() {
        return Err(AllocError);
    }

    let mut arena_ptr = unsafe { (*globals).current_arena };
    if let Some(current) = NonNull::new(arena_ptr) {
        if unsafe { current.as_ref() }.should_rotate() {
            debug!("Rotating backtrace arena after exceeding {CHUNK_ROTATE_THRESHOLD} bytes.");
            let new_ptr = ChunkedArena::new()?;
            unsafe {
                (*globals).current_arena = new_ptr.as_ptr();
                ChunkedArena::decref(current);
            }
            arena_ptr = new_ptr.as_ptr();
        }
    } else {
        let new_ptr = ChunkedArena::new()?;
        unsafe {
            (*globals).current_arena = new_ptr.as_ptr();
        }
        arena_ptr = new_ptr.as_ptr();
    }

    let arena_ptr = NonNull::new(arena_ptr).ok_or(AllocError)?;
    Ok(ArenaConsumer::from_raw(arena_ptr))
}

pub unsafe fn release_current_arena(ptr: *mut ChunkedArena) {
    if let Some(ptr) = NonNull::new(ptr) {
        ChunkedArena::decref(ptr);
    }
}

pub struct ArenaProducer {
    ptr: NonNull<ChunkedArena>,
    _not_send: PhantomData<std::rc::Rc<()>>,
}

impl ArenaProducer {
    fn from_raw(ptr: NonNull<ChunkedArena>) -> Self {
        Self {
            ptr,
            _not_send: PhantomData,
        }
    }

    pub fn append(&mut self, bytes: &[u8]) -> Result<FrameSlice, AllocError> {
        let arena = unsafe { &mut *self.ptr.as_ptr() };
        arena.append(bytes)
    }

    pub fn alloc_uninit(&mut self, len: usize) -> Result<(FrameSlice, *mut u8), AllocError> {
        let arena = unsafe { &mut *self.ptr.as_ptr() };
        arena.alloc_uninit(len)
    }
}

pub fn get_or_create_current_arena() -> Result<(ArenaConsumer, ArenaProducer), AllocError> {
    let consumer = get_or_create_current_arena_consumer()?;
    let producer = ArenaProducer::from_raw(consumer.ptr());
    Ok((consumer, producer))
}
