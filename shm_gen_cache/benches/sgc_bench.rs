//! Throughput suite for the two-generation cache, driven through the
//! crate's public Rust API with the configuration resolved at run time
//! ([`RuntimeParams`], occupancy mode selected from the resolved
//! configuration), the shape a production embedding uses.
//!
//! The primary workload is how the cache is used in practice: every
//! operation is a lookup; a miss is followed by an insert of that key's one
//! value. Hit rate, insert rate and rotation rate are therefore results, not
//! inputs; the only knobs are the key-set size, the popularity skew and the
//! thread count. Secondary groups isolate the read path (`lookup_hit`,
//! `lookup_miss`) and the write path (`insert_new`, which also drives
//! rotation).
//!
//! Rotations are not observable through the public API. They are estimated
//! by replaying the exact same operation streams through a reference model
//! of the documented two-generation semantics (`model.rs`). For one thread
//! the model is exact, which the measured hit count cross-checks; with
//! several threads it replays a round-robin interleaving.
//!
//! Run with `cargo bench -p shm_gen_cache --bench sgc_bench -- --help`
//! for the options; `benches/README.md` describes the output.

use std::process::ExitCode;

use shm_gen_cache::{Derived, Estimated, Exact, OccupancyMode, RuntimeParams};

use sgc_bench::cli::{self, Options};
use sgc_bench::config::{BENCH_CONFIG, BenchParams};
use sgc_bench::data::Dataset;
use sgc_bench::phase::ScenarioResult;
use sgc_bench::platform;
use sgc_bench::report;
use sgc_bench::scenarios::{self, Context};

mod sgc_bench {
    pub mod cli;
    pub mod config;
    pub mod data;
    pub mod mapping;
    pub mod model;
    pub mod phase;
    pub mod platform;
    pub mod report;
    pub mod rng;
    pub mod scenarios;
}

fn main() -> ExitCode {
    let Some(opt) = cli::parse_options(std::env::args().skip(1)) else {
        return ExitCode::from(2);
    };
    platform::raise_thread_priority();
    if opt.list {
        report::print_scenarios(&opt);
        return ExitCode::SUCCESS;
    }

    // Resolved once, on the heap like a C API handle's parameters; every
    // cache of the run reads its parameters through this one allocation.
    let derived = match BENCH_CONFIG.resolve() {
        Ok(d) => Box::new(d),
        Err(e) => {
            eprintln!("invalid configuration: {}", e.code());
            std::process::abort();
        }
    };
    let data = Dataset::build();
    report::print_header(&opt, &data, &derived);

    // The occupancy mode is a type parameter of the cache code; pick the
    // monomorphisation the resolved configuration selects, at run time.
    let (results, thp_min_coverage) = if derived.estimates_occupancy() {
        run_suite::<Estimated>(&opt, &data, &derived)
    } else {
        run_suite::<Exact>(&opt, &data, &derived)
    };

    if results.iter().any(|r| r.pinning_failed) {
        eprintln!("CPU pinning verification failed");
        return ExitCode::from(1);
    }
    report::print_table(&results);
    if opt.huge_pages {
        println!(
            "huge pages: every cache mapping >= {:.1}% THP-backed",
            100.0 * thp_min_coverage
        );
    }
    if let Some(path) = &opt.json_path
        && !report::write_json(path, &opt, &results, thp_min_coverage)
    {
        return ExitCode::from(1);
    }
    for r in &results {
        let t = &r.totals;
        if t.registration_failed || t.bad_values != 0 {
            eprintln!("{}: registration failure or bad values", r.name);
            return ExitCode::from(1);
        }
    }
    ExitCode::SUCCESS
}

/// Runs every selected scenario family in order; returns the results and
/// the lowest THP-backed fraction over the run's huge-page mappings.
fn run_suite<O: OccupancyMode + Send + Sync>(
    opt: &Options,
    data: &Dataset,
    derived: &Derived,
) -> (Vec<ScenarioResult>, f64) {
    let params: BenchParams<'_, O> = match RuntimeParams::new(derived) {
        Ok(p) => p,
        Err(e) => {
            eprintln!("invalid configuration: {}", e.code());
            std::process::abort();
        }
    };
    let ctx = Context::new(opt, data, params);
    let mut results = Vec::new();
    scenarios::run_mixed(&ctx, &mut results);
    scenarios::run_lookup(&ctx, &mut results);
    scenarios::run_insert(&ctx, &mut results);
    (results, ctx.thp_min_coverage())
}
