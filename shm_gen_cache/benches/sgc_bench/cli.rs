//! Command line.

use crate::sgc_bench::platform::{CAN_CHECK_PINNING, HUGE_PAGES_SUPPORTED};

pub struct Options {
    pub reps: u32,
    pub ops_per_thread: u64,
    pub warmup_ops: u64,
    pub threads: Vec<u32>,
    pub filters: Vec<String>,
    pub json_path: Option<String>,
    pub model: bool,
    pub verify: bool,
    pub check_pinning: bool,
    pub list: bool,
    pub pin: bool,
    pub huge_pages: bool,
}

impl Default for Options {
    fn default() -> Self {
        Options {
            reps: 7,
            ops_per_thread: 250_000,
            warmup_ops: 1_000_000,
            threads: vec![1, 4, 8],
            filters: Vec::new(),
            json_path: None,
            model: true,
            verify: false,
            check_pinning: false,
            list: false,
            pin: false,
            huge_pages: HUGE_PAGES_SUPPORTED,
        }
    }
}

const USAGE: &str = "\
usage: sgc_bench [--quick] [--reps N] [--ops N] [--warmup N]
                 [--threads 1,4,8] [--filter SUBSTR[,SUBSTR...]]
                 [--json FILE] [--no-model] [--verify] [--list]
                 [--pin] [--check-pinning]
                 [--huge-pages|--no-huge-pages]
  --ops     operations per thread per repetition (default 250000)
  --warmup  untimed operations before a family's first phase
  --quick   --reps 5 --ops 100000 (same warmup)
  --verify  compare every hit's bytes (slower; not for timing)
  --check-pinning  (Apple silicon) require stable, distinct worker CPUs
  --pin     (Linux) bind worker t to the t-th CPU of the affinity mask
  --huge-pages  (Linux) map the cache as private anonymous memory
            advised MADV_HUGEPAGE (the Linux default)
  --no-huge-pages  use a shared mapping without requesting THP
";

/// Parses the arguments (without the program name). `None` after printing
/// the problem: the caller exits with status 2.
pub fn parse_options(args: impl IntoIterator<Item = String>) -> Option<Options> {
    let mut opt = Options::default();
    let mut args = args.into_iter();
    while let Some(arg) = args.next() {
        let mut number = |at_least: u64| {
            args.next()
                .and_then(|v| parse_u64(&v))
                .filter(|&n| n >= at_least)
        };
        match arg.as_str() {
            "--quick" => {
                opt.reps = 5;
                opt.ops_per_thread = 100_000;
                opt.warmup_ops = 1_000_000;
            }
            "--reps" => opt.reps = number(1).map(|n| n as u32).or_else(fail)?,
            "--ops" => opt.ops_per_thread = number(1).or_else(fail)?,
            "--warmup" => opt.warmup_ops = number(0).or_else(fail)?,
            "--threads" => {
                let v = args.next().or_else(fail)?;
                opt.threads.clear();
                // Comma-separated: an empty string has no fields, and a
                // trailing comma adds no empty field.
                for t in v.split_terminator(',') {
                    match parse_u64(t) {
                        Some(n @ 1..=64) => opt.threads.push(n as u32),
                        _ => return fail(),
                    }
                }
            }
            "--filter" => {
                let v = args.next().or_else(fail)?;
                opt.filters = v.split_terminator(',').map(String::from).collect();
            }
            "--json" => {
                // An empty path means no JSON report.
                let v = args.next().or_else(fail)?;
                opt.json_path = Some(v).filter(|p| !p.is_empty());
            }
            "--no-model" => opt.model = false,
            "--verify" => opt.verify = true,
            "--check-pinning" => {
                if !CAN_CHECK_PINNING {
                    eprintln!("--check-pinning requires Apple silicon");
                    return None;
                }
                opt.check_pinning = true;
            }
            "--list" => opt.list = true,
            "--pin" => opt.pin = true,
            "--huge-pages" => {
                if HUGE_PAGES_SUPPORTED {
                    opt.huge_pages = true;
                } else {
                    eprintln!("--huge-pages: unsupported here; using the shared mapping");
                }
            }
            "--no-huge-pages" => opt.huge_pages = false,
            // `cargo bench` passes this to every bench binary.
            "--bench" => {}
            _ => return fail(),
        }
    }
    Some(opt)
}

/// Prints the usage; for `?` on a malformed option.
fn fail<T>() -> Option<T> {
    eprint!("{USAGE}");
    None
}

/// A non-empty string of decimal digits (wrapping on overflow).
fn parse_u64(s: &str) -> Option<u64> {
    if s.is_empty() || !s.bytes().all(|c| c.is_ascii_digit()) {
        return None;
    }
    Some(s.bytes().fold(0u64, |v, c| {
        v.wrapping_mul(10).wrapping_add(u64::from(c - b'0'))
    }))
}
