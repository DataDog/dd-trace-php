//! Scenario families. Within a family the cache persists across thread
//! counts, so each later phase starts from the steady state the previous
//! one left; only the first phase needs the long warmup.

use std::cell::Cell;

use crate::sgc_bench::api::{Api, Lookup, Participant};
use crate::sgc_bench::cli::Options;
use crate::sgc_bench::config::{
    BUCKET_COUNT, LOOKUP_HIT_KEYS, LOOKUP_MISS_KEYS, MAX_OCCUPANCY, MISS_SKEW, MIXED_KEY_COUNTS,
    SKEWS, UNIVERSE_SIZE, value_buffer,
};
use crate::sgc_bench::data::{Dataset, SourceBuffers};
use crate::sgc_bench::mapping::{MappedCache, insert_all, register_or_die};
use crate::sgc_bench::model::{GenerationModel, ModelCounts, OpKind};
use crate::sgc_bench::phase::{PhasePlan, ScenarioResult, StreamSource, run_phase};
use crate::sgc_bench::report::{phase_name, selected};
use crate::sgc_bench::rng::{ZipfSampler, fmix64, iota_from, shuffle};

/// What every family needs: options, data, the API that initializes
/// caches, and the lowest THP-backed fraction seen over the run's mappings.
pub struct Context<'a, A: Api> {
    opt: &'a Options,
    data: &'a Dataset,
    api: A,
    thp_min_coverage: Cell<f64>,
}

impl<'a, A: Api> Context<'a, A> {
    pub fn new(opt: &'a Options, data: &'a Dataset, api: A) -> Self {
        Context {
            opt,
            data,
            api,
            thp_min_coverage: Cell::new(1.0),
        }
    }

    pub fn thp_min_coverage(&self) -> f64 {
        self.thp_min_coverage.get()
    }

    /// A fresh, initialized cache mapping.
    fn map_cache(&self) -> MappedCache<A> {
        let mc = MappedCache::new(self.opt.huge_pages, self.api);
        if let Some(coverage) = mc.thp_coverage() {
            self.thp_min_coverage
                .set(self.thp_min_coverage.get().min(coverage));
        }
        mc
    }

    fn new_model(&self) -> Option<GenerationModel> {
        self.opt
            .model
            .then(|| GenerationModel::new(UNIVERSE_SIZE as usize))
    }
}

fn family_selected(opt: &Options, group: &str, keys: u32, skew: f64) -> bool {
    opt.threads
        .iter()
        .any(|&t| selected(opt, &phase_name(group, keys, skew, t)))
}

/// The family's stream seed.
fn family_seed(first_or_count: u32, skew: f64) -> u64 {
    fmix64(u64::from(first_or_count) ^ skew.to_bits())
}

/// Zipf(`skew`) over keys `first..first + count`, ranks shuffled by `seed`.
fn zipf_over(first: u32, count: u32, skew: f64, seed: u64) -> ZipfSampler {
    let mut rank_to_key = iota_from(first, count);
    shuffle(&mut rank_to_key, seed);
    ZipfSampler::new(rank_to_key, skew)
}

/// Lookup, insert on miss, over Zipf-distributed key sets.
pub fn run_mixed<A: Api>(ctx: &Context<'_, A>, results: &mut Vec<ScenarioResult>) {
    let opt = ctx.opt;
    for keys in MIXED_KEY_COUNTS {
        for s in SKEWS {
            if !family_selected(opt, "mixed", keys, s) {
                continue;
            }
            let seed = family_seed(keys, s);
            let sampler = zipf_over(0, keys, s, seed);

            let mc = ctx.map_cache();
            let mut model = ctx.new_model();
            // Shortcut towards steady state: insert the hottest 1.5
            // generations' worth of keys, coldest first, so the hottest keys
            // end up in the current generation. Warmup does the rest.
            let prefill = (keys as usize).min((MAX_OCCUPANCY + MAX_OCCUPANCY / 2) as usize);
            let order: Vec<u32> = (0..prefill)
                .map(|i| sampler.key_of_rank(prefill - 1 - i))
                .collect();
            insert_all(
                &mut register_or_die(mc.get()),
                ctx.data,
                &order,
                model.as_mut(),
            );

            let mut first = true;
            for &t in &opt.threads {
                let name = phase_name("mixed", keys, s, t);
                if !selected(opt, &name) {
                    continue;
                }
                let warm_total = if first {
                    opt.warmup_ops
                } else {
                    opt.warmup_ops / 4
                };
                first = false;
                let plan = PhasePlan {
                    name,
                    group: "mixed",
                    kind: OpKind::LookupOrInsert,
                    keys,
                    skew: s,
                    threads: t,
                    warmup_per_thread: warm_total / u64::from(t),
                    source: StreamSource::Zipf {
                        sampler: &sampler,
                        seed,
                    },
                };
                results.push(run_phase(opt, ctx.data, mc.get(), &plan, model.as_mut()));
                eprintln!("  done {}", plan.name);
            }
        }
    }
}

/// Lookups only: a hit set resident in the current generation, and a miss
/// set never inserted.
pub fn run_lookup<A: Api>(ctx: &Context<'_, A>, results: &mut Vec<ScenarioResult>) {
    let (opt, data) = (ctx.opt, ctx.data);
    let any = SKEWS
        .iter()
        .any(|&s| family_selected(opt, "lookup_hit", LOOKUP_HIT_KEYS, s))
        || family_selected(opt, "lookup_miss", LOOKUP_MISS_KEYS, MISS_SKEW);
    if !any {
        return;
    }

    // Dataset layout: [0, A) hit set, [A, A + F) filler, then the miss set.
    // Filler reaches the rotation target, moving that generation into the
    // previous arena; the hit set then fills the current one. Keep one
    // setup registration across both batches so its process-local
    // occupancy estimate retains the samples that brought the first arena
    // to its target. Hits never promote during the timed phase. A miss
    // probes the current generation's roughly 50%-occupied hash table,
    // followed by the previous generation's roughly 70%-occupied hash
    // table.
    let filler_first = LOOKUP_HIT_KEYS;
    let miss_first = filler_first + MAX_OCCUPANCY;
    let mc = ctx.map_cache();
    {
        let mut participant = register_or_die(mc.get());
        insert_all(
            &mut participant,
            data,
            &iota_from(filler_first, MAX_OCCUPANCY),
            None,
        );
        insert_all(&mut participant, data, &iota_from(0, LOOKUP_HIT_KEYS), None);
        let mut out = value_buffer();
        let mut buf = SourceBuffers::new();
        for k in 0..miss_first + LOOKUP_MISS_KEYS {
            if (filler_first..miss_first).contains(&k) {
                continue; // a filler lookup would promote it
            }
            let r = &data.records[k as usize];
            let want_hit = k < LOOKUP_HIT_KEYS;
            match participant.lookup(r.hash, data.key(k, &mut buf.key), &mut out) {
                Lookup::Hit(_) if want_hit => {}
                Lookup::Miss if !want_hit => {}
                _ => {
                    eprintln!("lookup setup: unexpected state");
                    std::process::abort();
                }
            }
        }
    }

    let run_set = |results: &mut Vec<ScenarioResult>,
                   group: &'static str,
                   first_key: u32,
                   keys: u32,
                   s: f64| {
        let seed = family_seed(first_key, s);
        let sampler = zipf_over(first_key, keys, s, seed);
        for &t in &opt.threads {
            let name = phase_name(group, keys, s, t);
            if !selected(opt, &name) {
                continue;
            }
            let plan = PhasePlan {
                name,
                group,
                kind: OpKind::Lookup,
                keys,
                skew: s,
                threads: t,
                warmup_per_thread: opt.ops_per_thread / 2,
                source: StreamSource::Zipf {
                    sampler: &sampler,
                    seed,
                },
            };
            // State never changes, so a model would only restate the
            // obvious: no promotions, inserts or rotations.
            let mut r = run_phase(opt, data, mc.get(), &plan, None);
            if opt.model {
                r.model = Some(ModelCounts {
                    ops: r.ops_per_rep * u64::from(opt.reps),
                    hits: r.totals.hits,
                    ..ModelCounts::default()
                });
            }
            results.push(r);
            eprintln!("  done {}", plan.name);
        }
    };
    for s in SKEWS {
        run_set(results, "lookup_hit", 0, LOOKUP_HIT_KEYS, s);
    }
    run_set(
        results,
        "lookup_miss",
        miss_first,
        LOOKUP_MISS_KEYS,
        MISS_SKEW,
    );
}

/// Inserts of keys always new to the cache.
pub fn run_insert<A: Api>(ctx: &Context<'_, A>, results: &mut Vec<ScenarioResult>) {
    let opt = ctx.opt;
    if !family_selected(opt, "insert_new", UNIVERSE_SIZE, 0.0) {
        return;
    }
    let mut pool = iota_from(0, UNIVERSE_SIZE);
    shuffle(&mut pool, 0x1257);
    for &t in &opt.threads {
        let name = phase_name("insert_new", UNIVERSE_SIZE, 0.0, t);
        if !selected(opt, &name) {
            continue;
        }
        // A fresh cache per thread count keeps every insert new regardless
        // of where the previous phase's slices stopped.
        let mc = ctx.map_cache();
        let mut model = ctx.new_model();
        let plan = PhasePlan {
            name,
            group: "insert_new",
            kind: OpKind::Insert,
            keys: UNIVERSE_SIZE,
            skew: 0.0,
            threads: t,
            // Fill both generations so rotation is already periodic.
            warmup_per_thread: 2 * u64::from(BUCKET_COUNT) / u64::from(t),
            source: StreamSource::Cyclic { pool: &pool },
        };
        results.push(run_phase(opt, ctx.data, mc.get(), &plan, model.as_mut()));
        eprintln!("  done {}", plan.name);
    }
}
