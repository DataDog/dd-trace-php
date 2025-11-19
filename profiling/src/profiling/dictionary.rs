// Copyright 2025-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

use crate::RefCellExt;
use libdd_profiling::profiles::collections::{Arc as DdArc, ArcOverflow, SetError};
use libdd_profiling::profiles::datatypes::{Function2, FunctionId2, ProfilesDictionary, StringId2};
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
    pub eval: StringId2,
    pub fatal: StringId2,
    pub gc: StringId2,
    pub idle: StringId2,
    pub include: StringId2,
    pub opcache_restart: StringId2,
    pub require: StringId2,
    pub truncated: StringId2,

    #[cfg(php_zts)]
    pub thread_start: StringId2,
    #[cfg(php_zts)]
    pub thread_stop: StringId2,
}

pub struct KnownFunctionIds {
    pub gc: FunctionId2,
    pub idle: FunctionId2,
    pub include: FunctionId2,
    pub require: FunctionId2,
    pub truncated: FunctionId2,

    #[cfg(php_zts)]
    pub thread_start: FunctionId2,
    #[cfg(php_zts)]
    pub thread_stop: FunctionId2,
}

pub struct PhpProfilesDictionary {
    dict: DdArc<ProfilesDictionary>,
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

    pub fn try_new() -> Result<Self, SetError> {
        // Try to create a fresh dictionary and store it.
        let dict = DdArc::try_new(ProfilesDictionary::try_new()?)?;

        // Add some strings that are used later to avoid needing to grow the
        // dictionary at that point in time, freeing up the hot path.
        // Some of these are not that hot, but may as well do it once here on
        // startup.
        // As an example of a definitely useful one, IDLE_STR is used on every
        // rinit.
        let known_strs = KnownStringIds {
            eval: dict.try_insert_str2(EVAL_STR)?,
            fatal: dict.try_insert_str2(FATAL_STR)?,
            gc: dict.try_insert_str2(GC_STR)?,
            idle: dict.try_insert_str2(IDLE_STR)?,
            include: dict.try_insert_str2(INCLUDE_STR)?,
            opcache_restart: dict.try_insert_str2(OPCACHE_RESTART)?,
            require: dict.try_insert_str2(REQUIRE_STR)?,
            truncated: dict.try_insert_str2(TRUNCATED_STR)?,
            #[cfg(php_zts)]
            thread_start: dict.try_insert_str2("[thread start]")?,
            #[cfg(php_zts)]
            thread_stop: dict.try_insert_str2("[thread stop]")?,
        };
        let known_funcs = KnownFunctionIds {
            gc: dict.try_insert_function2(Function2 {
                name: known_strs.gc,
                ..Function2::default()
            })?,
            idle: dict.try_insert_function2(Function2 {
                name: known_strs.idle,
                ..Function2::default()
            })?,
            include: dict.try_insert_function2(Function2 {
                name: known_strs.include,
                ..Function2::default()
            })?,
            require: dict.try_insert_function2(Function2 {
                name: known_strs.require,
                ..Function2::default()
            })?,
            truncated: dict.try_insert_function2(Function2 {
                name: known_strs.truncated,
                ..Function2::default()
            })?,
            #[cfg(php_zts)]
            thread_start: dict.try_insert_function2(Function2 {
                name: known_strs.thread_start,
                ..Function2::default()
            })?,
            #[cfg(php_zts)]
            thread_stop: dict.try_insert_function2(Function2 {
                name: known_strs.thread_stop,
                ..Function2::default()
            })?,
        };
        Ok(PhpProfilesDictionary {
            dict,
            known_strs,
            known_funcs,
        })
    }

    /// Returns a cloned Arc to the underlying `ProfilesDictionary`.
    pub fn dictionary_arc(&self) -> Result<DdArc<ProfilesDictionary>, ArcOverflow> {
        self.dict.try_clone()
    }
}

// Global profiles dictionary shared across the process lifetime.
// This is intentionally wrapped in a RwLock to allow future rotation.
static mut GLOBAL_DICTIONARY: OnceCell<RwLock<DdArc<PhpProfilesDictionary>>> = OnceCell::new();

thread_local! {
    // Thread-local dictionary reference, cloned from the global in ginit.
    static TLS_DICTIONARY: RefCell<Option<DdArc<PhpProfilesDictionary>>> = const { RefCell::new(None) };
}

/// Initializes the global dictionary if it has not been initialized yet.
fn init_global_if_needed() -> Result<(), SetError> {
    // SAFETY: initialization (set) is idempotent; checking get is benign
    // Use raw pointer to avoid creating shared reference to mutable static
    if unsafe { (*core::ptr::addr_of!(GLOBAL_DICTIONARY)).get().is_some() } {
        return Ok(());
    }

    let arc = DdArc::try_new(PhpProfilesDictionary::try_new()?)?;
    // If another thread beat us to initialization, just drop ours.
    // SAFETY: once set, it remains initialized until mshutdown clears it.
    let _ = unsafe { (*core::ptr::addr_of_mut!(GLOBAL_DICTIONARY)).set(RwLock::new(arc)) };
    Ok(())
}

/// Clone a reference to the current global dictionary.
pub fn try_clone_global() -> Result<DdArc<PhpProfilesDictionary>, SetError> {
    init_global_if_needed()?;
    // SAFETY: OnceCell has been initialized above.
    // Use raw pointer to avoid creating shared reference to mutable static
    let lock = unsafe { (*core::ptr::addr_of!(GLOBAL_DICTIONARY)).get() }
        .expect("global profiles dictionary not initialized");

    let guard = lock
        .read()
        .expect("global profiles dictionary lock poisoned");
    (*guard)
        .try_clone()
        .map_err(|_| SetError::ReferenceCountOverflow)
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
pub fn try_clone_tls_or_global() -> Result<DdArc<PhpProfilesDictionary>, SetError> {
    if let Ok(Some(arc)) = TLS_DICTIONARY.try_with_borrow(|slot| {
        slot.as_ref().and_then(|arc| {
            arc.try_clone()
                .map_err(|_| SetError::ReferenceCountOverflow)
                .ok()
        })
    }) {
        return Ok(arc);
    }
    try_clone_global()
}

/// Clears the global dictionary OnceCell back to an empty state.
///
/// # Safety
/// Must be called during PHP mshutdown when no other threads can access the global.
pub unsafe fn clear_global() {
    // SAFETY: caller guarantees mshutdown single-threaded context
    let _ = (*core::ptr::addr_of_mut!(GLOBAL_DICTIONARY)).take();
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
    SetError(#[from] SetError),
}
