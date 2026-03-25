use log::kv::{Key, VisitSource};
use log::{Level, Log, Metadata, Record};
use std::backtrace::Backtrace;
use std::borrow::Cow;
use std::cell::Cell;

use super::{LogLevel, TelemetryLog, TelemetryTags};
use crate::client::log::ANYHOW_BACKTRACE_KEY;
use crate::telemetry::error_tel_ctx::get_context_log_submitter;
use crate::telemetry::TelemetryLogSubmitter;

/// A composite logger that dispatches to the primary logger
/// and submits error-level logs to telemetry.
pub struct TelemetryAwareLogger {
    delegate: Box<dyn Log>,
}

impl TelemetryAwareLogger {
    pub fn new(delegate: Box<dyn Log>) -> Self {
        Self { delegate }
    }
}

impl Log for TelemetryAwareLogger {
    fn enabled(&self, metadata: &Metadata) -> bool {
        self.delegate.enabled(metadata)
    }

    fn log(&self, record: &Record) {
        self.delegate.log(record);

        if record.level() != Level::Error {
            return;
        }

        submit_error_to_telemetry(record);
    }

    fn flush(&self) {
        self.delegate.flush();
    }
}

// Recursion guard: prevents infinite loops if telemetry submission logs an error
thread_local! {
    static IN_ERROR_HANDLER: Cell<bool> = const { Cell::new(false) };
}

struct RecursionGuard;
impl RecursionGuard {
    fn enter() -> Option<Self> {
        IN_ERROR_HANDLER.with(|cell| {
            if cell.get() {
                None
            } else {
                cell.set(true);
                Some(RecursionGuard)
            }
        })
    }
}
impl Drop for RecursionGuard {
    fn drop(&mut self) {
        IN_ERROR_HANDLER.with(|cell| cell.set(false));
    }
}

// Rate limiter: allows at most one error per interval per thread
thread_local! {
    static LAST_SUBMIT_TIME: Cell<u64> = const { Cell::new(0) };
}

const MIN_INTERVAL_MS: u64 = 1000;

fn should_rate_limit() -> bool {
    let now = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|d| d.as_millis() as u64)
        .unwrap_or(0);

    LAST_SUBMIT_TIME.with(|cell| {
        let last = cell.get();
        if now.saturating_sub(last) < MIN_INTERVAL_MS {
            true
        } else {
            cell.set(now);
            false
        }
    })
}

fn submit_error_to_telemetry(record: &Record) {
    let Some(_guard) = RecursionGuard::enter() else {
        return;
    };

    let mut tags = TelemetryTags::new();
    tags.add("log_type", "helper::logged_error");
    if let Some(module) = record.module_path() {
        tags.add("module", module);
    }

    let message = format!("{}", record.args());

    let location = if let (Some(module), Some(line)) = (record.module_path(), record.line()) {
        Cow::Owned(format!("{}:{}", module, line))
    } else if let Some(module) = record.module_path() {
        Cow::Borrowed(module)
    } else {
        Cow::Borrowed("unknown")
    };

    if should_rate_limit() {
        return;
    }

    let stack_trace = extract_anyhow_backtrace(record).or_else(|| {
        // Fall back to capturing backtrace at the logger (less useful but better than nothing)
        let backtrace = Backtrace::force_capture();
        match backtrace.status() {
            std::backtrace::BacktraceStatus::Captured => Some(backtrace.to_string()),
            _ => None,
        }
    });

    let log = TelemetryLog {
        level: LogLevel::Error,
        identifier: format!("helper::{}", location),
        message: format!("{} at {}", message, location),
        stack_trace,
        tags: Some(tags),
        is_sensitive: false,
    };

    let mut submitter = get_context_log_submitter();
    submitter.submit_log(log);
}

/// Visitor to extract anyhow backtrace from log record's key-values
struct BacktraceExtractor {
    backtrace: Option<String>,
}

impl<'kvs> VisitSource<'kvs> for BacktraceExtractor {
    fn visit_pair(
        &mut self,
        key: Key<'kvs>,
        value: log::kv::Value<'kvs>,
    ) -> Result<(), log::kv::Error> {
        if key.as_str() == ANYHOW_BACKTRACE_KEY {
            self.backtrace = Some(value.to_string());
        }
        Ok(())
    }
}

fn extract_anyhow_backtrace(record: &Record) -> Option<String> {
    let mut extractor = BacktraceExtractor { backtrace: None };
    let _ = record.key_values().visit(&mut extractor);
    extractor.backtrace
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::{Arc, Mutex};

    struct TestLogger {
        logs: Arc<Mutex<Vec<String>>>,
    }

    impl Log for TestLogger {
        fn enabled(&self, _metadata: &Metadata) -> bool {
            true
        }

        fn log(&self, record: &Record) {
            self.logs
                .lock()
                .unwrap()
                .push(format!("[{}] {}", record.level(), record.args()));
        }

        fn flush(&self) {}
    }

    #[test]
    fn test_recursion_guard() {
        assert!(RecursionGuard::enter().is_some());
        {
            let _guard1 = RecursionGuard::enter().unwrap();
            assert!(RecursionGuard::enter().is_none());
            assert!(RecursionGuard::enter().is_none());
        }
        assert!(RecursionGuard::enter().is_some());
    }

    #[test]
    fn test_rate_limiting() {
        LAST_SUBMIT_TIME.with(|cell| cell.set(0));

        assert!(!should_rate_limit());

        assert!(should_rate_limit());
        assert!(should_rate_limit());

        LAST_SUBMIT_TIME.with(|cell| {
            let now = std::time::SystemTime::now()
                .duration_since(std::time::UNIX_EPOCH)
                .unwrap()
                .as_millis() as u64;
            cell.set(now - MIN_INTERVAL_MS - 1);
        });

        assert!(!should_rate_limit());
    }

    #[test]
    fn test_composite_logger_delegates_to_primary() {
        let logs = Arc::new(Mutex::new(Vec::new()));
        let primary = Box::new(TestLogger { logs: logs.clone() });
        let composite = TelemetryAwareLogger::new(primary);

        let record = log::Record::builder()
            .args(format_args!("test message"))
            .level(Level::Info)
            .build();

        composite.log(&record);

        let captured = logs.lock().unwrap();
        assert_eq!(captured.len(), 1);
        assert_eq!(captured[0], "[INFO] test message");
    }

    #[test]
    fn test_composite_logger_handles_all_levels() {
        let logs = Arc::new(Mutex::new(Vec::new()));
        let primary = Box::new(TestLogger { logs: logs.clone() });
        let composite = TelemetryAwareLogger::new(primary);

        macro_rules! log_level {
            ($level:expr, $msg:literal) => {{
                let record = log::Record::builder()
                    .args(format_args!($msg))
                    .level($level)
                    .build();
                composite.log(&record);
            }};
        }

        log_level!(Level::Trace, "trace message");
        log_level!(Level::Debug, "debug message");
        log_level!(Level::Info, "info message");
        log_level!(Level::Warn, "warn message");
        log_level!(Level::Error, "error message");

        let captured = logs.lock().unwrap();
        assert_eq!(captured.len(), 5);
    }

    #[test]
    fn test_extract_anyhow_backtrace_with_key() {
        use log::kv::{self, ToValue};

        struct TestKvs<'a> {
            backtrace: &'a str,
        }

        impl<'kvs> kv::Source for TestKvs<'kvs> {
            fn visit<'a>(&'a self, visitor: &mut dyn kv::VisitSource<'a>) -> Result<(), kv::Error> {
                visitor.visit_pair(
                    kv::Key::from_str(ANYHOW_BACKTRACE_KEY),
                    self.backtrace.to_value(),
                )
            }
        }

        let kvs = TestKvs {
            backtrace: "test backtrace content",
        };
        let record = log::Record::builder()
            .args(format_args!("test"))
            .level(Level::Error)
            .key_values(&kvs)
            .build();

        let extracted = extract_anyhow_backtrace(&record);
        assert_eq!(extracted, Some("test backtrace content".to_string()));
    }

    #[test]
    fn test_extract_anyhow_backtrace_without_key() {
        let record = log::Record::builder()
            .args(format_args!("test"))
            .level(Level::Error)
            .build();

        let extracted = extract_anyhow_backtrace(&record);
        assert!(extracted.is_none());
    }

    #[test]
    fn test_extract_anyhow_backtrace_with_other_keys() {
        use log::kv::{self, ToValue};

        struct TestKvs;

        impl kv::Source for TestKvs {
            fn visit<'a>(&'a self, visitor: &mut dyn kv::VisitSource<'a>) -> Result<(), kv::Error> {
                visitor.visit_pair(kv::Key::from_str("other_key"), "other_value".to_value())?;
                visitor.visit_pair(kv::Key::from_str("another_key"), 42i32.to_value())
            }
        }

        let kvs = TestKvs;
        let record = log::Record::builder()
            .args(format_args!("test"))
            .level(Level::Error)
            .key_values(&kvs)
            .build();

        let extracted = extract_anyhow_backtrace(&record);
        assert!(extracted.is_none());
    }
}
