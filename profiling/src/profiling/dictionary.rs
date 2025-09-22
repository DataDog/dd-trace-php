// Copyright 2025-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

use crate::RefCellExt;
use datadog_profiling::profiles::collections::{Arc as DdArc, ArcOverflow, StringId};
use datadog_profiling::profiles::datatypes::{Function, FunctionId, ProfilesDictionary};
use datadog_profiling::profiles::ProfileError;
use once_cell::sync::OnceCell;
use std::cell::RefCell;
use std::sync::RwLock;

// Global profiles dictionary shared across the process lifetime.
// This is intentionally wrapped in a RwLock to allow future rotation.
static GLOBAL_DICTIONARY: OnceCell<RwLock<DdArc<ProfilesDictionary>>> = OnceCell::new();

thread_local! {
    // Thread-local dictionary reference, cloned from the global in ginit.
    static TLS_DICTIONARY: RefCell<Option<DdArc<ProfilesDictionary>>> = const { RefCell::new(None) };
}

/// Initializes the global dictionary if it has not been initialized yet.
fn init_global_if_needed() -> Result<(), ProfileError> {
    if GLOBAL_DICTIONARY.get().is_some() {
        return Ok(());
    }

    // Try to create a fresh dictionary and store it.
    let dict = ProfilesDictionary::try_new()?;
    let arc = DdArc::try_new(dict)?;
    // If another thread beat us to initialization, just drop ours.
    let _ = GLOBAL_DICTIONARY.set(RwLock::new(arc));
    // Pre-insert well-known synthetic entries so later lookups are cheap and reliable.
    if let Some(lock) = GLOBAL_DICTIONARY.get() {
        let guard = lock
            .read()
            .expect("global profiles dictionary lock poisoned");
        truncated_function(&guard)?;
    }
    Ok(())
}

/// Clone a reference to the current global dictionary.
pub fn try_clone_global() -> Result<DdArc<ProfilesDictionary>, ProfileError> {
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

pub const TRUNCATED_STR: &str = "[truncated]";

/// Ensures the "\[truncated\]" function exists in the given dictionary and
/// returns its function id.
pub fn truncated_function(dict: &ProfilesDictionary) -> Result<FunctionId, ProfileError> {
    let strings = dict.strings();
    let name_id = strings.try_insert(TRUNCATED_STR)?;
    let function = Function {
        name: name_id,
        system_name: StringId::EMPTY,
        file_name: StringId::EMPTY,
    };
    let id = dict.functions().try_insert(function)?;
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
pub fn try_clone_tls_or_global() -> Result<DdArc<ProfilesDictionary>, ProfileError> {
    if let Ok(Some(arc)) =
        TLS_DICTIONARY.try_with_borrow(|slot| slot.as_ref().and_then(|arc| arc.try_clone().ok()))
    {
        return Ok(arc);
    }
    try_clone_global()
}

/// Executes `f` with a shared reference to the active profiles dictionary.
/// Prefers TLS dictionary, falling back to global without cloning.
pub fn with_tls_or_global<R>(f: impl Fn(&ProfilesDictionary) -> R) -> Result<R, InitError> {
    if let Ok(Some(r)) = TLS_DICTIONARY.try_with_borrow(|slot| slot.as_ref().map(|arc| f(arc))) {
        return Ok(r);
    }
    init_global_if_needed()?;
    let lock = GLOBAL_DICTIONARY
        .get()
        .expect("global profiles dictionary not initialized");
    let guard = lock.read().map_err(|_| InitError::Poisoned)?;
    Ok(f(&guard))
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
