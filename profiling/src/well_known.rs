use datadog_alloc::Global;

use datadog_thin_str::{ConstStorage, ThinString};

pub enum WellKnown {
    Empty,

    Eval,
    Fatal,
    Gc,
    Idle,
    Include,
    PhpOpenTag,
    Require,
    Truncated,
    UnknownIncludeType,
}

impl From<WellKnown> for ThinString<Global> {
    fn from(well_known: WellKnown) -> Self {
        match well_known {
            WellKnown::Empty => ThinString::from(&inline_strings::EMPTY),
            WellKnown::Eval => ThinString::from(&inline_strings::EVAL),
            WellKnown::Fatal => ThinString::from(&inline_strings::FATAL),
            WellKnown::Gc => ThinString::from(&inline_strings::GC),
            WellKnown::Idle => ThinString::from(&inline_strings::IDLE),
            WellKnown::Include => ThinString::from(&inline_strings::INCLUDE),
            WellKnown::PhpOpenTag => ThinString::from(&inline_strings::PHP_OPEN_TAG),
            WellKnown::Require => ThinString::from(&inline_strings::REQUIRE),
            WellKnown::Truncated => ThinString::from(&inline_strings::TRUNCATED),
            WellKnown::UnknownIncludeType => {
                ThinString::from(&inline_strings::UNKNOWN_INCLUDE_TYPE)
            }
        }
    }
}

mod inline_strings {
    use super::*;
    pub static EMPTY: ConstStorage<0> = ConstStorage::from_str("");
    pub static EVAL: ConstStorage<6> = ConstStorage::from_str("[eval]");
    pub static FATAL: ConstStorage<7> = ConstStorage::from_str("[fatal]");
    pub static GC: ConstStorage<4> = ConstStorage::from_str("[gc]");
    pub static IDLE: ConstStorage<6> = ConstStorage::from_str("[idle]");
    pub static INCLUDE: ConstStorage<9> = ConstStorage::from_str("[include]");
    pub static PHP_OPEN_TAG: ConstStorage<5> = ConstStorage::from_str("<?php");
    pub static REQUIRE: ConstStorage<9> = ConstStorage::from_str("[require]");
    pub static TRUNCATED: ConstStorage<11> = ConstStorage::from_str("[truncated]");
    pub static UNKNOWN_INCLUDE_TYPE: ConstStorage<22> =
        ConstStorage::from_str("[unknown include type]");
}
