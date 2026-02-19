//! Global [`ProfilesDictionary`] and pre-interned [`KnownStrings`].
//!
//! Initialized once in [`init`] (called from `get_module`) and lives for
//! the process lifetime. `get_module` is the very first entry point into
//! the extension — if it doesn't run, none of our code ever executes.
//! The dictionary is thread-safe (`Send + Sync`) and never dropped, so
//! all `StringId2` / `FunctionId2` values derived from it are valid for
//! `'static`.

use libdd_profiling::profiles::collections::Arc as DictArc;
use libdd_profiling::profiles::datatypes::{ProfilesDictionary, StringId2};
use std::mem::MaybeUninit;
use std::ptr;
use std::sync::atomic::{AtomicBool, Ordering};

/// Global dictionary, initialized exactly once in [`init`].
static mut DICTIONARY: MaybeUninit<DictArc<ProfilesDictionary>> = MaybeUninit::uninit();

/// Global known strings, initialized exactly once in [`init`].
static mut KNOWN_STRINGS: MaybeUninit<KnownStrings> = MaybeUninit::uninit();

/// Guard to ensure [`init`] only runs once.
static INITIALIZED: AtomicBool = AtomicBool::new(false);

/// Pre-interned string IDs for label keys and special frame strings.
///
/// All fields are inserted into the global [`ProfilesDictionary`] once
/// during [`init`] and remain valid for the process lifetime.
pub struct KnownStrings {
    // ── Label keys ──────────────────────────────────────────────────
    pub thread_id: StringId2,
    pub thread_name: StringId2,
    pub local_root_span_id: StringId2,
    pub span_id: StringId2,
    pub fiber: StringId2,
    pub exception_type: StringId2,
    pub exception_message: StringId2,
    pub event: StringId2,
    /// The `"filename"` label key (distinct from actual file name strings).
    pub label_filename: StringId2,
    pub message: StringId2,
    pub reason: StringId2,
    pub gc_reason: StringId2,
    pub gc_runs: StringId2,
    pub gc_collected: StringId2,

    // ── Special frame strings ───────────────────────────────────────
    /// `"<?php"` — used as the function name for top-level script code.
    pub php_open_tag: StringId2,
    /// `"[truncated]"` — function name for stack truncation markers.
    pub truncated: StringId2,
    /// `"[suspiciously large string]"` — replacement for oversized strings.
    pub suspiciously_large: StringId2,
}

// SAFETY: all StringId2 values point into the global ProfilesDictionary,
// which is Sync and lives for the process lifetime. Only shared references
// (`&KnownStrings`) are handed out, so only Sync is needed.
unsafe impl Sync for KnownStrings {}

impl KnownStrings {
    fn new(dict: &ProfilesDictionary) -> Self {
        let s = |str: &str| -> StringId2 {
            dict.try_insert_str2(str)
                .expect("failed to intern known string into ProfilesDictionary")
        };

        Self {
            // Label keys
            thread_id: s("thread id"),
            thread_name: s("thread name"),
            local_root_span_id: s("local root span id"),
            span_id: s("span id"),
            fiber: s("fiber"),
            exception_type: s("exception type"),
            exception_message: s("exception message"),
            event: s("event"),
            label_filename: s("filename"),
            message: s("message"),
            reason: s("reason"),
            gc_reason: s("gc reason"),
            gc_runs: s("gc runs"),
            gc_collected: s("gc collected"),

            // Special frame strings
            php_open_tag: s("<?php"),
            truncated: s("[truncated]"),
            suspiciously_large: s("[suspiciously large string]"),
        }
    }
}

/// Initializes the global [`ProfilesDictionary`] and [`KnownStrings`].
///
/// Must be called from `get_module`. It's safe to call it multiple times, but
/// it's not recommended to call it on a hot path for performance.
pub fn init() {
    if INITIALIZED.load(Ordering::Acquire) {
        return;
    }

    let dict = ProfilesDictionary::try_new().expect("failed to create ProfilesDictionary");
    let arc = DictArc::try_new(dict).expect("failed to allocate ProfilesDictionary Arc");

    // SAFETY: the AtomicBool guard ensures these writes happen exactly
    // once. get_module (our caller) runs single-threaded before any
    // workers exist, so there are no concurrent readers yet.
    unsafe {
        ptr::addr_of_mut!(DICTIONARY).write(MaybeUninit::new(arc));
        let dict = dictionary();
        ptr::addr_of_mut!(KNOWN_STRINGS).write(MaybeUninit::new(KnownStrings::new(dict)));
    }

    INITIALIZED.store(true, Ordering::Release);
}

/// Returns a reference to the global [`ProfilesDictionary`].
///
/// The caller must ensure [`init`] has been called. This is guaranteed
/// by the extension lifecycle: `get_module` -> `init` -> everything else,
/// which is why this has been marked safe, but this could be violated such
/// as in test code that hasn't been properly set up.
#[inline(always)]
pub fn dictionary() -> &'static ProfilesDictionary {
    unsafe { (*ptr::addr_of!(DICTIONARY)).assume_init_ref() }
}

/// Returns the [`DictArc`] wrapping the global dictionary. Use this when
/// creating a `Profile` via `try_new_with_dictionary`.
///
/// The caller must ensure [`init`] has been called.
#[inline(always)]
pub fn dictionary_arc() -> &'static DictArc<ProfilesDictionary> {
    unsafe { (*ptr::addr_of!(DICTIONARY)).assume_init_ref() }
}

/// Returns the pre-interned [`KnownStrings`].
///
/// The caller must ensure [`init`] has been called.
#[inline(always)]
pub fn known_strings() -> &'static KnownStrings {
    unsafe { (*ptr::addr_of!(KNOWN_STRINGS)).assume_init_ref() }
}
