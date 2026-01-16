use futures::Future;
use tokio::task_local;

task_local! {
    pub static CLIENT_ID: u64;
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

macro_rules! error {
    ($($arg:tt)*) => { crate::client::log::client_log!(error, $($arg)*) };
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
