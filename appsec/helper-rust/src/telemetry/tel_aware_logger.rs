use log::kv::{Key, VisitSource};
use log::Record;
use std::backtrace::Backtrace;
use std::borrow::Cow;
use std::cell::Cell;

use super::{LogLevel, TelemetryLog, TelemetryLogSubmitter, TelemetryTags};
use crate::client::log::ANYHOW_BACKTRACE_KEY;
use crate::telemetry::error_tel_ctx::get_context_log_submitter;

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

pub(crate) fn submit_error_to_telemetry(record: &Record) {
    let Some(_guard) = RecursionGuard::enter() else {
        return;
    };

    if should_rate_limit() {
        return;
    }

    let Some(mut submitter) = get_context_log_submitter() else {
        return;
    };

    let mut tags = TelemetryTags::new();
    tags.add("log_type", "helper::logged_error");
    if let Some(module) = record.module_path() {
        tags.add("module", module);
    }

    let message = redact_waf_strings(&format!("{}", record.args())).into_owned();

    let location = if let (Some(module), Some(line)) = (record.module_path(), record.line()) {
        Cow::Owned(format!("{}:{}", module, line))
    } else if let Some(module) = record.module_path() {
        Cow::Borrowed(module)
    } else {
        Cow::Borrowed("unknown")
    };

    let stack_trace = extract_anyhow_backtrace(record).or_else(|| {
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

/// Replace every `WafString("…")` span with `WafString("<REDACTED>")`.
///
/// The Debug impl of libddwaf `WafString` escapes `"` as `\"` and `\` as `\\`
/// inside the delimiters, so the closing `")` can only be an unescaped `"`
/// followed by `)`. Returns `Cow::Borrowed` when no replacement is needed.
pub(crate) fn redact_waf_strings(msg: &str) -> Cow<'_, str> {
    const OPEN: &str = "WafString(\"";
    const REPLACEMENT: &str = "WafString(\"<REDACTED>\")";

    if !msg.contains(OPEN) {
        return Cow::Borrowed(msg);
    }

    let mut out = String::with_capacity(msg.len());
    let mut rest = msg;

    while let Some(open_at) = rest.find(OPEN) {
        let content = &rest[open_at + OPEN.len()..];
        let Some(end) = find_waf_string_end(content) else {
            break;
        };
        out.push_str(&rest[..open_at]);
        out.push_str(REPLACEMENT);
        rest = &content[end..];
    }

    out.push_str(rest);
    Cow::Owned(out)
}

/// Given the bytes right after the opening `WafString("`, return the offset
/// just past the closing `")`, treating `\\` and `\"` as escapes inside the
/// quoted content. Returns `None` if the string is unterminated.
fn find_waf_string_end(content: &str) -> Option<usize> {
    let bytes = content.as_bytes();
    let mut i = 0;
    while i < bytes.len() {
        match bytes[i] {
            b'\\' if i + 1 < bytes.len() => i += 2, // skip escaped char
            b'"' if bytes.get(i + 1) == Some(&b')') => return Some(i + 2),
            b'"' => return None, // bare `"` not followed by `)` — malformed
            _ => i += 1,
        }
    }
    None
}

fn extract_anyhow_backtrace(record: &Record) -> Option<String> {
    let mut extractor = BacktraceExtractor { backtrace: None };
    let _ = record.key_values().visit(&mut extractor);
    extractor.backtrace
}

#[cfg(test)]
mod tests {
    use super::*;
    use log::Level;

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
    fn test_redact_waf_strings_no_match_is_borrowed() {
        let input = "no waf data here, just text";
        let out = redact_waf_strings(input);
        assert!(matches!(out, Cow::Borrowed(_)));
        assert_eq!(out, input);
    }

    #[test]
    fn test_redact_waf_strings_single() {
        let input = r#"error: WafString("Mozilla/5.0 secret")"#;
        let out = redact_waf_strings(input);
        assert_eq!(out, r#"error: WafString("<REDACTED>")"#);
    }

    #[test]
    fn test_redact_waf_strings_escaped_quote_not_treated_as_close() {
        let input = r#"WafString("say \"hi\"")"#;
        let out = redact_waf_strings(input);
        assert_eq!(out, r#"WafString("<REDACTED>")"#);
    }

    #[test]
    fn test_redact_waf_strings_escaped_backslash_before_close() {
        let input = r#"WafString("trailing backslash\\")"#;
        let out = redact_waf_strings(input);
        assert_eq!(out, r#"WafString("<REDACTED>")"#);
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
