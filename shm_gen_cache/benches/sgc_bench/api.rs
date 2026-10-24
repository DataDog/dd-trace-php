//! The two ways the bench drives the cache (`--api`):
//!
//! * `rust`: the Rust API. The cache code is compiled into the bench with
//!   LTO and may be inlined into the timed loop, as in a Rust caller.
//! * `c`: the C API (`ddog_sgc_*`), one out-of-line call per operation, as
//!   in a C caller of the staticlib. The entry points are called through
//!   function pointers hidden from the optimizer (`black_box`), so LTO
//!   cannot inline them into the timed loop.
//!
//! The scenarios, timing and setup are the same code for both, generic over
//! [`Api`].

use std::hint::black_box;
use std::ptr::{NonNull, null_mut};

use shm_gen_cache::ffi::{
    self, FfiCache, FfiParticipant, Status, ddog_sgc_cache_free, ddog_sgc_cache_init_in,
    ddog_sgc_cache_mapping_size,
};
use shm_gen_cache::{Cache, Params, ParticipantLock};

use crate::sgc_bench::config::{BENCH_CONFIG, ValueBuffer};

/// The result of a lookup.
pub enum Lookup<'o> {
    Hit(&'o [u8]),
    Miss,
    Error,
}

/// A registered participant, used by one thread.
pub trait Participant {
    /// Whether the insert succeeded.
    fn insert(&mut self, hash: u64, key: &[u8], value: &[u8]) -> bool;
    fn lookup<'o>(&mut self, hash: u64, key: &[u8], out: &'o mut ValueBuffer) -> Lookup<'o>;
}

/// A cache, shared by the threads of a phase.
pub trait Backend: Copy + Send + Sync {
    type Participant: Participant;
    /// A new participant, or the error code.
    fn register(self) -> Result<Self::Participant, String>;
}

/// Initializes caches in the bench's mappings.
pub trait Api: Copy + Send + Sync {
    type Cache: Backend;
    fn mapping_size(self) -> usize;
    /// Initializes a cache in `mem`; aborts on failure.
    ///
    /// # Safety
    /// `mem` is zero-filled, page-aligned, `len` bytes long, and outlives
    /// every use of the returned cache and of its participants.
    unsafe fn initialize(self, mem: *mut u8, len: usize) -> Self::Cache;
    /// Frees what `initialize` allocated outside `mem`.
    ///
    /// # Safety
    /// No participant of `cache` is left.
    unsafe fn release(self, cache: Self::Cache);
}

/// The Rust API with the resolved parameters.
#[derive(Clone, Copy)]
pub struct RustApi<P>(pub P);

impl<P: Params + Send + Sync> Api for RustApi<P> {
    type Cache = Cache<'static, P>;

    fn mapping_size(self) -> usize {
        self.0.derived().mapping_size()
    }

    unsafe fn initialize(self, mem: *mut u8, len: usize) -> Self::Cache {
        // SAFETY: forwarded from the caller.
        match unsafe { Cache::initialize(mem, len, self.0) } {
            Ok(c) => c,
            Err(e) => die("cache initialization", e.code()),
        }
    }

    unsafe fn release(self, _cache: Self::Cache) {}
}

impl<P: Params + Send + Sync> Backend for Cache<'static, P> {
    type Participant = ParticipantLock<'static, P>;

    fn register(self) -> Result<Self::Participant, String> {
        self.register_participant()
            .map_err(|e| e.code().to_string())
    }
}

impl<P: Params> Participant for ParticipantLock<'_, P> {
    #[inline(always)]
    fn insert(&mut self, hash: u64, key: &[u8], value: &[u8]) -> bool {
        ParticipantLock::insert(self, hash, key, value).is_ok()
    }

    #[inline(always)]
    fn lookup<'o>(&mut self, hash: u64, key: &[u8], out: &'o mut ValueBuffer) -> Lookup<'o> {
        match ParticipantLock::lookup(self, hash, key, out) {
            Ok(Some(v)) => Lookup::Hit(v),
            Ok(None) => Lookup::Miss,
            Err(_) => Lookup::Error,
        }
    }
}

/// The C API, with `BENCH_CONFIG`.
#[derive(Clone, Copy)]
pub struct CApi;

impl Api for CApi {
    type Cache = CCache;

    fn mapping_size(self) -> usize {
        // SAFETY: a valid configuration.
        unsafe { ddog_sgc_cache_mapping_size(&BENCH_CONFIG) }
    }

    unsafe fn initialize(self, mem: *mut u8, len: usize) -> Self::Cache {
        let mut handle = null_mut();
        // SAFETY: forwarded from the caller; `handle` is valid for a write.
        let status = unsafe { ddog_sgc_cache_init_in(mem.cast(), len, &BENCH_CONFIG, &mut handle) };
        match NonNull::new(handle) {
            Some(handle) if status == Status::Ok => CCache {
                handle,
                entries: Entries::hidden(),
            },
            _ => die("ddog_sgc_cache_init_in", format!("{status:?}")),
        }
    }

    unsafe fn release(self, cache: Self::Cache) {
        // SAFETY: the handle from `initialize`, with no participant left
        // (caller); it does not own the mapping.
        unsafe { ddog_sgc_cache_free(cache.handle.as_ptr()) };
    }
}

/// The participant and operation entry points of the C API, as function
/// pointers the optimizer cannot see through.
#[derive(Clone, Copy)]
struct Entries {
    register: unsafe extern "C" fn(*const FfiCache, *mut *mut FfiParticipant) -> Status,
    unregister: unsafe extern "C" fn(*mut FfiParticipant),
    lookup: unsafe extern "C" fn(
        *mut FfiParticipant,
        u64,
        *const u8,
        usize,
        *mut u64,
        usize,
        *mut usize,
    ) -> Status,
    insert: unsafe extern "C" fn(
        *mut FfiParticipant,
        u64,
        *const u8,
        usize,
        *const u8,
        usize,
    ) -> Status,
}

impl Entries {
    fn hidden() -> Self {
        black_box(Entries {
            register: ffi::ddog_sgc_participant_register,
            unregister: ffi::ddog_sgc_participant_unregister,
            lookup: ffi::ddog_sgc_lookup,
            insert: ffi::ddog_sgc_insert,
        })
    }
}

/// A C API cache handle.
#[derive(Clone, Copy)]
pub struct CCache {
    handle: NonNull<FfiCache>,
    entries: Entries,
}

// SAFETY: the C API lets any thread register with a cache handle.
unsafe impl Send for CCache {}
// SAFETY: as above.
unsafe impl Sync for CCache {}

impl Backend for CCache {
    type Participant = CParticipant;

    fn register(self) -> Result<CParticipant, String> {
        let mut handle = null_mut();
        // SAFETY: a live cache handle; `handle` is valid for a write.
        let status = unsafe { (self.entries.register)(self.handle.as_ptr(), &mut handle) };
        match NonNull::new(handle) {
            Some(handle) if status == Status::Ok => Ok(CParticipant {
                handle,
                entries: self.entries,
            }),
            _ => Err(format!("{status:?}")),
        }
    }
}

/// A C API participant handle, unregistered on drop.
pub struct CParticipant {
    handle: NonNull<FfiParticipant>,
    entries: Entries,
}

impl Participant for CParticipant {
    #[inline(always)]
    fn insert(&mut self, hash: u64, key: &[u8], value: &[u8]) -> bool {
        // SAFETY: a live handle on its registering thread; the slices are
        // valid for their lengths.
        let status = unsafe {
            (self.entries.insert)(
                self.handle.as_ptr(),
                hash,
                key.as_ptr(),
                key.len(),
                value.as_ptr(),
                value.len(),
            )
        };
        status == Status::Ok
    }

    #[inline(always)]
    fn lookup<'o>(&mut self, hash: u64, key: &[u8], out: &'o mut ValueBuffer) -> Lookup<'o> {
        let capacity = out.capacity();
        let mut len = 0usize;
        // SAFETY: a live handle on its registering thread; `key` is valid
        // for its length, `out` holds `ceil(capacity / 8)` aligned words,
        // and `len` is valid for a write.
        let status = unsafe {
            (self.entries.lookup)(
                self.handle.as_ptr(),
                hash,
                key.as_ptr(),
                key.len(),
                out.words_mut().as_mut_ptr(),
                capacity,
                &mut len,
            )
        };
        match status {
            Status::Ok => Lookup::Hit(&out.bytes()[..len]),
            Status::Miss => Lookup::Miss,
            _ => Lookup::Error,
        }
    }
}

impl Drop for CParticipant {
    fn drop(&mut self) {
        // SAFETY: the handle from `register`, released once.
        unsafe { (self.entries.unregister)(self.handle.as_ptr()) };
    }
}

fn die(what: &str, code: impl std::fmt::Display) -> ! {
    eprintln!("{what} failed: {code}");
    std::process::abort();
}
