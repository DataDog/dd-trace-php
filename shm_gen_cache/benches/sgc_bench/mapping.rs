//! A fresh cache mapping per scenario family, initialized the way a user
//! would: map anonymous memory, then initialize the cache in it
//! (`Cache::initialize` or `ddog_sgc_cache_init_in`, see [`Api`]).
//! Initialization writes every page, so no page faults happen inside timed
//! regions.
//!
//! With `--huge-pages` (Linux) the mapping is instead private anonymous
//! memory, aligned and padded to 2 MiB and advised `MADV_HUGEPAGE` before
//! initialization, so its faults allocate transparent huge pages. That is
//! the one kind of memory an unprivileged process gets THP for under the
//! usual `enabled=madvise`, `shmem_enabled=never` settings; the bench is one
//! process, so its threads still share the cache.

use std::ptr::NonNull;

use crate::sgc_bench::api::{Api, Backend, Participant};
use crate::sgc_bench::data::{Dataset, SourceBuffers};
use crate::sgc_bench::model::{GenerationModel, OpKind};

pub struct MappedCache<A: Api> {
    base: NonNull<u8>,
    base_len: usize,
    api: A,
    cache: A::Cache,
    /// The share of the mapping found THP-backed right after
    /// initialization (`--huge-pages` only).
    thp_coverage: Option<f64>,
}

impl<A: Api> MappedCache<A> {
    pub fn new(huge_pages: bool, api: A) -> Self {
        let size = api.mapping_size();
        let mapping = if huge_pages {
            Mapping::huge(size)
        } else {
            Mapping::shared(size)
        };
        // SAFETY: the mapping is fresh anonymous memory (zero-filled),
        // page-aligned, at least `size` bytes, and stays mapped until drop,
        // after every participant (see `get`).
        let cache = unsafe { api.initialize(mapping.cache.as_ptr(), size) };
        let thp_coverage = mapping
            .huge_len
            .map(|len| thp_coverage(mapping.cache.as_ptr(), len));
        MappedCache {
            base: mapping.base,
            base_len: mapping.base_len,
            api,
            cache,
            thp_coverage,
        }
    }

    /// The cache. Its participants must be dropped before `self`: every
    /// phase drops them before returning.
    pub fn get(&self) -> A::Cache {
        self.cache
    }

    pub fn thp_coverage(&self) -> Option<f64> {
        self.thp_coverage
    }
}

impl<A: Api> Drop for MappedCache<A> {
    fn drop(&mut self) {
        // SAFETY: no participant is left (see `get`); then the mapping made
        // in `new`, which nothing uses any more.
        unsafe {
            self.api.release(self.cache);
            libc::munmap(self.base.as_ptr().cast(), self.base_len)
        };
    }
}

struct Mapping {
    base: NonNull<u8>,
    base_len: usize,
    /// Where the cache starts.
    cache: NonNull<u8>,
    /// The span advised `MADV_HUGEPAGE`, in whole huge pages.
    huge_len: Option<usize>,
}

impl Mapping {
    fn shared(len: usize) -> Self {
        let base = map_or_die(len, libc::MAP_SHARED | libc::MAP_ANON);
        Mapping {
            base,
            base_len: len,
            cache: base,
            huge_len: None,
        }
    }

    #[cfg(target_os = "linux")]
    fn huge(size: usize) -> Self {
        const HUGE_PAGE_SIZE: usize = 2 << 20;
        let len = size.next_multiple_of(HUGE_PAGE_SIZE);
        let base_len = len + HUGE_PAGE_SIZE;
        let base = map_or_die(base_len, libc::MAP_PRIVATE | libc::MAP_ANONYMOUS);
        let offset =
            (base.as_ptr() as usize).next_multiple_of(HUGE_PAGE_SIZE) - base.as_ptr() as usize;
        // SAFETY: offset < HUGE_PAGE_SIZE, inside the mapping.
        let cache = unsafe { base.add(offset) };
        // SAFETY: advice on whole pages of the mapping made above.
        if unsafe { libc::madvise(cache.as_ptr().cast(), len, libc::MADV_HUGEPAGE) } != 0 {
            eprintln!(
                "madvise(MADV_HUGEPAGE): {}",
                std::io::Error::last_os_error()
            );
        }
        Mapping {
            base,
            base_len,
            cache,
            huge_len: Some(len),
        }
    }

    /// Unreachable: option parsing leaves huge pages off here.
    #[cfg(not(target_os = "linux"))]
    fn huge(size: usize) -> Self {
        Self::shared(size)
    }
}

fn map_or_die(len: usize, flags: libc::c_int) -> NonNull<u8> {
    // SAFETY: a fresh anonymous mapping.
    let p = unsafe {
        libc::mmap(
            std::ptr::null_mut(),
            len,
            libc::PROT_READ | libc::PROT_WRITE,
            flags,
            -1,
            0,
        )
    };
    if p == libc::MAP_FAILED {
        eprintln!("mmap: {}", std::io::Error::last_os_error());
        std::process::abort();
    }
    NonNull::new(p.cast()).expect("mmap returned null")
}

/// The share of `[first, first + len)` backed by huge pages: AnonHugePages
/// summed over the VMAs of /proc/self/smaps overlapping it (madvise may
/// have split the mapping into several). `len` must cover whole huge pages,
/// the madvised span, so that a fully backed range reads exactly 1.
fn thp_coverage(first: *const u8, len: usize) -> f64 {
    let Ok(smaps) = std::fs::read_to_string("/proc/self/smaps") else {
        return 0.0;
    };
    let lo = first as usize;
    let hi = lo + len;
    let mut inside = false;
    let mut huge_kb: u64 = 0;
    for line in smaps.lines() {
        if let Some((start, end)) = vma_range(line) {
            inside = start < hi && lo < end;
        } else if inside && let Some(kb) = anon_huge_kb(line) {
            huge_kb += kb;
        }
    }
    (huge_kb as f64 * 1024.0 / len as f64).min(1.0)
}

/// `start-end ...`, the first line of a VMA's entry.
fn vma_range(line: &str) -> Option<(usize, usize)> {
    let (start, rest) = line.split_once('-')?;
    let end = rest.split_ascii_whitespace().next()?;
    Some((
        usize::from_str_radix(start, 16).ok()?,
        usize::from_str_radix(end, 16).ok()?,
    ))
}

/// The value of an `AnonHugePages: N kB` line.
fn anon_huge_kb(line: &str) -> Option<u64> {
    let rest = line.strip_prefix("AnonHugePages:")?.trim_start();
    let digits = rest
        .find(|c: char| !c.is_ascii_digit())
        .unwrap_or(rest.len());
    rest[..digits].parse().ok()
}

pub fn register_or_die<B: Backend>(c: B) -> B::Participant {
    match c.register() {
        Ok(participant) => participant,
        Err(e) => {
            eprintln!("participant registration failed: {e}");
            std::process::abort();
        }
    }
}

/// Untimed setup inserts from the calling thread, mirrored into `model`.
pub fn insert_all(
    participant: &mut impl Participant,
    data: &Dataset,
    keys: &[u32],
    mut model: Option<&mut GenerationModel>,
) {
    let mut buf = SourceBuffers::new();
    for &k in keys {
        let r = &data.records[k as usize];
        let key = data.key(k, &mut buf.key);
        let value = data.value(k, &mut buf.value);
        if !participant.insert(r.hash, key, value) {
            eprintln!("setup insert failed");
            std::process::abort();
        }
        if let Some(m) = model.as_deref_mut() {
            m.apply(OpKind::Insert, k);
        }
    }
}
