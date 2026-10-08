//! The GenMC suite runner (README.md). For every test program in src/bin:
//!
//! * `<test>::native`: runs the native build cargo made of the program (a
//!   smoke run; it must exit 0);
//! * `<test>::safety`: GenMC must explore every execution of the program
//!   without a violation;
//! * `<test>::witness::<NAME>`, for each `witness!("NAME", ..)` in the
//!   program: GenMC must report exactly that witness as reachable.
//!
//! A GenMC trial compiles the library, the harness and the program to LLVM
//! bitcode with the host's rustc (for the Linux target of the host's
//! architecture), then links, prunes and checks it with the LLVM tools and
//! GenMC of a container image. Libraries are built once per content hash
//! into a shared cache, so concurrent trials (one process each under
//! nextest) reuse them.

#[path = "genmc/classify.rs"]
mod classify;

use std::fmt::Write as _;
use std::fs::{self, File, OpenOptions};
use std::io::Write as _;
use std::os::unix::fs::MetadataExt as _;
use std::os::unix::process::ExitStatusExt as _;
use std::path::{Path, PathBuf};
use std::process::{Command, Stdio};
use std::sync::OnceLock;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::time::{Duration, Instant};
use std::{env, thread};

use libtest_mimic::{Arguments, Failed, Trial};
use sha2::{Digest, Sha256};

/// A test program: `src/bin/<name>.rs`, built natively by cargo as `exe`.
struct Program {
    name: &'static str,
    exe: &'static str,
}

const PROGRAMS: &[Program] = include!(concat!(env!("OUT_DIR"), "/programs.rs"));

/// Programs whose GenMC trials are ignored by default (run them with
/// `--run-ignored`): their exploration takes minutes even with many GenMC
/// threads. Their native trials still run.
const GENMC_IGNORED: &[&str] = &["lookup_during_reuse"];

/// The default container image (GenMC v0.19.0, LLVM 21); see README.md.
const DEFAULT_IMAGE: &str = "ghcr.io/cataphract/genmc@sha256:cf4d0dff5379324727de68d264d6135e53ea2f9018e7d68f0a5d4a20d4e25479";
/// Where the image keeps the LLVM tools GenMC was built with.
const IMAGE_LLVM_BIN: &str = "/usr/lib/llvm-21/bin";

/// Library configuration of every bitcode build (README.md).
const LIB_GENMC_CFGS: &[&str] = &[r#"feature="verify""#, "sgc_genmc", "sgc_genmc_short_waits"];
/// Harness and program configuration of every bitcode build.
const HARNESS_GENMC_CFGS: &[&str] = &["sgc_genmc"];

/// Bitcode flags: abort on panic, optimise and inline aggressively, no
/// vectorisation, no runtime overflow/debug checks (README.md explains each).
const BITCODE_FLAGS: &[&str] = &[
    "--edition=2024",
    "-Cpanic=abort",
    "-Copt-level=3",
    "-g",
    "-Ccodegen-units=1",
    "-Cdebug-assertions=off",
    "-Coverflow-checks=off",
    "-Cno-vectorize-loops",
    "-Cno-vectorize-slp",
    "-Cllvm-args=-inline-threshold=10000",
    "-Cllvm-args=-unswitch-threshold=0",
];

const GENMC_ARGS: &[&str] = &[
    "-rc11",
    "-disable-mm-detector",
    "-disable-estimation",
    "-disable-code-condenser",
    "-mode=verify",
];

/// The bitcode target: GenMC runs in a Linux container of the host's
/// architecture.
const TARGET: &str = if cfg!(target_arch = "x86_64") {
    "x86_64-unknown-linux-gnu"
} else if cfg!(target_arch = "aarch64") {
    "aarch64-unknown-linux-gnu"
} else {
    panic!("unsupported host architecture")
};

fn main() {
    let args = Arguments::from_args();
    let mut trials = Vec::new();
    for program in PROGRAMS {
        trials.push(Trial::test(format!("{}::native", program.name), || {
            native(program)
        }));
        let ignored = GENMC_IGNORED.contains(&program.name);
        let source = suite_dir().join(format!("src/bin/{}.rs", program.name));
        let witnesses = fs::read_to_string(&source)
            .map_err(|e| e.to_string())
            .and_then(|text| classify::witness_names(&text))
            .map_err(|e| format!("{}: {e}", source.display()));
        let witnesses = match witnesses {
            Ok(witnesses) => witnesses,
            Err(error) => {
                // Fail closed: never drop a witness silently.
                trials.push(Trial::test(
                    format!("{}::witness::discovery", program.name),
                    move || Err(error.into()),
                ));
                continue;
            }
        };
        let variants = std::iter::once(None).chain(witnesses.into_iter().map(Some));
        for witness in variants {
            let name = match &witness {
                None => format!("{}::safety", program.name),
                Some(w) => format!("{}::witness::{w}", program.name),
            };
            let trial = Trial::test(name, move || genmc(program, witness.as_deref()));
            trials.push(trial.with_ignored_flag(ignored));
        }
    }
    libtest_mimic::run(&args, trials).exit();
}

/// Runs the native build of `program`.
fn native(program: &Program) -> Result<(), Failed> {
    let output = Command::new(program.exe)
        .output()
        .map_err(|e| format!("cannot run {}: {e}", program.exe))?;
    let text = format!(
        "{}{}",
        String::from_utf8_lossy(&output.stdout),
        String::from_utf8_lossy(&output.stderr)
    );
    print!("{text}");
    if !output.status.success() {
        return Err(format!("{} exited with {}", program.exe, output.status).into());
    }
    println!("PASS: native smoke run");
    Ok(())
}

/// The safety check (`witness == None`) or a witness check of `program`.
fn genmc(program: &Program, witness: Option<&str>) -> Result<(), Failed> {
    let setup = Setup::get()?;
    let variant = witness.unwrap_or("safety");
    let dir = setup
        .work
        .join("trials")
        .join(format!("{}.{variant}", program.name));
    let _ = fs::remove_dir_all(&dir);
    fs::create_dir_all(&dir).map_err(|e| format!("{}: {e}", dir.display()))?;
    println!("Artifacts: {}", dir.display());

    let genmc_version = setup.toolchain()?;
    let lib = setup.library()?;
    let mut cfgs: Vec<String> = HARNESS_GENMC_CFGS.iter().map(|&c| c.to_owned()).collect();
    if let Some(w) = witness {
        cfgs.push("genmc_witness".to_owned());
        cfgs.push(format!("genmc_witness=\"{w}\""));
    }
    let harness = setup.harness(&lib, &cfgs)?;

    // Relative (rustc runs in the repository), so that check messages carry
    // short paths.
    let source = format!("shm_gen_cache/verification/src/bin/{}.rs", program.name);
    let test_bc = dir.join("test.bc");
    let mut rustc = setup.rustc(&cfgs);
    rustc
        .args([
            "--crate-type=staticlib",
            &format!("--crate-name={}", program.name),
        ])
        .args(extern_args("shm_gen_cache", &lib))
        .args(extern_args("genmc_harness", &harness))
        .arg("--emit=llvm-bc")
        .arg("-o")
        .arg(&test_bc)
        .arg(&source);
    setup.require(&dir, "build", &mut rustc, None)?;

    let linked = dir.join("linked.bc");
    let mut link = setup.docker(Some(&format!("{IMAGE_LLVM_BIN}/llvm-link")));
    link.arg("-o")
        .arg(&linked)
        .arg(&test_bc)
        .arg(harness.join("genmc_harness.bc"))
        .arg(lib.join("shm_gen_cache.bc"));
    setup.require(&dir, "link", &mut link.command, Some(&link.container))?;

    // Only main is an entry point: drop everything unreachable from it (e.g.
    // the runtime configuration validator), so the checker never sees
    // intrinsics that cannot execute anyway.
    let pruned = dir.join("pruned.ll");
    let mut prune = setup.docker(Some(&format!("{IMAGE_LLVM_BIN}/opt")));
    prune
        .args(["-S", "-passes=internalize,globaldce"])
        .arg("-internalize-public-api-list=main")
        .arg("-o")
        .arg(&pruned)
        .arg(&linked);
    setup.require(&dir, "prune", &mut prune.command, Some(&prune.container))?;

    let program_ll = dir.join("program.ll");
    let ir = fs::read_to_string(&pruned).map_err(|e| format!("{}: {e}", pruned.display()))?;
    fs::write(&program_ll, classify::stub_panics(&ir))
        .map_err(|e| format!("{}: {e}", program_ll.display()))?;

    let mut check = setup.docker(None);
    check
        .args(GENMC_ARGS)
        .args(&setup.genmc_args)
        .arg(format!("-nthreads={}", setup.nthreads))
        .arg(&program_ll);
    let (status, log) = setup.run(&dir, "genmc", &mut check.command, Some(&check.container))?;
    let (complete, blocked) = classify::execution_counts(&log);
    println!(
        "GenMC: exit {status}, complete executions: {}, blocked executions: {} ({})",
        complete.unwrap_or("?"),
        blocked.unwrap_or("?"),
        genmc_version
    );
    match classify::classify(status, &log, witness) {
        Some(result) => {
            println!("PASS: {variant} ({result})");
            Ok(())
        }
        None => Err(format!(
            "{}.{variant}: unexpected GenMC result (exit {status}); see {}",
            program.name,
            dir.join("genmc.log").display()
        )
        .into()),
    }
}

fn suite_dir() -> PathBuf {
    PathBuf::from(env!("CARGO_MANIFEST_DIR"))
}

fn extern_args(crate_name: &str, dir: &Path) -> [String; 4] {
    [
        "--extern".to_owned(),
        format!(
            "{crate_name}={}",
            dir.join(format!("lib{crate_name}.rlib")).display()
        ),
        "-L".to_owned(),
        format!("dependency={}", dir.display()),
    ]
}

/// The environment of the GenMC trials.
struct Setup {
    /// The repository (workspace root): rustc runs there, so that
    /// rust-toolchain.toml selects it.
    repo: PathBuf,
    /// Artifacts: `trials/<test>.<variant>/` and `cache/`. Mounted into the
    /// container at the same path.
    work: PathBuf,
    image: String,
    docker: String,
    nthreads: u64,
    genmc_args: Vec<String>,
    timeout: Duration,
    /// `rustc -vV` (part of every cache key).
    rustc_version: String,
}

/// A `docker run` command and the name of its container.
struct Docker {
    command: Command,
    container: String,
}

impl std::ops::Deref for Docker {
    type Target = Command;
    fn deref(&self) -> &Command {
        &self.command
    }
}

impl std::ops::DerefMut for Docker {
    fn deref_mut(&mut self) -> &mut Command {
        &mut self.command
    }
}

impl Setup {
    fn get() -> Result<&'static Setup, String> {
        static SETUP: OnceLock<Result<Setup, String>> = OnceLock::new();
        SETUP.get_or_init(Setup::new).as_ref().map_err(Clone::clone)
    }

    fn new() -> Result<Setup, String> {
        let repo = suite_dir()
            .join("../..")
            .canonicalize()
            .map_err(|e| format!("repository: {e}"))?;
        let work = PathBuf::from(env!("CARGO_TARGET_TMPDIR")).join("genmc-verification");
        fs::create_dir_all(&work).map_err(|e| format!("{}: {e}", work.display()))?;
        let work = work
            .canonicalize()
            .map_err(|e| format!("{}: {e}", work.display()))?;
        let var = |name: &str| env::var(name).ok().filter(|v| !v.is_empty());
        let positive = |name: &str, default: u64| match var(name) {
            Some(v) => v
                .parse()
                .ok()
                .filter(|&n: &u64| n > 0)
                .ok_or_else(|| format!("{name}={v} is not a positive integer")),
            None => Ok(default),
        };
        let parallelism = thread::available_parallelism().map_or(1, |n| n.get() as u64);
        // The nextest test group runs 4 GenMC trials at a time.
        let nthreads = positive("SGC_GENMC_NTHREADS", (parallelism / 4).max(1))?;
        let timeout = positive("SGC_GENMC_TIMEOUT", 3600)?;
        let output = Command::new("rustc")
            .arg("-vV")
            .current_dir(&repo)
            .output()
            .map_err(|e| format!("cannot run rustc: {e}"))?;
        if !output.status.success() {
            return Err(format!(
                "rustc -vV failed: {}",
                String::from_utf8_lossy(&output.stderr)
            ));
        }
        Ok(Setup {
            repo,
            work,
            image: var("SGC_GENMC_IMAGE").unwrap_or_else(|| DEFAULT_IMAGE.to_owned()),
            docker: var("SGC_GENMC_DOCKER").unwrap_or_else(|| "docker".to_owned()),
            nthreads,
            genmc_args: var("SGC_GENMC_ARGS")
                .map(|a| a.split_whitespace().map(str::to_owned).collect())
                .unwrap_or_default(),
            timeout: Duration::from_secs(timeout),
            rustc_version: String::from_utf8_lossy(&output.stdout).into_owned(),
        })
    }

    /// Checks (once per rustc and image) that the container runs and that
    /// its LLVM matches rustc's; returns the GenMC version.
    fn toolchain(&self) -> Result<String, String> {
        let key: &[&[u8]] = &[b"toolchain", self.image.as_bytes(), self.docker.as_bytes()];
        let dir = self.cached("toolchain", key, |dir| {
            let what = format!("the GenMC image {} (SGC_GENMC_IMAGE)", self.image);
            let mut opt = self.docker(Some(&format!("{IMAGE_LLVM_BIN}/opt")));
            opt.arg("--version");
            let opt_version = self
                .require(dir, "llvm-version", &mut opt.command, Some(&opt.container))
                .map_err(|e| format!("cannot run {what}: {e}"))?;
            classify::check_llvm_versions(&self.rustc_version, &opt_version)?;
            let mut genmc = self.docker(None);
            genmc.arg("--version");
            let version = self
                .require(
                    dir,
                    "genmc-version",
                    &mut genmc.command,
                    Some(&genmc.container),
                )
                .map_err(|e| format!("cannot run {what}: {e}"))?;
            let version = version
                .lines()
                .find(|l| l.contains("GenMC v"))
                .unwrap_or("unknown GenMC version")
                .trim();
            fs::write(dir.join("version"), version).map_err(|e| e.to_string())
        })?;
        fs::read_to_string(dir.join("version")).map_err(|e| e.to_string())
    }

    /// The library rlib and bitcode (`shm_gen_cache.bc`).
    fn library(&self) -> Result<PathBuf, String> {
        let crate_dir = self.repo.join("shm_gen_cache");
        let mut sources = Vec::new();
        collect_sources(&crate_dir.join("src"), &crate_dir, &mut sources)?;
        let mut key: Vec<&[u8]> = vec![b"library", TARGET.as_bytes()];
        key.extend(BITCODE_FLAGS.iter().map(|f| f.as_bytes()));
        key.extend(LIB_GENMC_CFGS.iter().map(|c| c.as_bytes()));
        key.extend(sources.iter().map(Vec::as_slice));
        self.cached("library", &key, |dir| {
            let mut rustc = self.rustc(LIB_GENMC_CFGS);
            rustc
                .args(["--crate-type=rlib", "--crate-name=shm_gen_cache"])
                .arg("--emit=link,llvm-bc")
                .arg("--out-dir")
                .arg(dir)
                .arg(crate_dir.join("src/lib.rs"));
            self.require(dir, "build", &mut rustc, None).map(drop)
        })
    }

    /// The harness rlib and bitcode (`genmc_harness.bc`) for `cfgs`.
    fn harness(&self, lib: &Path, cfgs: &[String]) -> Result<PathBuf, String> {
        let source = suite_dir().join("src/lib.rs");
        let text = fs::read(&source).map_err(|e| format!("{}: {e}", source.display()))?;
        let lib_key = lib.as_os_str().as_encoded_bytes();
        let mut key: Vec<&[u8]> = vec![b"harness", lib_key, &text];
        key.extend(cfgs.iter().map(|c| c.as_bytes()));
        self.cached("harness", &key, |dir| {
            let mut rustc = self.rustc(cfgs);
            rustc
                .args(["--crate-type=rlib", "--crate-name=genmc_harness"])
                .args(extern_args("shm_gen_cache", lib))
                .arg("--emit=link,llvm-bc")
                .arg("--out-dir")
                .arg(dir)
                .arg(&source);
            self.require(dir, "build", &mut rustc, None).map(drop)
        })
    }

    /// The directory `build` fills, shared by every trial and run with the
    /// same `key` (with the rustc version): built once, under a file lock,
    /// and valid only once its done-marker exists.
    fn cached(
        &self,
        kind: &str,
        key: &[&[u8]],
        build: impl FnOnce(&Path) -> Result<(), String>,
    ) -> Result<PathBuf, String> {
        let mut hasher = Sha256::new();
        for part in [self.rustc_version.as_bytes()].iter().chain(key) {
            hasher.update((part.len() as u64).to_le_bytes());
            hasher.update(part);
        }
        let mut hash = String::new();
        for byte in &hasher.finalize()[..12] {
            write!(hash, "{byte:02x}").unwrap();
        }
        let cache = self.work.join("cache");
        let dir = cache.join(format!("{kind}-{hash}"));
        let done = dir.join(".done");
        if done.exists() {
            return Ok(dir);
        }
        fs::create_dir_all(&cache).map_err(|e| format!("{}: {e}", cache.display()))?;
        let lock_path = cache.join(format!("{kind}-{hash}.lock"));
        let lock = File::create(&lock_path).map_err(|e| format!("{}: {e}", lock_path.display()))?;
        lock.lock()
            .map_err(|e| format!("{}: {e}", lock_path.display()))?;
        if done.exists() {
            return Ok(dir);
        }
        let _ = fs::remove_dir_all(&dir);
        fs::create_dir_all(&dir).map_err(|e| format!("{}: {e}", dir.display()))?;
        build(&dir)?;
        let pending = dir.join(".done.tmp");
        fs::write(&pending, b"").map_err(|e| format!("{}: {e}", pending.display()))?;
        fs::rename(&pending, &done).map_err(|e| format!("{}: {e}", done.display()))?;
        Ok(dir)
    }

    fn rustc<S: AsRef<str>>(&self, cfgs: &[S]) -> Command {
        let mut rustc = Command::new("rustc");
        rustc.args(BITCODE_FLAGS).arg(format!("--target={TARGET}"));
        for cfg in cfgs {
            rustc.args(["--cfg", cfg.as_ref()]);
        }
        rustc
    }

    /// `docker run` of `entrypoint` (default: GenMC, the image's entry
    /// point) in the image, with the work directory mounted at the same
    /// path, as the owner of the work directory.
    fn docker(&self, entrypoint: Option<&str>) -> Docker {
        static COUNTER: AtomicUsize = AtomicUsize::new(0);
        let container = format!(
            "sgc-genmc-{}-{}",
            std::process::id(),
            COUNTER.fetch_add(1, Ordering::Relaxed)
        );
        let (uid, gid) = fs::metadata(&self.work).map_or((0, 0), |m| (m.uid(), m.gid()));
        let mut command = Command::new(&self.docker);
        command
            .args(["run", "--rm", "--network=none", "--name", &container])
            .arg(format!("--user={uid}:{gid}"))
            .arg(format!("--volume={0}:{0}", self.work.display()))
            .arg(format!("--workdir={}", self.work.display()));
        if let Some(entrypoint) = entrypoint {
            command.arg(format!("--entrypoint={entrypoint}"));
        }
        command.arg(&self.image);
        Docker { command, container }
    }

    /// Like [`Setup::run`], failing unless the command exits 0; returns its
    /// output.
    fn require(
        &self,
        dir: &Path,
        name: &str,
        command: &mut Command,
        container: Option<&str>,
    ) -> Result<String, String> {
        let (status, log) = self.run(dir, name, command, container)?;
        if status != 0 {
            return Err(format!(
                "{name} exited {status}; see {}",
                dir.join(format!("{name}.log")).display()
            ));
        }
        Ok(log)
    }

    /// Runs `command` in the repository with its output in `dir/<name>.log`
    /// (and the command line appended to `dir/commands.txt`); returns the
    /// exit status (negative: the signal) and the output. Kills it, and its
    /// container, after the timeout.
    fn run(
        &self,
        dir: &Path,
        name: &str,
        command: &mut Command,
        container: Option<&str>,
    ) -> Result<(i32, String), String> {
        let log_path = dir.join(format!("{name}.log"));
        let mut line = format!("{name}: {}", command.get_program().display());
        for arg in command.get_args() {
            write!(line, " {}", arg.display()).unwrap();
        }
        let commands = dir.join("commands.txt");
        OpenOptions::new()
            .create(true)
            .append(true)
            .open(&commands)
            .and_then(|mut f| writeln!(f, "{line}"))
            .map_err(|e| format!("{}: {e}", commands.display()))?;
        let log = File::create(&log_path).map_err(|e| format!("{}: {e}", log_path.display()))?;
        let stderr = log.try_clone().map_err(|e| e.to_string())?;
        let mut child = command
            .current_dir(&self.repo)
            .stdin(Stdio::null())
            .stdout(log)
            .stderr(stderr)
            .spawn()
            .map_err(|e| {
                format!(
                    "cannot run {} ({e}); for docker, see SGC_GENMC_DOCKER",
                    command.get_program().display()
                )
            })?;
        let start = Instant::now();
        let status = loop {
            if let Some(status) = child.try_wait().map_err(|e| e.to_string())? {
                break status;
            }
            if start.elapsed() > self.timeout {
                if let Some(container) = container {
                    let _ = Command::new(&self.docker)
                        .args(["kill", container])
                        .stdout(Stdio::null())
                        .stderr(Stdio::null())
                        .status();
                }
                let _ = child.kill();
                let _ = child.wait();
                return Err(format!(
                    "{name} timed out after {:?} (SGC_GENMC_TIMEOUT); see {}",
                    self.timeout,
                    log_path.display()
                ));
            }
            thread::sleep(Duration::from_millis(50));
        };
        let code = status
            .code()
            .unwrap_or_else(|| -status.signal().unwrap_or(1));
        let bytes = fs::read(&log_path).map_err(|e| format!("{}: {e}", log_path.display()))?;
        Ok((code, String::from_utf8_lossy(&bytes).into_owned()))
    }
}

/// Appends every file under `dir` to `out` (path relative to `base`, then
/// contents), in a stable order.
fn collect_sources(dir: &Path, base: &Path, out: &mut Vec<Vec<u8>>) -> Result<(), String> {
    let mut entries: Vec<PathBuf> = fs::read_dir(dir)
        .and_then(|it| it.map(|e| e.map(|e| e.path())).collect())
        .map_err(|e| format!("{}: {e}", dir.display()))?;
    entries.sort();
    for path in entries {
        if path.is_dir() {
            collect_sources(&path, base, out)?;
        } else {
            let relative = path.strip_prefix(base).unwrap_or(&path);
            out.push(relative.as_os_str().as_encoded_bytes().to_vec());
            out.push(fs::read(&path).map_err(|e| format!("{}: {e}", path.display()))?);
        }
    }
    Ok(())
}
