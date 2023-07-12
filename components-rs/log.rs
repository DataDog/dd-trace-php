use std::cell::RefCell;
use std::collections::BTreeSet;
use std::ffi::c_char;
use std::slice;
use bitflags::bitflags;
use ddcommon_ffi::CharSlice;
use ddcommon_ffi::slice::AsBytes;

bitflags! {
    #[derive(Clone, Copy, Debug, PartialEq, Eq, Hash)]
    #[repr(C)]
    pub struct Log: u32 {
        const None = 0;
        const Once = 1 << 0;
        const Error = 1 << 1;
        const Warn = 1 << 2;
        const Info = 1 << 3;
        const Deprecated = (1 << 4) | 1 /* Once */;
        const SpanTracing = 1 << 5;
        const Sampling = 1 << 6;
        const Startup = 1 << 7;
    }
}

#[macro_export]
macro_rules! log {
    ($source:ident, $msg:expr) => { if ($crate::ddog_shall_log($crate::Log::$source)) { $crate::log($crate::Log::$source, $msg) } }
}

#[allow_internal_unstable(thread_local)]
macro_rules! thread_local {
    ($($tokens:tt)*) => {
        #[thread_local]
        $($tokens)*
    }
}

#[no_mangle]
#[allow(non_upper_case_globals)]
pub static mut ddog_log_callback: Option<extern "C" fn(Log, CharSlice)> = None;

// Avoid RefCell for performance
thread_local! { static mut LOG_LEVEL: Log = Log::Once.union(Log::Error); }
std::thread_local! {
    static LOGGED_MSGS: RefCell<BTreeSet<String>> = RefCell::default();
}

#[no_mangle]
pub extern "C" fn ddog_shall_log(level: Log) -> bool {
    unsafe { LOG_LEVEL }.contains(level & !Log::Once)
}

pub fn log<S>(level: Log, msg: S) where S: AsRef<str> {
    ddog_log(level, CharSlice::from(msg.as_ref()))
}

#[no_mangle]
pub extern "C" fn ddog_set_log_level(level: Log) {
    unsafe { LOG_LEVEL = level; }
}

#[no_mangle]
pub unsafe extern "C" fn ddog_parse_log_level(levels: *const CharSlice, num: usize, startup_logs_by_default: bool) {
    let mut total_level = Log::None;
    let levels = slice::from_raw_parts(levels, num);

    if levels.len() == 1 {
        let first_level = levels[0].to_utf8_lossy();
        if first_level == "1" || first_level == "true" || first_level == "On" {
            total_level = Log::Error | Log::Warn | Log::Info | Log::Deprecated;
            if startup_logs_by_default {
                total_level |= Log::Startup;
            }
        }
    }

    for level_name in levels {
        for (name, level) in Log::all().iter_names() {
            if name.eq_ignore_ascii_case(&level_name.to_utf8_lossy()) {
                total_level |= level;
            }
        }
    }

    // Info always implies warn
    if total_level.contains(Log::Info) {
        total_level |= Log::Warn;
    }
    // Warn always implies error
    if total_level.contains(Log::Warn) {
        total_level |= Log::Error;
    }

    ddog_set_log_level(total_level);
}

#[no_mangle]
pub extern "C" fn ddog_log(level: Log, msg: CharSlice) {
    if let Some(cb) = unsafe { ddog_log_callback } {
        if level.contains(Log::Once) && !unsafe { LOG_LEVEL }.contains(Log::Once) {
            LOGGED_MSGS.with(|logged| {
                let mut logged = logged.borrow_mut();
                let msgstr = unsafe { msg.to_utf8_lossy() };
                if !logged.contains(msgstr.as_ref()) {
                    let msg = msgstr.to_string();
                    let once_msg = format!("{}; This message is only displayed once. Append 'Once' DD_TRACE_DEBUG= to show all messages (e.g. DD_TRACE_DEBUG=Error,Once).\0", msg);
                    cb(level, unsafe { CharSlice::new(once_msg.as_ptr() as *const c_char, once_msg.len() - 1) });
                    logged.insert(msg);
                }
            });
        } else {
            cb(level, msg);
        }
    }
}

#[no_mangle]
pub extern "C" fn ddog_reset_log_once() {
    LOGGED_MSGS.with(|logged| {
        let mut logged = logged.borrow_mut();
        logged.clear();
    });
}
