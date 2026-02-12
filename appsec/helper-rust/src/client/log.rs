use std::backtrace::Backtrace;

use futures::Future;
use tokio::task_local;

task_local! {
    pub static CLIENT_ID: u64;
}

/// Key used to pass anyhow backtrace through log's key-value API
pub const ANYHOW_BACKTRACE_KEY: &str = "__anyhow_backtrace";

/// Wrapper to pass backtrace through log's kv API
pub struct BacktraceValue<'a>(pub &'a Backtrace);

impl log::kv::ToValue for BacktraceValue<'_> {
    fn to_value(&self) -> log::kv::Value<'_> {
        log::kv::Value::from_display(self.0)
    }
}

/// Internal helper for error logging with optional backtrace
#[doc(hidden)]
pub fn log_error_with_backtrace(msg: &str, backtrace: Option<&Backtrace>) {
    let formatted_msg = if let Ok(client_id) = CLIENT_ID.try_with(|id| *id) {
        format!("Client #{}: {}", client_id, msg)
    } else {
        msg.to_string()
    };

    if let Some(bt) = backtrace {
        log::error!(
            { ANYHOW_BACKTRACE_KEY } = BacktraceValue(bt);
            "{}",
            formatted_msg
        );
    } else {
        log::error!("{}", formatted_msg);
    }
}

pub trait TryGetBacktrace {
    fn try_get_backtrace(&self) -> Option<&Backtrace>;
}

impl TryGetBacktrace for anyhow::Error {
    #[inline]
    fn try_get_backtrace(&self) -> Option<&Backtrace> {
        Some(self.backtrace())
    }
}

impl<T: ?Sized> TryGetBacktrace for &T {
    #[inline]
    fn try_get_backtrace(&self) -> Option<&Backtrace> {
        None
    }
}

macro_rules! client_log {
    ($level:ident, $($arg:tt)*) => {
        if let Ok(client_id) = $crate::client::log::CLIENT_ID.try_with(|id| *id) {
            ::log::$level!("Client #{}: {}", client_id, format!($($arg)*));
        } else {
            ::log::$level!("{}", format!($($arg)*));
        }
    };
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
    ($($arg:tt)*) => { crate::client::log::client_log!(trace, $($arg)*) };
}
pub(crate) use trace;

macro_rules! debug {
    ($($arg:tt)*) => { crate::client::log::client_log!(debug, $($arg)*) };
}
pub(crate) use debug;

macro_rules! info {
    ($($arg:tt)*) => { crate::client::log::client_log!(info, $($arg)*) };
}
pub(crate) use info;

macro_rules! warning {
    ($($arg:tt)*) => { crate::client::log::client_log!(warn, $($arg)*) };
}
pub(crate) use warning;

#[macro_export]
macro_rules! error {
    // No arguments - just format string
    ($fmt:literal) => {{
        $crate::client::log::log_error_with_backtrace($fmt, None);
    }};

    // With arguments - check for anyhow backtrace
    ($fmt:literal, $($arg:expr),* $(,)?) => {{
        use $crate::client::log::TryGetBacktrace;
        let __msg = format!($fmt, $($arg),*);

        let mut __found = false;
        $(
            if !__found {
                if let Some(bt) = (&$arg).try_get_backtrace() {
                    $crate::client::log::log_error_with_backtrace(&__msg, Some(bt));
                    __found = true;
                }
            }
        )*
        if !__found {
            $crate::client::log::log_error_with_backtrace(&__msg, None);
        }
    }};
}
pub(crate) use error;

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
