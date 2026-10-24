//! Timed execution of one phase: one participant per worker thread, a
//! common start per repetition, per-repetition wall times.

use std::sync::atomic::AtomicU32;
use std::sync::atomic::AtomicU64;
use std::sync::atomic::Ordering::{AcqRel, Acquire, Relaxed, Release};
use std::thread::{self, Thread};

use crate::sgc_bench::api::{Backend, Lookup, Participant};
use crate::sgc_bench::cli::Options;
use crate::sgc_bench::config::{ValueBuffer, value_buffer};
use crate::sgc_bench::data::{Dataset, SourceBuffers};
use crate::sgc_bench::model::{GenerationModel, ModelCounts, OpKind};
use crate::sgc_bench::platform::{
    NO_CPU_LOCATION, cpu_cluster, cpu_number, current_cpu_location, now_ns, pin_worker,
    raise_thread_priority, spin_pause,
};
use crate::sgc_bench::rng::{SplitMix64, ZipfSampler, fmix64};

/// One worker's (or a phase's total) counts over the measured repetitions.
#[derive(Clone, Copy, Debug)]
pub struct ThreadCounters {
    pub hits: u64,
    pub misses: u64,
    pub inserts: u64,
    pub lookup_errors: u64,
    pub insert_errors: u64,
    pub bad_values: u64,
    pub sink: u64,
    pub pinned_location: u32,
    pub unexpected_location: u32,
    pub unexpected_location_rep: u32,
    pub registration_failed: bool,
}

impl Default for ThreadCounters {
    fn default() -> Self {
        ThreadCounters {
            hits: 0,
            misses: 0,
            inserts: 0,
            lookup_errors: 0,
            insert_errors: 0,
            bad_values: 0,
            sink: 0,
            pinned_location: NO_CPU_LOCATION,
            unexpected_location: NO_CPU_LOCATION,
            unexpected_location_rep: 0,
            registration_failed: false,
        }
    }
}

impl ThreadCounters {
    fn accumulate(&mut self, t: &ThreadCounters) {
        self.hits += t.hits;
        self.misses += t.misses;
        self.inserts += t.inserts;
        self.lookup_errors += t.lookup_errors;
        self.insert_errors += t.insert_errors;
        self.bad_values += t.bad_values;
        self.sink = self.sink.wrapping_add(t.sink);
        self.registration_failed |= t.registration_failed;
    }
}

/// The result of one phase.
pub struct ScenarioResult {
    pub name: String,
    pub group: &'static str,
    pub keys: u32,
    pub skew: f64,
    pub threads: u32,
    pub ops_per_rep: u64,
    /// Per measured repetition.
    pub wall_ns: Vec<f64>,
    /// Measured repetitions only.
    pub totals: ThreadCounters,
    pub model: Option<ModelCounts>,
    pub cpu_locations: Vec<u32>,
    pub pinning_failed: bool,
}

/// How each thread's operation stream is produced.
pub enum StreamSource<'a> {
    /// Independent Zipf draws per thread.
    Zipf { sampler: &'a ZipfSampler, seed: u64 },
    /// Thread t walks its own contiguous slice of a shuffled pool,
    /// cyclically. The pool is so much larger than the two generations that
    /// a key has been rotated out long before its slice wraps around: every
    /// insert is new.
    Cyclic { pool: &'a [u32] },
}

impl StreamSource<'_> {
    /// Fills thread `thread`'s whole stream.
    pub fn generate(&self, thread: u32, thread_count: u32, out: &mut [u32]) {
        match *self {
            StreamSource::Zipf { sampler, seed } => {
                let mut rng = SplitMix64::new(fmix64(
                    seed ^ (u64::from(thread_count) << 32) ^ u64::from(thread),
                ));
                for v in out {
                    *v = sampler.sample(rng.next_u64());
                }
            }
            StreamSource::Cyclic { pool } => {
                let slice = pool.len() / thread_count as usize;
                let first = slice * thread as usize;
                for (i, v) in out.iter_mut().enumerate() {
                    *v = pool[first + i % slice];
                }
            }
        }
    }
}

/// One phase: how each thread's operation stream is produced and consumed.
pub struct PhasePlan<'a> {
    pub name: String,
    pub group: &'static str,
    pub kind: OpKind,
    pub keys: u32,
    pub skew: f64,
    pub threads: u32,
    pub warmup_per_thread: u64,
    /// Fills a thread's whole stream: warmup, then reps * ops_per_thread.
    pub source: StreamSource<'a>,
}

const LOOKUP_OR_INSERT: u8 = OpKind::LookupOrInsert as u8;
const LOOKUP: u8 = OpKind::Lookup as u8;
const INSERT: u8 = OpKind::Insert as u8;

/// The timed loop, monomorphised per operation kind and verification mode.
#[inline]
fn run_ops<L: Participant, const KIND: u8, const VERIFY: bool>(
    participant: &mut L,
    data: &Dataset,
    seq: &[u32],
    out: &mut ValueBuffer,
    c: &mut ThreadCounters,
) {
    let mut hits = 0u64;
    let mut misses = 0u64;
    let mut inserts = 0u64;
    let mut lookup_errors = 0u64;
    let mut insert_errors = 0u64;
    let mut bad_values = 0u64;
    let mut sink = c.sink;
    let mut buf = SourceBuffers::new();
    for &index in seq {
        let r = &data.records[index as usize];
        let key = data.key(index, &mut buf.key);
        if KIND == INSERT {
            inserts += 1;
            if !participant.insert(r.hash, key, data.value(index, &mut buf.value)) {
                insert_errors += 1;
            }
            continue;
        }
        let v = match participant.lookup(r.hash, key, out) {
            Lookup::Error => {
                lookup_errors += 1;
                continue;
            }
            Lookup::Hit(v) => v,
            Lookup::Miss => {
                misses += 1;
                if KIND == LOOKUP_OR_INSERT {
                    inserts += 1;
                    if !participant.insert(r.hash, key, data.value(index, &mut buf.value)) {
                        insert_errors += 1;
                    }
                }
                continue;
            }
        };
        hits += 1;
        if VERIFY {
            bad_values += u64::from(v != data.value(index, &mut buf.value));
        } else {
            bad_values += u64::from(v.len() != usize::from(r.value_len));
        }
        sink = sink.wrapping_add(v.len() as u64 + u64::from(v[0]));
    }
    c.hits += hits;
    c.misses += misses;
    c.inserts += inserts;
    c.lookup_errors += lookup_errors;
    c.insert_errors += insert_errors;
    c.bad_values += bad_values;
    c.sink = sink;
}

fn dispatch_ops<L: Participant, const VERIFY: bool>(
    kind: OpKind,
    participant: &mut L,
    data: &Dataset,
    seq: &[u32],
    out: &mut ValueBuffer,
    c: &mut ThreadCounters,
) {
    match kind {
        OpKind::LookupOrInsert => {
            run_ops::<L, LOOKUP_OR_INSERT, VERIFY>(participant, data, seq, out, c)
        }
        OpKind::Lookup => run_ops::<L, LOOKUP, VERIFY>(participant, data, seq, out, c),
        OpKind::Insert => run_ops::<L, INSERT, VERIFY>(participant, data, seq, out, c),
    }
}

/// Cache-line-aligned, so the two counters never share a line.
#[cfg_attr(target_arch = "aarch64", repr(align(128)))]
#[cfg_attr(not(target_arch = "aarch64"), repr(align(64)))]
struct Padded<T>(T);

/// Stage 0 is the untimed warmup; stages 1..=reps are the measured
/// repetitions. Workers spin between stages (the gaps are microseconds);
/// the coordinating thread sleeps (parks) until the last worker of a
/// stage arrives, so it never steals a core.
struct PhaseSync {
    stage: Padded<AtomicU32>,
    arrived: Padded<AtomicU32>,
    coordinator: Thread,
}

impl PhaseSync {
    fn new() -> Self {
        PhaseSync {
            stage: Padded(AtomicU32::new(0)),
            arrived: Padded(AtomicU32::new(0)),
            coordinator: thread::current(),
        }
    }

    /// Called by the coordinator: returns once `target` arrivals happened.
    fn wait_arrivals(&self, target: u32) {
        while self.arrived.0.load(Acquire) < target {
            thread::park();
        }
    }

    /// Called by a worker; the last of `participants` arrivals wakes the
    /// coordinator.
    fn arrive(&self, participants: u32) {
        let arrived = self.arrived.0.fetch_add(1, AcqRel) + 1;
        if arrived.is_multiple_of(participants) {
            self.coordinator.unpark();
        }
    }
}

/// Runs a phase on `c` with `plan.threads` workers, each with its own
/// participant, and, with a model, replays the streams through it.
pub fn run_phase<B: Backend>(
    opt: &Options,
    data: &Dataset,
    c: B,
    plan: &PhasePlan<'_>,
    model: Option<&mut GenerationModel>,
) -> ScenarioResult {
    let nthreads = plan.threads;
    let warm = plan.warmup_per_thread;
    let per_rep = opt.ops_per_thread;
    let stream_len = (warm + per_rep * u64::from(opt.reps)) as usize;
    let stages = opt.reps + 1;

    let end_ns: Vec<AtomicU64> = (0..nthreads as usize * stages as usize)
        .map(|_| AtomicU64::new(0))
        .collect();
    let sync = PhaseSync::new();

    let mut result = ScenarioResult {
        name: plan.name.clone(),
        group: plan.group,
        keys: plan.keys,
        skew: plan.skew,
        threads: nthreads,
        ops_per_rep: per_rep * u64::from(nthreads),
        wall_ns: Vec::with_capacity(opt.reps as usize),
        totals: ThreadCounters::default(),
        model: None,
        cpu_locations: Vec::new(),
        pinning_failed: false,
    };

    let worker = |t: u32| -> (Vec<u32>, ThreadCounters) {
        raise_thread_priority();
        pin_worker(opt.pin, t);
        let mut seq = vec![0u32; stream_len];
        plan.source.generate(t, nthreads, &mut seq);
        let mut counters = ThreadCounters::default();
        let mut registration = c.register().ok();
        if registration.is_none() {
            counters.registration_failed = true;
        }
        let mut out = value_buffer();
        sync.arrive(nthreads);
        let mut discard = ThreadCounters::default();
        let check_cpu_location = |counters: &mut ThreadCounters, repetition: u32| {
            if !opt.check_pinning || repetition == 0 {
                return;
            }
            let location = current_cpu_location();
            if counters.pinned_location == NO_CPU_LOCATION {
                counters.pinned_location = location;
            } else if location != counters.pinned_location
                && counters.unexpected_location == NO_CPU_LOCATION
            {
                counters.unexpected_location = location;
                counters.unexpected_location_rep = repetition;
            }
        };
        for s in 0..stages {
            while sync.stage.0.load(Acquire) <= s {
                spin_pause();
            }
            check_cpu_location(&mut counters, s);
            let (begin, len) = if s == 0 {
                (0, warm)
            } else {
                (warm + u64::from(s - 1) * per_rep, per_rep)
            };
            if let Some(participant) = registration.as_mut() {
                let slice = &seq[begin as usize..(begin + len) as usize];
                let into = if s == 0 { &mut discard } else { &mut counters };
                if opt.verify {
                    dispatch_ops::<_, true>(plan.kind, participant, data, slice, &mut out, into);
                } else {
                    dispatch_ops::<_, false>(plan.kind, participant, data, slice, &mut out, into);
                }
            }
            check_cpu_location(&mut counters, s);
            end_ns[t as usize * stages as usize + s as usize].store(now_ns(), Relaxed);
            sync.arrive(nthreads);
        }
        counters.sink = counters.sink.wrapping_add(discard.sink);
        std::hint::black_box(counters.sink);
        drop(registration);
        (seq, counters)
    };

    let per_thread: Vec<(Vec<u32>, ThreadCounters)> = thread::scope(|scope| {
        let worker = &worker;
        let handles: Vec<_> = (0..nthreads)
            .map(|t| scope.spawn(move || worker(t)))
            .collect();
        sync.wait_arrivals(nthreads);
        for s in 0..stages {
            let start = now_ns();
            sync.stage.0.store(s + 1, Release);
            sync.wait_arrivals(nthreads * (s + 2));
            if s == 0 {
                continue;
            }
            let last = (0..nthreads as usize)
                .map(|t| end_ns[t * stages as usize + s as usize].load(Relaxed))
                .fold(start, u64::max);
            result.wall_ns.push((last - start) as f64);
        }
        handles
            .into_iter()
            .map(|h| h.join().expect("worker panicked"))
            .collect()
    });

    for (_, tc) in &per_thread {
        result.totals.accumulate(tc);
    }
    if opt.check_pinning {
        report_placement(&mut result, &per_thread);
    }
    if let Some(model) = model {
        // Replay warmup and then the measured window, each interleaved
        // round-robin, counting only the measured window.
        let replay = |model: &mut GenerationModel, begin: usize, len: usize| {
            for i in begin..begin + len {
                for (seq, _) in &per_thread {
                    model.apply(plan.kind, seq[i]);
                }
            }
        };
        replay(model, 0, warm as usize);
        let before = model.counts;
        replay(
            model,
            warm as usize,
            (per_rep * u64::from(opt.reps)) as usize,
        );
        result.model = Some(model.counts.since(&before));
    }
    result
}

/// `--check-pinning`: records each worker's CPU and fails the phase if a
/// worker moved or two shared a CPU.
fn report_placement(result: &mut ScenarioResult, per_thread: &[(Vec<u32>, ThreadCounters)]) {
    let mut line = format!("  placement {}:", result.name);
    for (t, (_, counter)) in per_thread.iter().enumerate() {
        result.cpu_locations.push(counter.pinned_location);
        line += &format!(
            " w{t}=c{}/cpu{}",
            cpu_cluster(counter.pinned_location),
            cpu_number(counter.pinned_location)
        );
        if counter.unexpected_location != NO_CPU_LOCATION {
            result.pinning_failed = true;
        }
    }
    let locations = &result.cpu_locations;
    for i in 0..locations.len() {
        for j in i + 1..locations.len() {
            if locations[i] == locations[j] {
                result.pinning_failed = true;
                line += &format!(
                    "\n    workers {i} and {j} share CPU {}",
                    cpu_number(locations[i])
                );
            }
        }
    }
    for (t, (_, counter)) in per_thread.iter().enumerate() {
        if counter.unexpected_location != NO_CPU_LOCATION {
            line += &format!(
                "\n    worker {t} moved in repetition {}: c{}/cpu{} -> c{}/cpu{}",
                counter.unexpected_location_rep,
                cpu_cluster(counter.pinned_location),
                cpu_number(counter.pinned_location),
                cpu_cluster(counter.unexpected_location),
                cpu_number(counter.unexpected_location)
            );
        }
    }
    let verdict = if result.pinning_failed {
        "FAILED"
    } else {
        "stable"
    };
    eprintln!("{line} {verdict}");
}
