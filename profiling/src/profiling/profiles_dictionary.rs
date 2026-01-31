use libdd_profiling::profiles::collections::Arc;
use libdd_profiling::profiles::datatypes::ProfilesDictionary;
use std::mem::MaybeUninit;
use std::ptr;
use std::sync::Once;

static PROFILES_DICTIONARY_INIT: Once = Once::new();
static mut PROFILES_DICTIONARY: MaybeUninit<Arc<ProfilesDictionary>> = MaybeUninit::uninit();

/// Initializes the global profiles dictionary and inserts some strings we
/// know that will be needed at runtime.
pub fn init_profiles_dictionary() {
    PROFILES_DICTIONARY_INIT.call_once(|| {
        let dict = ProfilesDictionary::try_new().expect("failed to initialize ProfilesDictionary");
        for name in [
            "<?php",
            "[truncated]",
            "[suspiciously large string]",
            "[eval]",
            "[include]",
            "[include_once]",
            "[require]",
            "[require_once]",
            "[fatal]",
            "[opcache restart]",
            "[idle]",
            "[gc]",
            "compilation",
            "fatal",
            "opcache_restart",
            "gc",
            "exception type",
            "exception message",
            "event",
            "filename",
            "message",
            "reason",
            "gc reason",
            "gc runs",
            "gc collected",
            "thread id",
            "thread name",
            "local root span id",
            "span id",
            "fiber",
        ] {
            dict.try_insert_str2(name)
                .expect("failed to intern ProfilesDictionary string");
        }
        let dict = Arc::try_new(dict).expect("failed to allocate ProfilesDictionary");
        unsafe {
            ptr::addr_of_mut!(PROFILES_DICTIONARY).write(MaybeUninit::new(dict));
        }
    });
}

#[inline]
pub fn profiles_dictionary() -> &'static Arc<ProfilesDictionary> {
    debug_assert!(PROFILES_DICTIONARY_INIT.is_completed());
    // SAFETY: The dictionary is written in `get_module` via
    // `init_profiles_dictionary()`.
    unsafe { &*ptr::addr_of!(PROFILES_DICTIONARY).cast::<Arc<ProfilesDictionary>>() }
}
