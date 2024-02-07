use std::cell::RefCell;
use std::collections::BTreeSet;
use std::ffi::c_char;
use std::fmt::Debug;
use bitflags::bitflags;
use tracing::Level;
use tracing_core::{Event, Field, LevelFilter, Subscriber};
use tracing_subscriber::EnvFilter;
use tracing_subscriber::fmt::{FmtContext, FormatEvent, FormatFields};
use tracing_subscriber::fmt::format::Writer;
use tracing_subscriber::registry::LookupSpan;
use tracing_subscriber::util::SubscriberInitExt;
use ddcommon_ffi::CharSlice;
use ddcommon_ffi::slice::AsBytes;

bitflags! {
    #[derive(Clone, Copy, Debug, PartialEq, Eq, Hash)]
    #[repr(C)]
    pub struct Log: u32 {
        const Error = 1;
        const Warn = 2;
        const Info = 3;
        const Debug = 4;
        const Trace = 5;
        const Once = 1 << 3; // I.e. once per request
        const _Deprecated = 3 | (1 << 4);
        const Deprecated = 3 | (1 << 4) | (1 << 3) /* Once */;
        const Startup = 3 | (2 << 4);
        const Startup_Warn = 1 | (2 << 4);
        const Span = 4 | (3 << 4);
        const Span_Trace = 5 | (3 << 4);
        const Hook_Trace = 5 | (4 << 4);
    }
}

#[no_mangle]
#[allow(non_upper_case_globals)]
pub static mut ddog_log_callback: Option<extern "C" fn(CharSlice)> = None;

// Avoid RefCell for performance
std::thread_local! {
    static LOGGED_MSGS: RefCell<BTreeSet<String>> = RefCell::default();
    static TRACING_GUARDS: RefCell<Option<tracing_core::dispatcher::DefaultGuard>> = RefCell::default();
}

macro_rules! with_target {
    ($cat:ident, tracing::$p:ident!($($t:tt)*)) => {
        match $cat {
            Log::Error => tracing::$p!(target: "ddtrace", Level::ERROR, $($t)*),
            Log::Warn => tracing::$p!(target: "ddtrace", Level::WARN, $($t)*),
            Log::Info => tracing::$p!(target: "ddtrace", Level::INFO, $($t)*),
            Log::Debug => tracing::$p!(target: "ddtrace", Level::DEBUG, $($t)*),
            Log::Trace => tracing::$p!(target: "ddtrace", Level::TRACE, $($t)*),
            Log::_Deprecated => tracing::$p!(target: "deprecated", Level::INFO, $($t)*),
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
    let category = category & !Log::Once;
    with_target!(category, tracing::event_enabled!())
}

pub fn log<S>(category: Log, msg: S) where S: AsRef<str> + tracing::Value {
    let once = !(category & Log::Once).is_empty();
    let category = category & !Log::Once;
    if once {
        with_target!(category, tracing::event!(once = true, msg));
    } else {
        with_target!(category, tracing::event!(msg));
    }
}

struct LogFormatter {
    pub once: bool,
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
        N: for<'a> FormatFields<'a> + 'static {
    fn format_event(
        &self,
        _ctx: &FmtContext<'_, S, N>,
        _writer: Writer<'_>,
        event: &Event<'_>
    ) -> core::fmt::Result {
        let mut visitor = LogVisitor { msg: None, once: false };
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
            format!("[ddtrace] [{}] {}{}\0", target, msg, suffix)
        }

        if let Some(msg) = visitor.msg {
            if let Some(cb) = unsafe { ddog_log_callback } {
                let msg = if self.once && visitor.once {
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
                cb(unsafe { CharSlice::from_raw_parts(msg.as_ptr() as *const c_char, msg.len() - 1) });
            }
        }
        Ok(())
    }
}

#[no_mangle]
pub unsafe extern "C" fn ddog_set_error_log_level(once: bool) {
    let subscriber = tracing_subscriber::fmt()
        .with_max_level(LevelFilter::ERROR)
        .event_format(LogFormatter { once });
    set_log_subscriber(subscriber)
}

#[no_mangle]
pub unsafe extern "C" fn ddog_set_log_level(level: CharSlice, once: bool) {
    let subscriber = tracing_subscriber::fmt()
        .with_env_filter(EnvFilter::builder().parse_lossy(level.to_utf8_lossy()))
        .event_format(LogFormatter { once });
    set_log_subscriber(subscriber)
}

fn set_log_subscriber<S>(subscriber: S) where S: SubscriberInitExt {
    TRACING_GUARDS.replace(None); // drop first to avoid a prior guard to reset the thread local subscriber it upon replace()
    TRACING_GUARDS.replace(Some(subscriber.set_default()));
}

#[no_mangle]
pub unsafe extern "C" fn ddog_log(category: Log, msg: CharSlice) {
    let once = !(category & Log::Once).is_empty();
    let category = category & !Log::Once;
    if once {
        with_target!(category, tracing::event!(once = true, "{}", msg.to_utf8_lossy()));
    } else {
        with_target!(category, tracing::event!("{}", msg.to_utf8_lossy()));
    }
}

#[no_mangle]
pub extern "C" fn ddog_reset_log_once() {
    LOGGED_MSGS.with(|logged| {
        let mut logged = logged.borrow_mut();
        logged.clear();
    });
}
