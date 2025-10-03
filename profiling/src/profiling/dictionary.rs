// Copyright 2025-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

use crate::RefCellExt;
use datadog_profiling::profiles::collections::{Arc as DdArc, ArcOverflow, StringId};
use datadog_profiling::profiles::datatypes::{Function, FunctionId, ProfilesDictionary};
use datadog_profiling::profiles::ProfileError;
use once_cell::sync::OnceCell;
use std::cell::RefCell;
use std::sync::RwLock;

const EVAL_STR: &str = "[eval]";
const FATAL_STR: &str = "[fatal]";
const GC_STR: &str = "[gc]";
const IDLE_STR: &str = "[idle]";
const INCLUDE_STR: &str = "[include]";
const OPCACHE_RESTART: &str = "[opcache restart]";
const REQUIRE_STR: &str = "[require]";
const TRUNCATED_STR: &str = "[truncated]";

pub struct KnownStringIds {
    pub eval: StringId,
    pub fatal: StringId,
    pub gc: StringId,
    pub idle: StringId,
    pub include: StringId,
    pub opcache_restart: StringId,
    pub require: StringId,
    pub truncated: StringId,

    #[cfg(php_zts)]
    pub thread_start: StringId,
    #[cfg(php_zts)]
    pub thread_stop: StringId,
}

pub struct KnownFunctionIds {
    pub gc: FunctionId,
    pub idle: FunctionId,
    pub include: FunctionId,
    pub require: FunctionId,
    pub truncated: FunctionId,

    #[cfg(php_zts)]
    pub thread_start: FunctionId,
    #[cfg(php_zts)]
    pub thread_stop: FunctionId,
}

pub struct PhpProfilesDictionary {
    dict: ProfilesDictionary,
    known_strs: KnownStringIds,
    known_funcs: KnownFunctionIds,
}

// SAFETY: the Ids are owned by the dictionary, so they aren't out-of-sync.
unsafe impl Send for PhpProfilesDictionary {}
// SAFETY: the Ids are owned by the dictionary, so they aren't out-of-sync.
unsafe impl Sync for PhpProfilesDictionary {}

impl PhpProfilesDictionary {
    pub fn dictionary(&self) -> &ProfilesDictionary {
        &self.dict
    }

    pub fn known_strs(&self) -> &KnownStringIds {
        &self.known_strs
    }

    pub fn known_funcs(&self) -> &KnownFunctionIds {
        &self.known_funcs
    }

    pub fn try_new() -> Result<Self, ProfileError> {
        // Try to create a fresh dictionary and store it.
        let dict = ProfilesDictionary::try_new()?;

        // Add some strings that are used later to avoid needing to grow the
        // dictionary at that point in time, freeing up the hot path.
        // Some of these are not that hot, but may as well do it once here on
        // startup.
        // As an example of a definitely useful one, IDLE_STR is used on every
        // rinit.
        let strings = dict.strings();
        let functions = dict.functions();
        let known_strs = KnownStringIds {
            eval: strings.try_insert(EVAL_STR)?,
            fatal: strings.try_insert(FATAL_STR)?,
            gc: strings.try_insert(GC_STR)?,
            idle: strings.try_insert(IDLE_STR)?,
            include: strings.try_insert(INCLUDE_STR)?,
            opcache_restart: strings.try_insert(OPCACHE_RESTART)?,
            require: strings.try_insert(REQUIRE_STR)?,
            truncated: strings.try_insert(TRUNCATED_STR)?,
            #[cfg(php_zts)]
            thread_start: strings.try_insert("[thread start]")?,
            #[cfg(php_zts)]
            thread_stop: strings.try_insert("[thread stop]")?,
        };
        let known_funcs = KnownFunctionIds {
            gc: functions
                .try_insert(Function {
                    name: known_strs.gc,
                    ..Function::default()
                })?
                .into_raw(),
            idle: functions
                .try_insert(Function {
                    name: known_strs.idle,
                    ..Function::default()
                })?
                .into_raw(),
            include: functions
                .try_insert(Function {
                    name: known_strs.include,
                    ..Function::default()
                })?
                .into_raw(),
            require: functions
                .try_insert(Function {
                    name: known_strs.require,
                    ..Function::default()
                })?
                .into_raw(),
            truncated: functions
                .try_insert(Function {
                    name: known_strs.truncated,
                    ..Function::default()
                })?
                .into_raw(),
            #[cfg(php_zts)]
            thread_start: functions
                .try_insert(Function {
                    name: known_strs.thread_start,
                    ..Function::default()
                })?
                .into_raw(),
            #[cfg(php_zts)]
            thread_stop: functions
                .try_insert(Function {
                    name: known_strs.thread_stop,
                    ..Function::default()
                })?
                .into_raw(),
        };
        Ok(PhpProfilesDictionary {
            dict,
            known_strs,
            known_funcs,
        })
    }
}

// Global profiles dictionary shared across the process lifetime.
// This is intentionally wrapped in a RwLock to allow future rotation.
static GLOBAL_DICTIONARY: OnceCell<RwLock<DdArc<PhpProfilesDictionary>>> = OnceCell::new();

thread_local! {
    // Thread-local dictionary reference, cloned from the global in ginit.
    static TLS_DICTIONARY: RefCell<Option<DdArc<PhpProfilesDictionary>>> = const { RefCell::new(None) };
}

/// Initializes the global dictionary if it has not been initialized yet.
fn init_global_if_needed() -> Result<(), ProfileError> {
    if GLOBAL_DICTIONARY.get().is_some() {
        return Ok(());
    }

    let arc = DdArc::try_new(PhpProfilesDictionary::try_new()?)?;
    // If another thread beat us to initialization, just drop ours.
    let _ = GLOBAL_DICTIONARY.set(RwLock::new(arc));
    Ok(())
}

/// Clone a reference to the current global dictionary.
pub fn try_clone_global() -> Result<DdArc<PhpProfilesDictionary>, ProfileError> {
    init_global_if_needed()?;
    // SAFETY: OnceCell has been initialized above.
    let lock = GLOBAL_DICTIONARY
        .get()
        .expect("global profiles dictionary not initialized");

    let guard = lock
        .read()
        .expect("global profiles dictionary lock poisoned");
    Ok(guard.try_clone()?)
}

/// Returns whether two dictionary Arcs refer to the exact same allocation.
#[inline]
pub fn dict_ptr_eq(a: &DdArc<ProfilesDictionary>, b: &DdArc<ProfilesDictionary>) -> bool {
    a.as_ptr() == b.as_ptr()
}

#[inline]
pub fn arc_ptr_eq(a: &DdArc<ProfilesDictionary>, b: &DdArc<ProfilesDictionary>) -> bool {
    dict_ptr_eq(a, b)
}

/// Ensures the "\[truncated\]" function exists in the given dictionary and
/// returns its function id.
pub fn truncated_function(dict: &PhpProfilesDictionary) -> Result<FunctionId, ProfileError> {
    let function = Function {
        name: dict.known_strs.truncated,
        system_name: StringId::EMPTY,
        file_name: StringId::EMPTY,
    };
    let id = dict.dict.functions().try_insert(function)?;
    Ok(id.into_raw())
}

/// Clone the global dictionary into TLS for the current thread.
pub fn ginit() {
    // Try to initialize and clone the global dict; on failure, leave TLS empty.
    if let Ok(arc) = try_clone_global() {
        let _ = TLS_DICTIONARY.try_with_borrow_mut(|slot| *slot = Some(arc));
    }
}

/// Drop the TLS dictionary reference for the current thread.
pub fn gshutdown() {
    let _ = TLS_DICTIONARY.try_with_borrow_mut(|slot| *slot = None);
}

/// Returns a try-cloned Arc to the TLS dictionary if present; otherwise tries the global.
pub fn try_clone_tls_or_global() -> Result<DdArc<PhpProfilesDictionary>, ProfileError> {
    if let Ok(Some(arc)) =
        TLS_DICTIONARY.try_with_borrow(|slot| slot.as_ref().and_then(|arc| arc.try_clone().ok()))
    {
        return Ok(arc);
    }
    try_clone_global()
}

#[derive(Debug, thiserror::Error)]
pub enum InitError {
    #[error("profiles dictionary allocation failed")]
    AllocError(#[from] allocator_api2::alloc::AllocError),
    #[error("profiles dictionary refcount overflow")]
    ArcOverflow(#[from] ArcOverflow),
    #[error("profiles dictionary poisoned lock")]
    Poisoned,
    #[error("profiles dictionary error: {0}")]
    ProfileError(#[from] ProfileError),
}
