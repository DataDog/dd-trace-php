//! Native smoke tests: the cache works through the Rust API (static
//! configuration in static storage) and through the C API (runtime
//! configuration in an anonymous shared mapping), with several threads.
//! These are not the verification suite (see verification/).

use std::sync::Barrier;
use std::thread;

use shm_gen_cache::ffi::{
    FfiCache, FfiParticipant, Status, ddog_sgc_cache_free, ddog_sgc_cache_init_in,
    ddog_sgc_cache_mapping_size, ddog_sgc_cache_new, ddog_sgc_insert, ddog_sgc_lookup,
    ddog_sgc_participant_register, ddog_sgc_participant_unregister,
};
use shm_gen_cache::{
    Cache, CacheStorage, Config, Error, NoopGetPid, Params, StaticParams, output_buffer,
    static_config,
};

/// A deterministic value for `key`, `len` bytes long.
fn value_for(key: u64, len: usize) -> Vec<u8> {
    (0..len)
        .map(|i| (key.wrapping_mul(31).wrapping_add(i as u64 * 7) % 251) as u8)
        .collect()
}

fn key_bytes(key: u64) -> [u8; 8] {
    key.to_le_bytes()
}

/// splitmix64, as the caller-supplied hash.
fn hash_of(key: u64) -> u64 {
    let mut z = key.wrapping_add(0x9e37_79b9_7f4a_7c15);
    z = (z ^ (z >> 30)).wrapping_mul(0xbf58_476d_1ce4_e5b9);
    z = (z ^ (z >> 27)).wrapping_mul(0x94d0_49bb_1331_11eb);
    z ^ (z >> 31)
}

static_config! {
    /// Exact occupancy, 16 buckets, rotation after 8 new entries.
    struct Small: NoopGetPid {
        participant_capacity: 8,
        bucket_count: 16,
        max_key_size: 16,
        max_value_size: 40,
        max_occupancy: 8,
    }
}

static_config! {
    /// Exact occupancy, rotates often under several threads.
    struct Shared: NoopGetPid {
        participant_capacity: 8,
        bucket_count: 64,
        max_key_size: 16,
        max_value_size: 32,
    }
}

/// Waits are bounded: a thread preempted while pinned (or while owning a
/// rotation) can make another's operation time out. That is a legitimate
/// outcome under contention; anything else is a failure.
fn tolerate_timeouts(result: Result<(), Error>) {
    match result {
        Ok(()) | Err(Error::RotationOwnerTimeout | Error::ArenaReuseTimeout) => {}
        Err(e) => panic!("unexpected error {e:?}"),
    }
}

fn tolerate_timeout_status(status: Status) {
    match status {
        Status::Ok | Status::RotationOwnerTimeout | Status::ArenaReuseTimeout => {}
        s => panic!("unexpected status {s:?}"),
    }
}

#[test]
fn static_config_mapping_size_follows_the_layout() {
    let d = Small::DERIVED;
    let cl = shm_gen_cache::CACHE_LINE;
    // Exact mode: two header lines per arena.
    let arena = (2 * cl + 8 * 16 + 16 * (16 + 16 + 40)).next_multiple_of(cl);
    assert_eq!(d.mapping_size(), cl * (1 + 8) + 3 * arena);
    assert!(!d.estimates_occupancy());
    // The bench configuration: 112.5 MiB on x86-64 (64-byte lines).
    let bench = Config {
        participant_capacity: 128,
        bucket_count: 65536,
        max_key_size: 64,
        max_value_size: 512,
        record_area_size: 65536 * (8 + 8 + 64 + 512),
        max_occupancy: 65536 * 7 / 10,
        ..Config::DEFAULT
    }
    .resolve()
    .unwrap();
    assert!(bench.estimates_occupancy());
}

/// Expected mapping sizes of a few configurations per architecture (aarch64
/// has 128-byte lines, x86-64 64-byte); they are fixed by layout version 10.
#[test]
fn mapping_sizes_per_architecture() {
    let bench = Config {
        participant_capacity: 128,
        bucket_count: 65536,
        max_key_size: 64,
        max_value_size: 512,
        record_area_size: 65536 * (8 + 8 + 64 + 512),
        max_occupancy: 65536 * 7 / 10,
        ..Config::DEFAULT
    };
    let tiny = Config {
        participant_capacity: 2,
        bucket_count: 8,
        max_key_size: 1,
        max_value_size: 1,
        record_area_size: 32,
        max_occupancy: 8,
        reservation_chunk_size: 8,
        ..Config::DEFAULT
    };
    let estimated = Config {
        participant_capacity: 16,
        bucket_count: 8192,
        max_key_size: 16,
        max_value_size: 100,
        ..Config::DEFAULT
    };
    let sizes: Vec<usize> = [bench, tiny, Config::DEFAULT, estimated]
        .iter()
        .map(|c| c.resolve().unwrap().mapping_size())
        .collect();
    #[cfg(target_arch = "aarch64")]
    assert_eq!(sizes, [117_981_696, 1536, 3_433_344, 3_541_504]);
    #[cfg(target_arch = "x86_64")]
    assert_eq!(sizes, [117_973_248, 960, 3_424_704, 3_540_224]);
    let _ = sizes;
}

#[test]
fn promotion_keeps_a_key_across_rotations() {
    static STORAGE: <Small as StaticParams>::Storage = CacheStorage::new();
    let cache = unsafe { Cache::initialize(STORAGE.as_mut_ptr(), STORAGE.len(), Small) }.unwrap();
    let mut lock = cache.register_participant().unwrap();
    let mut out = output_buffer!(40);

    lock.insert(hash_of(0), &key_bytes(0), &value_for(0, 40))
        .unwrap();
    // Seven more new entries reach the target of 8: rotation to epoch 3,
    // key 0 is now in the previous generation.
    for k in 1..8 {
        lock.insert(hash_of(k), &key_bytes(k), &value_for(k, 9))
            .unwrap();
    }
    // Hit in the previous generation: promoted into epoch 3.
    assert_eq!(
        lock.lookup(hash_of(0), &key_bytes(0), &mut out).unwrap(),
        Some(&value_for(0, 40)[..])
    );
    // Seven more new entries (plus the promoted one): rotation to epoch 4.
    for k in 8..15 {
        lock.insert(hash_of(k), &key_bytes(k), &value_for(k, 3))
            .unwrap();
    }
    // Without the promotion, key 0 (epoch 2) would be gone now.
    assert_eq!(
        lock.lookup(hash_of(0), &key_bytes(0), &mut out).unwrap(),
        Some(&value_for(0, 40)[..])
    );
    // Keys 1..8 (epoch 2) are gone; 8..15 (epoch 3) are in the previous
    // generation.
    assert_eq!(
        lock.lookup(hash_of(1), &key_bytes(1), &mut out).unwrap(),
        None
    );
    assert_eq!(
        lock.lookup(hash_of(9), &key_bytes(9), &mut out).unwrap(),
        Some(&value_for(9, 3)[..])
    );

    // Replacement, empty values, size limits.
    lock.insert(hash_of(9), &key_bytes(9), b"").unwrap();
    assert_eq!(
        lock.lookup(hash_of(9), &key_bytes(9), &mut out).unwrap(),
        Some(&b""[..])
    );
    assert_eq!(lock.insert(1, &[0; 17], b""), Err(Error::KeyTooLarge));
    assert_eq!(lock.insert(1, b"k", &[0; 41]), Err(Error::ValueTooLarge));
    assert_eq!(lock.lookup(1, &[0; 17], &mut out), Err(Error::KeyTooLarge));
    let mut small = output_buffer!(39);
    assert_eq!(
        lock.lookup(hash_of(0), &key_bytes(0), &mut small),
        Err(Error::InsufficientCapacity)
    );
    drop(lock);
}

#[test]
fn static_config_threads() {
    static STORAGE: <Shared as StaticParams>::Storage = CacheStorage::new();
    let cache = unsafe { Cache::initialize(STORAGE.as_mut_ptr(), STORAGE.len(), Shared) }.unwrap();
    const THREADS: u64 = 4;
    const KEYS: u64 = 500;
    let barrier = Barrier::new(THREADS as usize);
    thread::scope(|s| {
        for t in 0..THREADS {
            let barrier = &barrier;
            s.spawn(move || {
                let mut lock = cache.register_participant().unwrap();
                let mut out = output_buffer!(32);
                barrier.wait();
                for i in 0..KEYS {
                    let k = t * KEYS + i;
                    let len = (k % 33) as usize;
                    tolerate_timeouts(lock.insert(hash_of(k), &key_bytes(k), &value_for(k, len)));
                    // Any hit must hold the right bytes: own keys and other
                    // threads' recent keys.
                    for probe in [k, k.saturating_sub(KEYS)] {
                        let found = lock.lookup(hash_of(probe), &key_bytes(probe), &mut out);
                        if let Ok(Some(v)) = found {
                            assert_eq!(v, &value_for(probe, (probe % 33) as usize)[..]);
                        } else {
                            tolerate_timeouts(found.map(|_| ()));
                        }
                    }
                }
            });
        }
    });
    // Quiescent: insert then lookup must hit.
    let mut lock = cache.register_participant().unwrap();
    let mut out = output_buffer!(32);
    for k in 10_000..10_050 {
        lock.insert(hash_of(k), &key_bytes(k), &value_for(k, 32))
            .unwrap();
        assert_eq!(
            lock.lookup(hash_of(k), &key_bytes(k), &mut out).unwrap(),
            Some(&value_for(k, 32)[..])
        );
    }
    assert!(Shared.derived().header().participant_capacity == 8);
}

struct CApi(*mut FfiCache);
unsafe impl Send for CApi {}
unsafe impl Sync for CApi {}

impl CApi {
    fn new(config: Config) -> Result<Self, Status> {
        let mut cache = std::ptr::null_mut();
        match unsafe { ddog_sgc_cache_new(&config, &mut cache) } {
            Status::Ok => Ok(CApi(cache)),
            s => Err(s),
        }
    }

    fn register(&self) -> Result<CParticipant, Status> {
        let mut p = std::ptr::null_mut();
        match unsafe { ddog_sgc_participant_register(self.0, &mut p) } {
            Status::Ok => Ok(CParticipant(p)),
            s => Err(s),
        }
    }
}

impl Drop for CApi {
    fn drop(&mut self) {
        unsafe { ddog_sgc_cache_free(self.0) }
    }
}

struct CParticipant(*mut FfiParticipant);

impl CParticipant {
    fn insert(&mut self, key: u64, value: &[u8]) -> Status {
        let k = key_bytes(key);
        unsafe {
            ddog_sgc_insert(
                self.0,
                hash_of(key),
                k.as_ptr(),
                k.len(),
                value.as_ptr(),
                value.len(),
            )
        }
    }

    fn lookup(&mut self, key: u64, out: &mut [u64], capacity: usize) -> Result<usize, Status> {
        let k = key_bytes(key);
        let mut len = usize::MAX;
        match unsafe {
            ddog_sgc_lookup(
                self.0,
                hash_of(key),
                k.as_ptr(),
                k.len(),
                out.as_mut_ptr(),
                capacity,
                &mut len,
            )
        } {
            Status::Ok => Ok(len),
            s => Err(s),
        }
    }
}

impl Drop for CParticipant {
    fn drop(&mut self) {
        unsafe { ddog_sgc_participant_unregister(self.0) }
    }
}

fn words_as_bytes(words: &[u64], len: usize) -> &[u8] {
    let bytes = unsafe { std::slice::from_raw_parts(words.as_ptr().cast::<u8>(), words.len() * 8) };
    &bytes[..len]
}

fn c_api_threads(config: Config, threads: u64, keys: u64) {
    let cache = CApi::new(config).unwrap();
    let max_value = config.max_value_size as usize;
    let barrier = Barrier::new(threads as usize);
    thread::scope(|s| {
        for t in 0..threads {
            let (cache, barrier) = (&cache, &barrier);
            s.spawn(move || {
                let mut p = cache.register().unwrap();
                let mut out = vec![0u64; max_value.div_ceil(8)];
                barrier.wait();
                let (mut own_inserted, mut own_hits) = (0u64, 0u64);
                for i in 0..keys {
                    let k = t * keys + i;
                    let len = (k as usize * 13) % (max_value + 1);
                    let inserted = p.insert(k, &value_for(k, len));
                    tolerate_timeout_status(inserted);
                    if inserted == Status::Ok {
                        own_inserted += 1;
                        if p.lookup(k, &mut out, max_value).is_ok() {
                            own_hits += 1;
                        }
                    }
                    for probe in [k, k / 2, k.saturating_sub(keys)] {
                        match p.lookup(probe, &mut out, max_value) {
                            Ok(n) => {
                                let expected =
                                    value_for(probe, (probe as usize * 13) % (max_value + 1));
                                assert_eq!(words_as_bytes(&out, n), &expected[..]);
                            }
                            Err(Status::Miss) => {}
                            Err(s) => tolerate_timeout_status(s),
                        }
                    }
                }
                // A fresh key misses only if two rotations completed between
                // its insert and the lookup.
                assert!(
                    own_hits * 10 >= own_inserted * 9,
                    "fresh keys hit: {own_hits}/{own_inserted}"
                );
                if threads == 1 {
                    assert_eq!((own_hits, own_inserted), (keys, keys));
                }
            });
        }
    });

    let mut p = cache.register().unwrap();
    let mut out = vec![0u64; max_value.div_ceil(8)];
    let k = 1 << 40;
    assert_eq!(p.insert(k, &value_for(k, max_value)), Status::Ok);
    assert_eq!(p.lookup(k, &mut out, max_value), Ok(max_value));
    assert_eq!(
        words_as_bytes(&out, max_value),
        &value_for(k, max_value)[..]
    );
    assert_eq!(
        p.lookup(k, &mut out, max_value - 1),
        Err(Status::InsufficientCapacity)
    );
    assert_eq!(p.lookup(k + 1, &mut out, max_value), Err(Status::Miss));
    assert_eq!(p.insert(k, &vec![0; max_value + 1]), Status::ValueTooLarge);
    let long_key = vec![0u8; config.max_key_size as usize + 1];
    let mut len = 0;
    unsafe {
        assert_eq!(
            ddog_sgc_insert(
                p.0,
                1,
                long_key.as_ptr(),
                long_key.len(),
                std::ptr::null(),
                0
            ),
            Status::KeyTooLarge
        );
        assert_eq!(
            ddog_sgc_lookup(
                p.0,
                1,
                long_key.as_ptr(),
                long_key.len(),
                out.as_mut_ptr(),
                8,
                &mut len
            ),
            Status::KeyTooLarge
        );
        // The key may not overlap the output storage.
        let key_in_out = out.as_ptr().cast::<u8>().add(8);
        assert_eq!(
            ddog_sgc_lookup(p.0, 1, key_in_out, 8, out.as_mut_ptr(), 16, &mut len),
            Status::InvalidArgument
        );
        // Empty key, null pointer, zero capacity.
        assert_eq!(
            ddog_sgc_insert(p.0, 7, std::ptr::null(), 0, std::ptr::null(), 0),
            Status::Ok
        );
        len = 99;
        assert_eq!(
            ddog_sgc_lookup(
                p.0,
                7,
                std::ptr::null(),
                0,
                std::ptr::null_mut(),
                0,
                &mut len
            ),
            Status::Ok
        );
        assert_eq!(len, 0);
    }
}

#[test]
fn c_api_estimated_occupancy_threads() {
    // 8192 buckets at 0.7 selects the estimator.
    let config = Config {
        participant_capacity: 16,
        bucket_count: 8192,
        max_key_size: 16,
        max_value_size: 100,
        ..Config::DEFAULT
    };
    assert!(config.resolve().unwrap().estimates_occupancy());
    c_api_threads(config, 8, 20_000);
}

#[test]
fn c_api_exact_occupancy_threads() {
    let config = Config {
        participant_capacity: 16,
        bucket_count: 256,
        max_key_size: 8,
        max_value_size: 24,
        reservation_chunk_size: 64,
        always_exact_occupancy: true,
        ..Config::DEFAULT
    };
    assert!(!config.resolve().unwrap().estimates_occupancy());
    c_api_threads(config, 8, 5_000);
}

#[test]
fn c_api_single_thread_rotations() {
    // Without concurrency every fresh key must hit (no two rotations can
    // separate an insert from the next lookup).
    let exact = Config {
        participant_capacity: 2,
        bucket_count: 64,
        max_key_size: 8,
        max_value_size: 24,
        ..Config::DEFAULT
    };
    c_api_threads(exact, 1, 20_000);
    let estimated = Config {
        participant_capacity: 2,
        bucket_count: 8192,
        max_key_size: 8,
        max_value_size: 24,
        ..Config::DEFAULT
    };
    c_api_threads(estimated, 1, 50_000);
}

/// Zero-filled, page-aligned heap memory for `ddog_sgc_cache_init_in`.
struct ZeroedPages {
    ptr: *mut u8,
    layout: std::alloc::Layout,
}

impl ZeroedPages {
    fn new(len: usize) -> Self {
        let layout = std::alloc::Layout::from_size_align(len, 4096).unwrap();
        let ptr = unsafe { std::alloc::alloc_zeroed(layout) };
        assert!(!ptr.is_null());
        ZeroedPages { ptr, layout }
    }
}

impl Drop for ZeroedPages {
    fn drop(&mut self) {
        unsafe { std::alloc::dealloc(self.ptr, self.layout) };
    }
}

#[test]
fn c_api_init_in_caller_memory() {
    let config = Config {
        participant_capacity: 4,
        ..Config::DEFAULT
    };
    let size = unsafe { ddog_sgc_cache_mapping_size(&config) };
    assert_eq!(size, config.resolve().unwrap().mapping_size());
    let pages = ZeroedPages::new(size);

    let mut cache = std::ptr::null_mut();
    let status = unsafe { ddog_sgc_cache_init_in(pages.ptr.cast(), size, &config, &mut cache) };
    assert_eq!(status, Status::Ok);
    let cache = CApi(cache);
    let mut participant = cache.register().unwrap();
    assert_eq!(participant.insert(7, &value_for(7, 40)), Status::Ok);
    let mut out = [0u64; 8];
    let len = participant.lookup(7, &mut out, 64).unwrap();
    assert_eq!(words_as_bytes(&out, len), &value_for(7, 40)[..]);
    drop(participant);

    // Initialised memory is no longer zero-filled.
    let mut again = std::ptr::null_mut();
    let status = unsafe { ddog_sgc_cache_init_in(pages.ptr.cast(), size, &config, &mut again) };
    assert_eq!(status, Status::ConcurrentOperation);
    assert!(again.is_null());
    // Frees the handle only; the caller still owns (and here frees) the
    // memory.
    drop(cache);
}

#[test]
fn c_api_init_in_validates_alignment_and_size() {
    let config = Config::DEFAULT;
    let size = unsafe { ddog_sgc_cache_mapping_size(&config) };
    let pages = ZeroedPages::new(size + 4096);
    // Never written on error.
    let sentinel = std::ptr::dangling_mut::<FfiCache>();
    let init = |offset: usize, len: usize| {
        let mut out = sentinel;
        let status =
            unsafe { ddog_sgc_cache_init_in(pages.ptr.add(offset).cast(), len, &config, &mut out) };
        assert!(out == sentinel, "out written on {status:?}");
        status
    };

    // 64 bytes is enough everywhere but aarch64, whose cache lines are 128.
    for offset in [1, 8, 32] {
        assert_eq!(init(offset, size), Status::Misaligned, "offset {offset}");
    }
    let wide_line_offset = if cfg!(target_arch = "aarch64") {
        Status::Misaligned
    } else {
        Status::InsufficientCapacity // aligned; one byte short
    };
    assert_eq!(init(64, size - 1), wide_line_offset);
    assert_eq!(init(0, size - 1), Status::InsufficientCapacity);
    assert_eq!(init(0, 0), Status::InsufficientCapacity);

    let mut out = sentinel;
    let null = std::ptr::null_mut();
    assert_eq!(
        unsafe { ddog_sgc_cache_init_in(null, size, &config, &mut out) },
        Status::InvalidArgument
    );
    let invalid = Config {
        bucket_count: 12,
        ..Config::DEFAULT
    };
    assert_eq!(
        unsafe { ddog_sgc_cache_init_in(pages.ptr.cast(), size, &invalid, &mut out) },
        Status::InvalidArgument
    );
    assert!(out == sentinel);
    assert_eq!(unsafe { ddog_sgc_cache_mapping_size(&invalid) }, 0);
    assert_eq!(unsafe { ddog_sgc_cache_mapping_size(std::ptr::null()) }, 0);

    // The memory stayed zero-filled: an aligned, large enough call works.
    let mut cache = std::ptr::null_mut();
    assert_eq!(
        unsafe { ddog_sgc_cache_init_in(pages.ptr.cast(), size, &config, &mut cache) },
        Status::Ok
    );
    drop(CApi(cache));
}

#[test]
fn c_api_registry_and_config_errors() {
    assert!(matches!(
        CApi::new(Config {
            bucket_count: 12,
            ..Config::DEFAULT
        }),
        Err(Status::InvalidArgument)
    ));
    assert!(matches!(
        CApi::new(Config {
            record_area_size: 4,
            ..Config::DEFAULT
        }),
        Err(Status::InvalidArgument)
    ));
    assert!(matches!(
        CApi::new(Config {
            reservation_chunk_size: 12,
            ..Config::DEFAULT
        }),
        Err(Status::InvalidArgument)
    ));
    let cache = CApi::new(Config {
        participant_capacity: 2,
        ..Config::DEFAULT
    })
    .unwrap();
    let a = cache.register().unwrap();
    let b = cache.register().unwrap();
    // Both registrations are live (this thread): nothing to reap.
    assert!(matches!(
        cache.register(),
        Err(Status::ParticipantRegistryFull)
    ));
    drop(a);
    let c = cache.register().unwrap();
    drop((b, c));
    unsafe {
        let mut out = std::ptr::null_mut();
        assert_eq!(
            ddog_sgc_cache_new(std::ptr::null(), &mut out),
            Status::InvalidArgument
        );
        assert_eq!(
            ddog_sgc_participant_register(std::ptr::null(), &mut out.cast()),
            Status::InvalidArgument
        );
        ddog_sgc_cache_free(std::ptr::null_mut());
        ddog_sgc_participant_unregister(std::ptr::null_mut());
    }
}

/// Whether this kernel records `MADV_HUGEPAGE` on a shared anonymous
/// mapping (VmFlags `hg`). Kernels without THP, and some sandboxes,
/// reject or ignore the advice; the C API then proceeds without it.
#[cfg(target_os = "linux")]
fn kernel_records_huge_page_advice() -> Result<(), String> {
    let len = 2 << 20;
    // SAFETY: a fresh anonymous mapping, advised and unmapped here only.
    let p = unsafe {
        libc::mmap(
            std::ptr::null_mut(),
            len,
            libc::PROT_READ | libc::PROT_WRITE,
            libc::MAP_SHARED | libc::MAP_ANONYMOUS,
            -1,
            0,
        )
    };
    assert_ne!(p, libc::MAP_FAILED);
    // SAFETY: advice on the mapping created above.
    let advised = unsafe { libc::madvise(p, len, libc::MADV_HUGEPAGE) };
    let errno = std::io::Error::last_os_error();
    let start = format!("{:x}-", p as usize);
    let smaps = std::fs::read_to_string("/proc/self/smaps").unwrap();
    let flags = smaps
        .split_inclusive('\n')
        .skip_while(|line| !line.starts_with(&start))
        .find_map(|line| line.strip_prefix("VmFlags:").map(str::to_owned));
    // SAFETY: unmapping the mapping created above.
    unsafe { libc::munmap(p, len) };
    if advised == 0
        && flags
            .as_deref()
            .is_some_and(|f| f.split_whitespace().any(|f| f == "hg"))
    {
        return Ok(());
    }
    let setting = |name: &str| {
        std::fs::read_to_string(format!("/sys/kernel/mm/transparent_hugepage/{name}"))
            .map_or_else(|e| format!("({e})"), |s| s.trim().to_owned())
    };
    let kernel = std::fs::read_to_string("/proc/sys/kernel/osrelease").unwrap_or_default();
    Err(format!(
        "madvise returned {advised} ({errno}), VmFlags {flags:?}, kernel {}, \
         THP enabled {}, shmem_enabled {}",
        kernel.trim(),
        setting("enabled"),
        setting("shmem_enabled"),
    ))
}

/// The C API's mapping is advised `MADV_HUGEPAGE` (VmFlags `hg`), whether
/// or not the kernel's shmem THP setting then backs it with huge pages.
/// Skipped where the kernel does not record the advice at all.
#[cfg(target_os = "linux")]
#[test]
fn c_api_mapping_is_advised_huge_pages() {
    if let Err(why) = kernel_records_huge_page_advice() {
        eprintln!("skipped: this kernel does not record MADV_HUGEPAGE: {why}");
        return;
    }
    // A configuration no other test uses, so its size identifies the mapping.
    let config = Config {
        participant_capacity: 5,
        bucket_count: 1 << 13,
        max_key_size: 24,
        max_value_size: 200,
        ..Config::DEFAULT
    };
    let page = 4096;
    let size_kb = config.resolve().unwrap().mapping_size().div_ceil(page) * page / 1024;
    let _cache = CApi::new(config).unwrap();

    let smaps = std::fs::read_to_string("/proc/self/smaps").unwrap();
    let mut size = None;
    let mut found = Vec::new();
    for line in smaps.lines() {
        if let Some(kb) = line.strip_prefix("Size:") {
            size = kb.trim().trim_end_matches(" kB").parse::<usize>().ok();
        } else if let Some(flags) = line.strip_prefix("VmFlags:")
            && size == Some(size_kb)
        {
            let flags: Vec<&str> = flags.split_whitespace().collect();
            if flags.contains(&"sh") {
                found.push(flags.contains(&"hg"));
            }
        }
    }
    assert_eq!(
        found,
        [true],
        "shared mappings of {size_kb} kB (hg flag each)"
    );
}

/// Slot reaping: a thread that exits registered leaves its slot to be
/// reaped (Linux liveness backend: dead TID, then a global membarrier;
/// Windows: the joined thread's object is signalled or deleted).
#[cfg(any(target_os = "linux", windows))]
#[test]
fn c_api_reaps_a_dead_registration() {
    let cache = CApi::new(Config {
        participant_capacity: 2,
        ..Config::DEFAULT
    })
    .unwrap();
    thread::scope(|s| {
        s.spawn(|| std::mem::forget(cache.register().unwrap()));
    });
    let a = cache.register().unwrap();
    // The registry is full; the dead thread's slot is reaped. pthread_join
    // can return slightly before the kernel reports the thread dead in
    // /proc (the TID is cleared early in its exit), and until then the
    // backend conservatively reports it live: retry briefly.
    let mut attempts = 0;
    let b = loop {
        match cache.register() {
            Ok(b) => break b,
            Err(Status::ParticipantRegistryFull) if attempts < 200 => {
                attempts += 1;
                thread::sleep(std::time::Duration::from_millis(5));
            }
            Err(s) => panic!("registration failed: {s:?}"),
        }
    };
    assert!(matches!(
        cache.register(),
        Err(Status::ParticipantRegistryFull)
    ));
    drop((a, b));
}
