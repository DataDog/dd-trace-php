//! Scenario names, the run header, the results table and the JSON report.

use std::fs::File;
use std::io::{BufWriter, Write};

use shm_gen_cache::Derived;

use crate::sgc_bench::cli::Options;
use crate::sgc_bench::config::{
    BENCH_CONFIG, BUCKET_COUNT, LOOKUP_HIT_KEYS, LOOKUP_MISS_KEYS, MAX_KEY_SIZE, MAX_OCCUPANCY,
    MAX_VALUE_SIZE, MIN_KEY_SIZE, MIN_VALUE_SIZE, MISS_SKEW, MIXED_KEY_COUNTS, SKEWS,
    UNIVERSE_SIZE,
};
use crate::sgc_bench::data::Dataset;
use crate::sgc_bench::phase::ScenarioResult;

/// `1Mi`, `64Ki`, or the plain number.
fn keys_label(keys: u32) -> String {
    if keys.is_multiple_of(1 << 20) {
        format!("{}Mi", keys >> 20)
    } else if keys.is_multiple_of(1 << 10) {
        format!("{}Ki", keys >> 10)
    } else {
        keys.to_string()
    }
}

/// `group/keys[/sSKEW]/tTHREADS`; the skew is omitted when zero.
pub fn phase_name(group: &str, keys: u32, skew: f64, threads: u32) -> String {
    let mut name = format!("{group}/{}", keys_label(keys));
    if skew > 0.0 {
        name += &format!("/s{skew:.1}");
    }
    name += &format!("/t{threads}");
    name
}

/// Whether `name` contains any `--filter` substring (all, without one).
pub fn selected(opt: &Options, name: &str) -> bool {
    opt.filters.is_empty() || opt.filters.iter().any(|f| name.contains(f.as_str()))
}

/// `--list`: the selected scenarios, in run order.
pub fn print_scenarios(opt: &Options) {
    let mut names = Vec::new();
    for k in MIXED_KEY_COUNTS {
        for s in SKEWS {
            for &t in &opt.threads {
                names.push(phase_name("mixed", k, s, t));
            }
        }
    }
    for s in SKEWS {
        for &t in &opt.threads {
            names.push(phase_name("lookup_hit", LOOKUP_HIT_KEYS, s, t));
        }
    }
    for &t in &opt.threads {
        names.push(phase_name("lookup_miss", LOOKUP_MISS_KEYS, MISS_SKEW, t));
    }
    for &t in &opt.threads {
        names.push(phase_name("insert_new", UNIVERSE_SIZE, 0.0, t));
    }
    for n in names.iter().filter(|n| selected(opt, n)) {
        println!("{n}");
    }
}

fn on_off(b: bool) -> &'static str {
    if b { "on" } else { "off" }
}

pub fn print_header(opt: &Options, data: &Dataset, derived: &Derived) {
    let n = data.records.len() as f64;
    let kmean = data
        .records
        .iter()
        .map(|r| f64::from(r.key_len))
        .sum::<f64>()
        / n;
    let vmean = data
        .records
        .iter()
        .map(|r| f64::from(r.value_len))
        .sum::<f64>()
        / n;
    println!(
        "sgc_bench: buckets={BUCKET_COUNT} max_occupancy={MAX_OCCUPANCY} participants={} \
         max_key={MAX_KEY_SIZE} max_value={MAX_VALUE_SIZE} record_area={} B/arena \
         mapping={:.1} MiB backend=rust-api",
        BENCH_CONFIG.participant_capacity,
        BENCH_CONFIG.record_area_size,
        derived.mapping_size() as f64 / f64::from(1u32 << 20),
    );
    println!(
        "data: {UNIVERSE_SIZE} keys, key bytes mean {kmean:.1} [{MIN_KEY_SIZE},{MAX_KEY_SIZE}], \
         value bytes mean {vmean:.1} [{MIN_VALUE_SIZE},{MAX_VALUE_SIZE}]"
    );
    println!(
        "run: reps={} ops/thread/rep={} warmup={} model={} verify={} check_pinning={} \
         huge_pages={}\n",
        opt.reps,
        opt.ops_per_thread,
        opt.warmup_ops,
        on_off(opt.model),
        on_off(opt.verify),
        on_off(opt.check_pinning),
        on_off(opt.huge_pages),
    );
}

struct RepStats {
    median_mops: f64,
    min_mops: f64,
    max_mops: f64,
    mad_pct: f64,
}

fn median_of(mut v: Vec<f64>) -> f64 {
    v.sort_by(f64::total_cmp);
    let n = v.len();
    match n {
        0 => 0.0,
        _ if n % 2 == 1 => v[n / 2],
        _ => (v[n / 2 - 1] + v[n / 2]) / 2.0,
    }
}

/// Per-repetition throughput (aggregate over threads, Mops/s).
fn rep_mops(r: &ScenarioResult) -> impl Iterator<Item = f64> + '_ {
    r.wall_ns.iter().map(|&ns| r.ops_per_rep as f64 * 1e3 / ns)
}

fn stats_of(r: &ScenarioResult) -> RepStats {
    let mops: Vec<f64> = rep_mops(r).collect();
    let med = median_of(mops.clone());
    let dev: Vec<f64> = mops.iter().map(|m| (m - med).abs()).collect();
    RepStats {
        median_mops: med,
        min_mops: mops.iter().copied().reduce(f64::min).unwrap_or(0.0),
        max_mops: mops.iter().copied().reduce(f64::max).unwrap_or(0.0),
        mad_pct: if med > 0.0 {
            100.0 * median_of(dev) / med
        } else {
            0.0
        },
    }
}

fn total_wall_s(r: &ScenarioResult) -> f64 {
    r.wall_ns.iter().fold(0.0, |a, &b| a + b) / 1e9
}

fn measured_ops(r: &ScenarioResult) -> u64 {
    r.ops_per_rep * r.wall_ns.len() as u64
}

fn ratio(a: u64, b: u64) -> f64 {
    if b == 0 { 0.0 } else { a as f64 / b as f64 }
}

pub fn print_table(results: &[ScenarioResult]) {
    println!(
        "{:<26} {:>8} {:>17} {:>5} {:>8} {:>6} {:>6} {:>8} {:>7} {:>8} {:>6} {:>6} {:>6}",
        "scenario",
        "Mops/s",
        "[min - max]",
        "MAD%",
        "ns/op/t",
        "hit%",
        "ins%",
        "Mins/s",
        "rot/rep",
        "rot/s",
        "~hit%",
        "~prm%",
        "errs"
    );
    for r in results {
        let st = stats_of(r);
        let t = &r.totals;
        let ops = measured_ops(r);
        let wall = total_wall_s(r);
        let ns_per_op_thread = if st.median_mops > 0.0 {
            1e3 * f64::from(r.threads) / st.median_mops
        } else {
            0.0
        };
        let (rot_rep, rot, mhit, mprom) = match &r.model {
            Some(m) => (
                format!("{:.1}", m.rotations as f64 / r.wall_ns.len() as f64),
                format!("{:.1}", m.rotations as f64 / wall),
                format!("{:.2}", 100.0 * ratio(m.hits, m.ops)),
                format!("{:.2}", 100.0 * ratio(m.promotions, m.ops)),
            ),
            None => ("-".into(), "-".into(), "-".into(), "-".into()),
        };
        println!(
            "{:<26} {:>8.2} {:>8.2} - {:>6.2} {:>5.1} {:>8.1} {:>6.2} {:>6.2} {:>8.3} {:>7} {:>8} \
             {:>6} {:>6} {:>6}",
            r.name,
            st.median_mops,
            st.min_mops,
            st.max_mops,
            st.mad_pct,
            ns_per_op_thread,
            100.0 * ratio(t.hits, t.hits + t.misses),
            100.0 * ratio(t.inserts, ops),
            t.inserts as f64 / wall / 1e6,
            rot_rep,
            rot,
            mhit,
            mprom,
            t.lookup_errors + t.insert_errors
        );
    }
    println!(
        "\nMops/s: aggregate over threads, median of reps. ns/op/t: per thread at the median.\n\
         hit%/ins%: measured over all reps. rot/rep /rot/s/~hit%/~prm% (promotions): reference \
         model replay\n(exact for t1, round-robin interleaving otherwise); compare ~hit% with \
         hit%."
    );
}

/// Writes the `--json` report; `false` after printing why it failed.
pub fn write_json(
    path: &str,
    opt: &Options,
    results: &[ScenarioResult],
    thp_min_coverage: f64,
) -> bool {
    let written = File::create(path).and_then(|f| {
        let mut f = BufWriter::new(f);
        write_json_to(&mut f, opt, results, thp_min_coverage)?;
        f.into_inner().map_err(|e| e.into_error())?;
        Ok(())
    });
    match written {
        Ok(()) => true,
        Err(e) => {
            eprintln!("{path}: {e}");
            false
        }
    }
}

fn write_json_to(
    f: &mut impl Write,
    opt: &Options,
    results: &[ScenarioResult],
    thp_min_coverage: f64,
) -> std::io::Result<()> {
    write!(
        f,
        "{{\"config\":{{\"bucket_count\":{BUCKET_COUNT},\"max_occupancy\":{MAX_OCCUPANCY},\
         \"max_key_size\":{MAX_KEY_SIZE},\"max_value_size\":{MAX_VALUE_SIZE},\
         \"participant_capacity\":{},\"universe\":{UNIVERSE_SIZE},\"reps\":{},\
         \"ops_per_thread\":{},\"warmup_ops\":{},\"check_pinning\":{},\"huge_pages\":{},\
         \"thp_min_coverage\":{:.4}}},\n\"results\":[",
        BENCH_CONFIG.participant_capacity,
        opt.reps,
        opt.ops_per_thread,
        opt.warmup_ops,
        opt.check_pinning,
        opt.huge_pages,
        if opt.huge_pages {
            thp_min_coverage
        } else {
            0.0
        },
    )?;
    for (i, r) in results.iter().enumerate() {
        let st = stats_of(r);
        let t = &r.totals;
        let ops = measured_ops(r);
        let wall = total_wall_s(r);
        write!(
            f,
            "{}\n{{\"name\":\"{}\",\"group\":\"{}\",\"keys\":{},",
            if i == 0 { "" } else { "," },
            r.name,
            r.group,
            r.keys
        )?;
        write!(
            f,
            "\"skew\":{:.2},\"threads\":{},\"ops_per_rep\":{},\"median_mops\":{:.4},\
             \"min_mops\":{:.4},\"max_mops\":{:.4},\"mad_pct\":{:.3},\"ns_per_op\":{:.3},\
             \"ns_per_op_thread\":{:.3},",
            r.skew,
            r.threads,
            r.ops_per_rep,
            st.median_mops,
            st.min_mops,
            st.max_mops,
            st.mad_pct,
            1e3 / st.median_mops,
            1e3 * f64::from(r.threads) / st.median_mops
        )?;
        write!(f, "\"rep_mops\":[")?;
        for (j, m) in rep_mops(r).enumerate() {
            write!(f, "{}{m:.4}", if j == 0 { "" } else { "," })?;
        }
        write!(
            f,
            "],\"hit_rate\":{:.6},\"insert_rate\":{:.6},\"inserts_per_s\":{:.1},\
             \"lookup_errors\":{},\"insert_errors\":{}",
            ratio(t.hits, t.hits + t.misses),
            ratio(t.inserts, ops),
            t.inserts as f64 / wall,
            t.lookup_errors,
            t.insert_errors
        )?;
        if let Some(m) = &r.model {
            write!(
                f,
                ",\"model\":{{\"hit_rate\":{:.6},\"promotion_rate\":{:.6},\"rotations\":{},\
                 \"rotations_per_rep\":{:.3},\"rotations_per_s\":{:.3},\"replacements\":{}}}",
                ratio(m.hits, m.ops),
                ratio(m.promotions, m.ops),
                m.rotations,
                m.rotations as f64 / r.wall_ns.len() as f64,
                m.rotations as f64 / wall,
                m.replacements
            )?;
        }
        write!(f, "}}")?;
    }
    writeln!(f, "\n]}}")
}
