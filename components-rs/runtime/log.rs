use arc_swap::ArcSwap;
use libdd_common_ffi::slice::AsBytes;
use libdd_common_ffi::CharSlice;
use std::cell::RefCell;
use std::collections::{BTreeSet, HashMap};
use std::ffi::c_char;
use std::fmt::Debug;
use std::str::FromStr;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, OnceLock};
use tracing::Level;
use tracing_core::{Event, Field, Interest, LevelFilter, Metadata, Subscriber};
use tracing_subscriber::filter::Targets;
use tracing_subscriber::fmt::format::Writer;
use tracing_subscriber::fmt::{FmtContext, FormatEvent, FormatFields};
use tracing_subscriber::layer::{Context, Filter, SubscriberExt};
use tracing_subscriber::registry::LookupSpan;
use tracing_subscriber::util::SubscriberInitExt;
use tracing_subscriber::{EnvFilter, Layer};

pub const LOG_ONCE: isize = 1 << 3;

#[allow(non_camel_case_types)]
#[derive(Clone, Copy)]
#[repr(C)]
pub enum Log {
    Error = 1,
    Warn = 2,
    Info = 3,
    Debug = 4,
    Trace = 5,
    Deprecated = 3 | LOG_ONCE,
    Startup = 3 | (2 << 4),
    Startup_Warn = 1 | (2 << 4),
    Span = 4 | (3 << 4),
    Span_Trace = 5 | (3 << 4),
    Hook_Trace = 5 | (4 << 4),
}

#[no_mangle]
#[allow(non_upper_case_globals)]
pub static mut ddog_log_callback: Option<extern "C" fn(CharSlice)> = None;

/// Log callback for sidecar threads. It may write to `DD_TRACE_LOG_FILE`, but must not call PHP's
/// error logger, which requires request state and can bail out.
#[no_mangle]
#[allow(non_upper_case_globals)]
pub static mut ddog_log_callback_off_thread: Option<extern "C" fn(CharSlice)> = None;

/// The global subscriber is installed once, so its formatter reads this flag on each event.
static GLOBAL_ONCE: AtomicBool = AtomicBool::new(false);

/// The filter the global subscriber consults, and the marker that it has been installed.
///
/// `tracing_subscriber::reload::Layer` would be the obvious way to make the filter mutable, but
/// it guards the filter with an `RwLock`: every event takes the read lock and every level change
/// takes the write lock. A process that forks while another thread holds the read lock inherits
/// it held forever - the owning thread does not exist in the child - so the child's first level
/// change blocks in `reload()` and never returns. `ArcSwap` keeps the event path lock-free and
/// reduces a change to a pointer store, leaving nothing for a child to inherit.
static GLOBAL_FILTER: OnceLock<ArcSwap<Targets>> = OnceLock::new();

fn global_filter() -> &'static ArcSwap<Targets> {
    // Nothing enabled is the right fallback: an event can only reach us through the subscriber
    // installed by `set_global_log_filter`, which stores the real filter before installing it.
    GLOBAL_FILTER.get_or_init(|| ArcSwap::from_pointee(Targets::new()))
}

/// Per-layer filter reading [`GLOBAL_FILTER`] on each event.
struct GlobalFilter;

impl<S> Filter<S> for GlobalFilter {
    fn enabled(&self, metadata: &Metadata<'_>, cx: &Context<'_, S>) -> bool {
        Filter::<S>::enabled(&**global_filter().load(), metadata, cx)
    }

    fn callsite_enabled(&self, _metadata: &'static Metadata<'static>) -> Interest {
        // `Targets` answers `always`/`never` here, which tracing caches per callsite until the
        // next interest rebuild. Keeping `enabled` authoritative costs a `Targets` scan only
        // for callsites that already passed the global max level, and in exchange no callsite
        // can outlive a narrowed filter in the window before the rebuild lands.
        Interest::sometimes()
    }

    fn max_level_hint(&self) -> Option<LevelFilter> {
        Filter::<S>::max_level_hint(&**global_filter().load())
    }
}

// Avoid RefCell for performance
std::thread_local! {
    static LOGGED_MSGS: RefCell<BTreeSet<String>> = const { RefCell::new(BTreeSet::new()) };
    static TRACING_GUARDS: RefCell<Option<tracing_core::dispatcher::DefaultGuard>> = const { RefCell::new(None) };

    // todo: MSRV 1.85+ make this const with HashMap::with_hasher
    static COUNTERS: RefCell<HashMap<Level, u32>> = RefCell::default();
}

macro_rules! with_target {
    ($cat:ident, tracing::$p:ident!($($t:tt)*)) => {
        match $cat {
            Log::Error => tracing::$p!(target: "ddtrace", Level::ERROR, $($t)*),
            Log::Warn => tracing::$p!(target: "ddtrace", Level::WARN, $($t)*),
            Log::Info => tracing::$p!(target: "ddtrace", Level::INFO, $($t)*),
            Log::Debug => tracing::$p!(target: "ddtrace", Level::DEBUG, $($t)*),
            Log::Trace => tracing::$p!(target: "ddtrace", Level::TRACE, $($t)*),
            Log::Deprecated => tracing::$p!(target: "deprecated", Level::INFO, $($t)*),
            Log::Startup => tracing::$p!(target: "startup", Level::INFO, $($t)*),
            Log::Span => tracing::$p!(target: "span", Level::DEBUG, $($t)*),
            Log::Span_Trace => tracing::$p!(target: "span", Level::TRACE, $($t)*),
            Log::Hook_Trace => tracing::$p!(target: "hook", Level::TRACE, $($t)*),
            _ => unreachable!()
        }
    }
}

#[no_mangle]
pub extern "C" fn ddog_shall_log(category: Log) -> bool {
    with_target!(category, tracing::event_enabled!())
}

pub fn log<S>(category: Log, msg: S)
where
    S: AsRef<str> + tracing::Value,
{
    let once = (category as isize & LOG_ONCE) != 0;
    if once {
        with_target!(category, tracing::event!(once = true, msg));
    } else {
        with_target!(category, tracing::event!(msg));
    }
}

struct LogFormatter {
    pub once: bool,
    /// Use the file-only callback for threads without PHP request state.
    pub off_thread: bool,
}

struct LogVisitor {
    pub msg: Option<String>,
    pub once: bool,
}

impl tracing_core::field::Visit for LogVisitor {
    fn record_bool(&mut self, _field: &Field, value: bool) {
        self.once = value;
    }

    fn record_str(&mut self, _field: &Field, msg: &str) {
        self.msg = Some(msg.to_string());
    }

    fn record_debug(&mut self, _field: &Field, value: &dyn Debug) {
        self.msg = Some(format!("{value:?}"));
    }
}

impl<S, N> FormatEvent<S, N> for LogFormatter
where
    S: Subscriber + for<'a> LookupSpan<'a>,
    N: for<'a> FormatFields<'a> + 'static,
{
    fn format_event(
        &self,
        _ctx: &FmtContext<'_, S, N>,
        _writer: Writer<'_>,
        event: &Event<'_>,
    ) -> core::fmt::Result {
        let mut visitor = LogVisitor {
            msg: None,
            once: false,
        };
        event.record(&mut visitor);

        fn fmt_msg(event: &Event<'_>, msg: &str, suffix: &str) -> String {
            let data = event.metadata();
            let target = if data.target() == "ddtrace" {
                match *data.level() {
                    Level::ERROR => "error",
                    Level::WARN => "warning",
                    Level::INFO => "info",
                    Level::DEBUG => "debug",
                    Level::TRACE => "trace",
                }
            } else {
                data.target()
            };
            format!(
                "[ddtrace] [{}] [{}] {}{}\0",
                target,
                std::process::id(),
                msg,
                suffix
            )
        }

        let (callback, once) = if self.off_thread {
            (
                unsafe { ddog_log_callback_off_thread },
                GLOBAL_ONCE.load(Ordering::Relaxed),
            )
        } else {
            (unsafe { ddog_log_callback }, self.once)
        };

        if let Some(msg) = visitor.msg {
            if let Some(cb) = callback {
                let msg = if once && visitor.once {
                    if let Some(formatted) = LOGGED_MSGS.with(|logged| {
                        let mut logged = logged.borrow_mut();
                        if logged.contains(msg.as_str()) {
                            return None;
                        }
                        let formatted = Some(fmt_msg(event, &msg, "; This message is only displayed once. Specify DD_TRACE_ONCE_LOGS=0 to show all messages."));
                        logged.insert(msg);
                        formatted
                    }) {
                        formatted
                    } else {
                        return Ok(());
                    }
                } else {
                    fmt_msg(event, &msg, "")
                };

                COUNTERS.with(|counter| {
                    let mut counter = counter.borrow_mut();
                    *counter
                        .entry(event.metadata().level().to_owned())
                        .or_default() += 1;
                });

                cb(unsafe {
                    CharSlice::from_raw_parts(msg.as_ptr() as *const c_char, msg.len() - 1)
                });
            }
        }
        Ok(())
    }
}

#[no_mangle]
pub unsafe extern "C" fn ddog_set_error_log_level(once: bool) {
    let subscriber = tracing_subscriber::fmt()
        .with_max_level(LevelFilter::ERROR)
        .event_format(LogFormatter {
            once,
            off_thread: false,
        });
    set_log_subscriber(subscriber);
    set_global_log_filter(Targets::new().with_default(LevelFilter::ERROR), once);
}

#[no_mangle]
pub unsafe extern "C" fn ddog_set_log_level(level: CharSlice, once: bool) {
    let level = level.to_utf8_lossy();
    let subscriber = tracing_subscriber::fmt()
        .with_env_filter(EnvFilter::builder().parse_lossy(level.as_ref()))
        .event_format(LogFormatter {
            once,
            off_thread: false,
        });
    set_log_subscriber(subscriber);
    set_global_log_filter(parse_targets_lossy(level.as_ref()), once);
}

/// Parse comma-separated filter directives, skipping the ones that do not parse.
///
/// `DD_TRACE_LOG_LEVEL` is user input, so this mirrors `EnvFilter::parse_lossy`: one bad
/// directive is reported and dropped rather than discarding the whole filter. `Targets` does
/// not trim its directives the way `EnvFilter` does, so do it here - otherwise a space after a
/// comma turns the next directive into a target name that can never match.
fn parse_targets_lossy(directives: &str) -> Targets {
    let kept: Vec<&str> = directives
        .split(',')
        .map(str::trim)
        .filter(|directive| !directive.is_empty())
        .filter(|directive| match Targets::from_str(directive) {
            Ok(_) => true,
            Err(err) => {
                eprintln!("ignoring `{directive}`: {err}");
                false
            }
        })
        .collect();

    if kept.is_empty() {
        return Targets::new();
    }
    Targets::from_str(&kept.join(",")).unwrap_or_default()
}

/// Install a global subscriber for sidecar threads, then swap its filter on later calls.
/// PHP threads keep their own subscribers, which take precedence over the global one.
/// The shared filter uses the most recently configured level.
fn set_global_log_filter(targets: Targets, once: bool) {
    GLOBAL_ONCE.store(once, Ordering::Relaxed);

    if let Some(filter) = GLOBAL_FILTER.get() {
        // Already installed, so the swap is all that is left. `tracing::event!` short-circuits
        // on a cached global max level before any filter runs, so a change has to invalidate
        // that cache - but rebuilding it walks every registered callsite, so only do it when
        // the filter really changed.
        if **filter.load() != targets {
            filter.store(Arc::new(targets));
            tracing_core::callsite::rebuild_interest_cache();
        }
        return;
    }

    // Store before installing: the filter has to be current for the very first event.
    let _ = GLOBAL_FILTER.set(ArcSwap::from_pointee(targets));
    let subscriber = tracing_subscriber::registry().with(
        tracing_subscriber::fmt::layer()
            .event_format(LogFormatter {
                once,
                off_thread: true,
            })
            .with_filter(GlobalFilter),
    );
    let _ = tracing::subscriber::set_global_default(subscriber);
}

fn set_log_subscriber<S>(subscriber: S)
where
    S: SubscriberInitExt,
{
    TRACING_GUARDS.replace(None); // drop first to avoid a prior guard to reset the thread local subscriber it upon replace()
    TRACING_GUARDS.replace(Some(subscriber.set_default()));
}

#[no_mangle]
pub unsafe extern "C" fn ddog_log(category: Log, once: bool, msg: CharSlice) {
    if once {
        with_target!(
            category,
            tracing::event!(once = true, "{}", msg.to_utf8_lossy())
        );
    } else {
        with_target!(category, tracing::event!("{}", msg.to_utf8_lossy()));
    }
}

#[no_mangle]
pub extern "C" fn ddog_reset_logger() {
    LOGGED_MSGS.with(|logged| {
        let mut logged = logged.borrow_mut();
        logged.clear();
    });

    COUNTERS.with(|counter| {
        let mut counter = counter.borrow_mut();
        counter.clear();
    });
}

#[no_mangle]
pub extern "C" fn ddog_get_logs_count(level: CharSlice) -> u32 {
    return COUNTERS.with(|counter| {
        let level = Level::from_str(&level.to_utf8_lossy()).unwrap();

        let mut counter = counter.borrow_mut();
        *counter.entry(level).or_default()
    });
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::Mutex;

    static CAPTURED: Mutex<Vec<String>> = Mutex::new(Vec::new());

    extern "C" fn capture(msg: CharSlice) {
        CAPTURED
            .lock()
            .unwrap()
            .push(msg.to_utf8_lossy().into_owned());
    }

    fn drain() -> Vec<String> {
        std::mem::take(&mut *CAPTURED.lock().unwrap())
    }

    fn logged(messages: &[String], msg: &str) -> bool {
        messages.iter().any(|logged| logged.ends_with(msg))
    }

    /// Every level change has to reach callsites that have already logged.
    ///
    /// `tracing::event!` short-circuits on a process-wide max level that is only recomputed by
    /// an interest rebuild, so swapping the filter alone leaves a widened level with no effect.
    /// That is invisible until a request reconfigures `DD_TRACE_LOG_LEVEL`: dropping the
    /// rebuild from `set_global_log_filter` fails this test at the `error` -> `debug` step.
    ///
    /// This test owns the process-wide subscriber, so it has to stay the only one installing it.
    #[test]
    fn the_global_filter_follows_later_level_changes() {
        // SAFETY: the formatter only reads this pointer, and no other test writes it.
        unsafe { ddog_log_callback_off_thread = Some(capture) };

        set_global_log_filter(parse_targets_lossy("error"), false);
        assert!(!ddog_shall_log(Log::Debug), "debug enabled at level error");
        log(Log::Debug, "first");
        log(Log::Error, "loud");
        let seen = drain();
        assert!(!logged(&seen, "first"), "debug emitted at level error");
        assert!(
            logged(&seen, "loud"),
            "error dropped at level error: {seen:?}"
        );

        // Same callsites, higher level: a cached per-callsite verdict would keep them silent.
        set_global_log_filter(parse_targets_lossy("debug"), false);
        assert!(
            ddog_shall_log(Log::Debug),
            "debug still disabled at level debug"
        );
        log(Log::Debug, "second");
        assert!(logged(&drain(), "second"), "debug dropped at level debug");

        // And back down again, so the change is not just a one-way widening.
        set_global_log_filter(parse_targets_lossy("error"), false);
        assert!(
            !ddog_shall_log(Log::Debug),
            "debug re-enabled at level error"
        );
        log(Log::Debug, "third");
        assert!(!logged(&drain(), "third"), "debug emitted at level error");
    }

    /// Per-target directives are what `dd_log_set_level` sends for startup logs.
    #[test]
    fn a_per_target_directive_is_parsed() {
        let targets = parse_targets_lossy("debug,startup=error");
        assert!(targets.would_enable("ddtrace", &Level::DEBUG));
        assert!(!targets.would_enable("startup", &Level::INFO));
        assert!(targets.would_enable("startup", &Level::ERROR));
    }

    /// `ddog_set_error_log_level` skips parsing and builds its filter directly. It has to
    /// match the parsed one, or alternating between the two entry points would look like a
    /// change and rebuild the interest cache for nothing.
    #[test]
    fn the_unparsed_error_filter_matches_the_parsed_one() {
        assert_eq!(
            Targets::new().with_default(LevelFilter::ERROR),
            parse_targets_lossy("error")
        );
    }

    /// `DD_TRACE_LOG_LEVEL` is user input, so one bad directive must not discard the rest.
    #[test]
    fn a_malformed_directive_is_skipped() {
        let targets = parse_targets_lossy("debug,startup=nonsense");
        assert!(targets.would_enable("ddtrace", &Level::DEBUG));
        assert_eq!(parse_targets_lossy(""), Targets::new());
    }

    /// `EnvFilter` trims each directive and `Targets` does not, so spacing stays tolerated.
    #[test]
    fn surrounding_whitespace_is_tolerated() {
        assert_eq!(
            parse_targets_lossy(" debug , startup=error "),
            parse_targets_lossy("debug,startup=error")
        );
    }
}
