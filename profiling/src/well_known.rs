use datadog_alloc::Global;
use std::borrow::Cow;
use std::ops::Deref;

use datadog_thin_str::{ConstStorage, ThinString};

#[derive(Copy, Clone)]
pub enum WellKnown {
    Empty,

    BracketedEval,
    BracketedFatal,
    BracketedGc,
    BracketedIdle,
    BracketedInclude,
    BracketedRequire,
    BracketedSelect,
    BracketedSleeping,
    BracketedTruncated,
    BracketedUnknownIncludeType,

    Compilation,
    Engine,
    Fatal,
    Idle,
    Include,
    Induced,
    OpcacheRestart,
    PhpOpenTag,
    Require,
    Select,
    Sleeping,
    Unknown,

    #[cfg(php_zts)]
    BracketedThreadStart,
    #[cfg(php_zts)]
    BracketedThreadStop,
    #[cfg(php_zts)]
    ThreadStart,
    #[cfg(php_zts)]
    ThreadStop,
}

impl From<WellKnown> for Cow<'static, str> {
    fn from(well_known: WellKnown) -> Self {
        let storage: &'static _ = match well_known {
            WellKnown::Empty => inline_strings::EMPTY.as_storage(),

            WellKnown::BracketedEval => inline_strings::BRACKETED_EVAL.as_storage(),
            WellKnown::BracketedFatal => inline_strings::BRACKETED_FATAL.as_storage(),
            WellKnown::BracketedGc => inline_strings::BRACKETED_GC.as_storage(),
            WellKnown::BracketedIdle => inline_strings::BRACKETED_IDLE.as_storage(),
            WellKnown::BracketedInclude => inline_strings::BRACKETED_INCLUDE.as_storage(),
            WellKnown::BracketedRequire => inline_strings::BRACKETED_REQUIRE.as_storage(),
            WellKnown::BracketedSelect => inline_strings::BRACKETED_SELECT.as_storage(),
            WellKnown::BracketedSleeping => inline_strings::BRACKETED_SLEEPING.as_storage(),
            WellKnown::BracketedTruncated => inline_strings::BRACKETED_TRUNCATED.as_storage(),
            WellKnown::BracketedUnknownIncludeType => {
                inline_strings::BRACKETED_UNKNOWN_INCLUDE_TYPE.as_storage()
            }

            WellKnown::Compilation => inline_strings::COMPILATION.as_storage(),
            WellKnown::Engine => inline_strings::ENGINE.as_storage(),
            WellKnown::Fatal => inline_strings::FATAL.as_storage(),
            WellKnown::Idle => inline_strings::IDLE.as_storage(),
            WellKnown::Include => inline_strings::INCLUDE.as_storage(),
            WellKnown::Induced => inline_strings::INDUCED.as_storage(),
            WellKnown::OpcacheRestart => inline_strings::OPCACHE_RESTART.as_storage(),
            WellKnown::PhpOpenTag => inline_strings::PHP_OPEN_TAG.as_storage(),
            WellKnown::Require => inline_strings::REQUIRE.as_storage(),
            WellKnown::Select => inline_strings::SELECT.as_storage(),
            WellKnown::Sleeping => inline_strings::SLEEPING.as_storage(),
            WellKnown::Unknown => inline_strings::UNKNOWN.as_storage(),

            #[cfg(php_zts)]
            WellKnown::BracketedThreadStart => inline_strings::BRACKETED_THREAD_START.as_storage(),
            #[cfg(php_zts)]
            WellKnown::BracketedThreadStop => inline_strings::BRACKETED_THREAD_STOP.as_storage(),
            #[cfg(php_zts)]
            WellKnown::ThreadStart => inline_strings::THREAD_START.as_storage(),
            #[cfg(php_zts)]
            WellKnown::ThreadStop => inline_strings::THREAD_STOP.as_storage(),
        };
        Cow::Borrowed(storage.deref())
    }
}

impl From<WellKnown> for ThinString<Global> {
    fn from(well_known: WellKnown) -> Self {
        match well_known {
            WellKnown::Empty => ThinString::from(&inline_strings::EMPTY),

            WellKnown::BracketedEval => ThinString::from(&inline_strings::BRACKETED_EVAL),
            WellKnown::BracketedFatal => ThinString::from(&inline_strings::BRACKETED_FATAL),
            WellKnown::BracketedGc => ThinString::from(&inline_strings::BRACKETED_GC),
            WellKnown::BracketedIdle => ThinString::from(&inline_strings::BRACKETED_IDLE),
            WellKnown::BracketedInclude => ThinString::from(&inline_strings::BRACKETED_INCLUDE),
            WellKnown::BracketedRequire => ThinString::from(&inline_strings::BRACKETED_REQUIRE),
            WellKnown::BracketedSelect => ThinString::from(&inline_strings::BRACKETED_SELECT),
            WellKnown::BracketedSleeping => ThinString::from(&inline_strings::BRACKETED_SLEEPING),
            WellKnown::BracketedTruncated => ThinString::from(&inline_strings::BRACKETED_TRUNCATED),
            WellKnown::BracketedUnknownIncludeType => {
                ThinString::from(&inline_strings::BRACKETED_UNKNOWN_INCLUDE_TYPE)
            }

            WellKnown::Compilation => ThinString::from(&inline_strings::COMPILATION),
            WellKnown::Engine => ThinString::from(&inline_strings::ENGINE),
            WellKnown::Fatal => ThinString::from(&inline_strings::FATAL),
            WellKnown::Idle => ThinString::from(&inline_strings::IDLE),
            WellKnown::Include => ThinString::from(&inline_strings::INCLUDE),
            WellKnown::Induced => ThinString::from(&inline_strings::INDUCED),
            WellKnown::OpcacheRestart => ThinString::from(&inline_strings::OPCACHE_RESTART),
            WellKnown::PhpOpenTag => ThinString::from(&inline_strings::PHP_OPEN_TAG),
            WellKnown::Require => ThinString::from(&inline_strings::REQUIRE),
            WellKnown::Select => ThinString::from(&inline_strings::SELECT),
            WellKnown::Sleeping => ThinString::from(&inline_strings::SLEEPING),
            WellKnown::Unknown => ThinString::from(&inline_strings::UNKNOWN),

            #[cfg(php_zts)]
            WellKnown::BracketedThreadStart => {
                ThinString::from(&inline_strings::BRACKETED_THREAD_START)
            }
            #[cfg(php_zts)]
            WellKnown::BracketedThreadStop => {
                ThinString::from(&inline_strings::BRACKETED_THREAD_STOP)
            }
            #[cfg(php_zts)]
            WellKnown::ThreadStart => ThinString::from(&inline_strings::THREAD_START),
            #[cfg(php_zts)]
            WellKnown::ThreadStop => ThinString::from(&inline_strings::THREAD_STOP),
        }
    }
}

mod inline_strings {
    use super::*;

    pub static EMPTY: ConstStorage<0> = ConstStorage::from_str("");

    pub static BRACKETED_EVAL: ConstStorage<6> = ConstStorage::from_str("[eval]");
    pub static BRACKETED_FATAL: ConstStorage<7> = ConstStorage::from_str("[fatal]");
    pub static BRACKETED_GC: ConstStorage<4> = ConstStorage::from_str("[gc]");
    pub static BRACKETED_IDLE: ConstStorage<6> = ConstStorage::from_str("[idle]");
    pub static BRACKETED_INCLUDE: ConstStorage<9> = ConstStorage::from_str("[include]");
    pub static BRACKETED_REQUIRE: ConstStorage<9> = ConstStorage::from_str("[require]");
    pub static BRACKETED_SELECT: ConstStorage<8> = ConstStorage::from_str("[select]");
    pub static BRACKETED_SLEEPING: ConstStorage<10> = ConstStorage::from_str("[sleeping]");
    pub static BRACKETED_TRUNCATED: ConstStorage<11> = ConstStorage::from_str("[truncated]");
    pub static BRACKETED_UNKNOWN_INCLUDE_TYPE: ConstStorage<22> =
        ConstStorage::from_str("[unknown include type]");

    pub static COMPILATION: ConstStorage<11> = ConstStorage::from_str("compilation");
    pub static ENGINE: ConstStorage<6> = ConstStorage::from_str("engine");
    pub static FATAL: ConstStorage<5> = ConstStorage::from_str("fatal");
    pub static IDLE: ConstStorage<4> = ConstStorage::from_str("idle");
    pub static INCLUDE: ConstStorage<7> = ConstStorage::from_str("include");
    pub static INDUCED: ConstStorage<7> = ConstStorage::from_str("induced");
    // todo: use space instead to match "thread start"?
    pub static OPCACHE_RESTART: ConstStorage<15> = ConstStorage::from_str("opcache_restart");
    pub static PHP_OPEN_TAG: ConstStorage<5> = ConstStorage::from_str("<?php");
    pub static REQUIRE: ConstStorage<7> = ConstStorage::from_str("require");
    pub static SELECT: ConstStorage<6> = ConstStorage::from_str("select");
    pub static SLEEPING: ConstStorage<8> = ConstStorage::from_str("sleeping");
    pub static UNKNOWN: ConstStorage<7> = ConstStorage::from_str("unknown");

    #[cfg(php_zts)]
    pub static BRACKETED_THREAD_START: ConstStorage<15> = ConstStorage::from_str("[thread start]");
    #[cfg(php_zts)]
    pub static BRACKETED_THREAD_STOP: ConstStorage<14> = ConstStorage::from_str("[thread stop]");
    #[cfg(php_zts)]
    pub static THREAD_START: ConstStorage<13> = ConstStorage::from_str("thread start");
    #[cfg(php_zts)]
    pub static THREAD_STOP: ConstStorage<12> = ConstStorage::from_str("thread stop");
}
