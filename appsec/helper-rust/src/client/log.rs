use std::backtrace::Backtrace;

use futures::Future;
use tokio::task_local;

task_local! {
    pub static CLIENT_ID: u64;
}

/// Key used to pass anyhow backtrace through log's key-value API
pub const ANYHOW_BACKTRACE_KEY: &str = "__anyhow_backtrace";

/// Logs an error with an optional backtrace, forcing the emitted record location.
#[doc(hidden)]
pub fn log_error_with_backtrace_at(
    file: &'static str,
    line: u32,
    module_path: &'static str,
    msg: &str,
    backtrace: Option<&Backtrace>,
) {
    if !log::log_enabled!(log::Level::Error) {
        return;
    }

    let formatted_msg = if let Ok(client_id) = CLIENT_ID.try_with(|id| *id) {
        format!("Client #{}: {}", client_id, msg)
    } else {
        msg.to_string()
    };

    struct BacktraceKvs<'a> {
        bt: &'a Backtrace,
    }

    impl log::kv::Source for BacktraceKvs<'_> {
        fn visit<'a>(
            &'a self,
            visitor: &mut dyn log::kv::VisitSource<'a>,
        ) -> Result<(), log::kv::Error> {
            visitor.visit_pair(
                log::kv::Key::from_str(ANYHOW_BACKTRACE_KEY),
                log::kv::Value::from_display(&self.bt),
            )
        }
    }

    struct EmptSource;
    impl log::kv::Source for EmptSource {
        fn visit<'a>(
            &'a self,
            _visitor: &mut dyn log::kv::VisitSource<'a>,
        ) -> Result<(), log::kv::Error> {
            Ok(())
        }
    }

    let kvs: Box<dyn log::kv::Source> = if let Some(bt) = backtrace {
        Box::new(BacktraceKvs { bt })
    } else {
        Box::new(EmptSource)
    };

    let submit_and_log = |record: &log::Record<'_>| {
        crate::telemetry::submit_error_to_telemetry(record);
        log::logger().log(record);
    };
    submit_and_log(
        &log::Record::builder()
            .args(format_args!("{}", formatted_msg))
            .level(log::Level::Error)
            .target(module_path)
            .module_path(Some(module_path))
            .file(Some(file))
            .line(Some(line))
            .key_values(&kvs)
            .build(),
    );
}

pub trait TryGetBacktrace {
    fn try_get_backtrace(&self) -> Option<&Backtrace>;
}

impl TryGetBacktrace for anyhow::Error {
    #[inline]
    fn try_get_backtrace(&self) -> Option<&Backtrace> {
        let bt = self.backtrace();
        match bt.status() {
            std::backtrace::BacktraceStatus::Captured => Some(bt),
            _ => None,
        }
    }
}

impl<T: ?Sized> TryGetBacktrace for &T {
    #[inline]
    fn try_get_backtrace(&self) -> Option<&Backtrace> {
        None
    }
}

macro_rules! client_log {
    ($level:expr, $log_macro:ident, $($arg:tt)*) => {{
        let target = module_path!();
        if ::log::log_enabled!(target: target, $level) {
            if let Ok(client_id) = $crate::client::log::CLIENT_ID.try_with(|id| *id) {
                ::log::$log_macro!(
                    target: target,
                    "Client #{}: {}",
                    client_id,
                    ::std::format_args!($($arg)*)
                );
            } else {
                ::log::$log_macro!(target: target, $($arg)*);
            }
        }
    }};
}
pub(crate) use client_log;

macro_rules! client_log_gen {
    ($level:expr, $($arg:tt)*) => {
        match $level {
            ::log::Level::Trace => crate::client::log::trace!($($arg)*),
            ::log::Level::Debug => crate::client::log::debug!($($arg)*),
            ::log::Level::Info => crate::client::log::info!($($arg)*),
            ::log::Level::Warn => crate::client::log::warning!($($arg)*),
            ::log::Level::Error => crate::client::log::error!($($arg)*),
        }
    };
}
pub(crate) use client_log_gen;

macro_rules! trace {
    ($($arg:tt)*) => {
        crate::client::log::client_log!(::log::Level::Trace, trace, $($arg)*)
    };
}
pub(crate) use trace;

macro_rules! debug {
    ($($arg:tt)*) => {
        crate::client::log::client_log!(::log::Level::Debug, debug, $($arg)*)
    };
}
pub(crate) use debug;

macro_rules! info {
    ($($arg:tt)*) => {
        crate::client::log::client_log!(::log::Level::Info, info, $($arg)*)
    };
}
pub(crate) use info;

macro_rules! warning {
    ($($arg:tt)*) => {
        crate::client::log::client_log!(::log::Level::Warn, warn, $($arg)*)
    };
}
pub(crate) use warning;

#[macro_export]
macro_rules! error {
    // No arguments - just format string
    ($fmt:literal) => {{
        $crate::client::log::log_error_with_backtrace_at(
            file!(),
            line!(),
            module_path!(),
            $fmt,
            None,
        );
    }};

    // With arguments - check for anyhow backtrace
    ($fmt:literal, $($arg:expr),* $(,)?) => {{
        use $crate::client::log::TryGetBacktrace;
        let __msg = format!($fmt, $($arg),*);

        let mut __found = false;
        $(
            if !__found {
                if let Some(bt) = (&$arg).try_get_backtrace() {
                    $crate::client::log::log_error_with_backtrace_at(
                        file!(),
                        line!(),
                        module_path!(),
                        &__msg,
                        Some(bt),
                    );
                    __found = true;
                }
            }
        )*
        if !__found {
            $crate::client::log::log_error_with_backtrace_at(
                file!(),
                line!(),
                module_path!(),
                &__msg,
                None,
            );
        }
    }};
}
pub(crate) use error;

#[macro_export]
macro_rules! error_once {
    ($fmt:literal $(, $($arg:expr),* $(,)?)?) => {{
        static __ERROR_ONCE: ::std::sync::atomic::AtomicBool =
            ::std::sync::atomic::AtomicBool::new(false);
        if ::log::log_enabled!(::log::Level::Error)
            && !__ERROR_ONCE.swap(true, ::std::sync::atomic::Ordering::Relaxed)
        {
            #[allow(unused_imports)]
            use $crate::client::log::TryGetBacktrace;
            let mut __msg = ::std::format!($fmt $(, $($arg),*)?);
            __msg.push_str(" (this error will only be logged once)");

            let mut __found = false;
            $(
                if !__found {
                    if let Some(bt) = (&$arg).try_get_backtrace() {
                        $crate::client::log::log_error_with_backtrace_at(
                            file!(),
                            line!(),
                            module_path!(),
                            &__msg,
                            Some(bt),
                        );
                        __found = true;
                    }
                }
            )*
            if !__found {
                $crate::client::log::log_error_with_backtrace_at(
                    file!(),
                    line!(),
                    module_path!(),
                    &__msg,
                    None,
                );
            }
        }
    }};
}

pub async fn with_scoped_client_id<F>(client_id: u64, fut: F)
where
    F: Future,
{
    CLIENT_ID.scope(client_id, fut).await;
}

pub fn fmt_bin(vec: &[u8]) -> impl std::fmt::Debug + '_ {
    struct BinFormatter<'a>(&'a [u8]);
    impl<'a> std::fmt::Debug for BinFormatter<'a> {
        fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
            for &c in self.0 {
                if c.is_ascii_graphic() {
                    write!(f, "{}", c as char)?;
                } else {
                    write!(f, "\\x{:02x}", c)?;
                }
            }
            Ok(())
        }
    }

    BinFormatter(vec)
}

#[cfg(test)]
mod tests {
    use std::fmt;
    use std::sync::atomic::{AtomicUsize, Ordering};
    use std::sync::{Mutex, Once};

    use serial_test::serial;

    use super::{debug, info, CLIENT_ID};

    static LOGGER_INIT: Once = Once::new();
    static FORMAT_CALLS: AtomicUsize = AtomicUsize::new(0);
    static TEST_LOGGER: TestLogger = TestLogger {
        messages: Mutex::new(Vec::new()),
    };

    struct TestLogger {
        messages: Mutex<Vec<String>>,
    }

    impl log::Log for TestLogger {
        fn enabled(&self, metadata: &log::Metadata<'_>) -> bool {
            metadata.level() <= log::Level::Info
                && metadata.target() == "ddappsec_helper::client::log::tests"
        }

        fn log(&self, record: &log::Record<'_>) {
            if self.enabled(record.metadata()) {
                self.messages
                    .lock()
                    .unwrap()
                    .push(record.args().to_string());
            }
        }

        fn flush(&self) {}
    }

    struct CountedDisplay;

    impl fmt::Display for CountedDisplay {
        fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
            FORMAT_CALLS.fetch_add(1, Ordering::Relaxed);
            f.write_str("formatted")
        }
    }

    #[tokio::test]
    #[serial]
    async fn client_log_only_formats_messages_consumed_by_the_logger() {
        LOGGER_INIT.call_once(|| {
            log::set_logger(&TEST_LOGGER).unwrap();
            log::set_max_level(log::LevelFilter::Trace);
        });
        FORMAT_CALLS.store(0, Ordering::Relaxed);
        TEST_LOGGER.messages.lock().unwrap().clear();

        CLIENT_ID
            .scope(42, async {
                debug!("filtered {}", CountedDisplay);
                assert_eq!(FORMAT_CALLS.load(Ordering::Relaxed), 0);

                info!("accepted {}", CountedDisplay);
            })
            .await;

        assert_eq!(FORMAT_CALLS.load(Ordering::Relaxed), 1);
        assert_eq!(
            TEST_LOGGER.messages.lock().unwrap().as_slice(),
            ["Client #42: accepted formatted"]
        );
    }
}
