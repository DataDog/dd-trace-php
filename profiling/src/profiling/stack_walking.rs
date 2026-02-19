use crate::bindings::{zend_execute_data, zend_function, zend_op, zend_op_array};
use crate::profiling::Backtrace;
use crate::shm_cache;
use crate::vec_ext::{self, VecExt};
use libdd_profiling_shm::{ShmStringId, ShmStringTable};
use std::borrow::Cow;
use std::collections::TryReserveError;

#[cfg(php_frameless)]
use crate::bindings::{
    zend_flf_functions, ZEND_FRAMELESS_ICALL_0, ZEND_FRAMELESS_ICALL_1, ZEND_FRAMELESS_ICALL_2,
    ZEND_FRAMELESS_ICALL_3,
};

const STR_LEN_LIMIT: usize = u16::MAX as usize;

const COW_PHP_OPEN_TAG: Cow<str> = Cow::Borrowed("<?php");
const COW_LARGE_STRING: Cow<str> = Cow::Borrowed("[suspiciously large string]");

/// Known synthetic frames without a runtime filename.
///
/// Used with [`Frame::from_synthetic`] to create frames for timeline events
/// and special markers. The implementation maps these to pre-interned
/// [`ShmStringId`] values from [`shm_cache::ShmGlobals`].
#[derive(Clone, Copy, Debug)]
pub enum SyntheticFrame {
    Truncated,      // "[truncated]"
    Idle,           // "[idle]"
    Gc,             // "[gc]"
    Include,        // "[include]"
    Require,        // "[require]"
    IncludeUnknown, // "[]"
    ThreadStart,    // "[thread start]"
    ThreadStop,     // "[thread stop]"
}

impl SyntheticFrame {
    /// Returns `(function_name, filename)` string pair for this frame.
    pub const fn as_strs(self) -> (&'static str, &'static str) {
        match self {
            Self::Truncated => ("[truncated]", ""),
            Self::Idle => ("[idle]", ""),
            Self::Gc => ("[gc]", ""),
            Self::Include => ("[include]", ""),
            Self::Require => ("[require]", ""),
            Self::IncludeUnknown => ("[]", ""),
            Self::ThreadStart => ("[thread start]", ""),
            Self::ThreadStop => ("[thread stop]", ""),
        }
    }
}

/// Known frame names that pair with a runtime filename.
///
/// Used with [`Frame::from_synthetic_with_file`] for events like eval,
/// fatal errors, and opcache restarts where the frame name is fixed but
/// the filename comes from runtime context.
#[derive(Clone, Copy, Debug)]
pub enum SyntheticFrameName {
    Eval,           // "[eval]"
    Fatal,          // "[fatal]"
    OpcacheRestart, // "[opcache restart]"
}

impl SyntheticFrameName {
    pub const fn as_str(self) -> &'static str {
        match self {
            Self::Eval => "[eval]",
            Self::Fatal => "[fatal]",
            Self::OpcacheRestart => "[opcache restart]",
        }
    }
}

/// A stack frame identified by interned SHM string IDs.
#[derive(Clone, Copy, Debug)]
pub struct Frame {
    pub function_name: ShmStringId,
    pub filename: ShmStringId,
    pub line: u32,
}

// SAFETY: ShmStringId is a plain u32 wrapper (Copy + Send + Sync).
unsafe impl Send for Frame {}
unsafe impl Sync for Frame {}

impl Default for Frame {
    fn default() -> Self {
        Self {
            function_name: ShmStringTable::EMPTY,
            filename: ShmStringTable::EMPTY,
            line: 0,
        }
    }
}

impl Frame {
    /// Creates a [`Frame`] for a known synthetic event (no runtime filename).
    pub fn from_synthetic(sf: SyntheticFrame, line: u32) -> Self {
        let Some(g) = shm_cache::shm_globals() else {
            return Self::default();
        };
        let function_name = match sf {
            SyntheticFrame::Truncated => g.truncated,
            SyntheticFrame::Idle => g.idle,
            SyntheticFrame::Gc => g.gc,
            SyntheticFrame::Include => g.include,
            SyntheticFrame::Require => g.require,
            SyntheticFrame::IncludeUnknown => g.include_unknown,
            SyntheticFrame::ThreadStart => g.thread_start,
            SyntheticFrame::ThreadStop => g.thread_stop,
        };
        Self {
            function_name,
            filename: ShmStringTable::EMPTY,
            line,
        }
    }

    /// Creates a [`Frame`] for a known synthetic event with a runtime filename.
    ///
    /// Returns `None` if the SHM table is not initialized or interning fails.
    pub fn from_synthetic_with_file(
        name: SyntheticFrameName,
        filename: &str,
        line: u32,
    ) -> Option<Self> {
        let g = shm_cache::shm_globals()?;
        let function_name = match name {
            SyntheticFrameName::Eval => g.eval,
            SyntheticFrameName::Fatal => g.fatal,
            SyntheticFrameName::OpcacheRestart => g.opcache_restart,
        };
        let filename_id = g.table.intern(filename)?;
        Some(Self {
            function_name,
            filename: filename_id,
            line,
        })
    }
}

#[derive(thiserror::Error, Debug)]
pub enum CollectStackSampleError {
    #[error("failed to borrow request locals: already destroyed")]
    AccessError(#[from] std::thread::AccessError),
    #[error("failed to borrow request locals: non-mutable borrow while mutably borrowed")]
    BorrowError(#[from] std::cell::BorrowError),
    #[error("failed to borrow request locals: mutable borrow while mutably borrowed")]
    BorrowMutError(#[from] std::cell::BorrowMutError),
    #[error(transparent)]
    TryReserveError(#[from] std::collections::TryReserveError),
}

/// Computes the fully-qualified function name for a frame.
///
/// The result looks like `{module}|{class}::{method}` for internal methods,
/// `{class}::{method}` for user methods, `{function}` for plain functions,
/// or `"<?php"` for top-level script code (no function name).
///
/// All allocations are fallible — returns `Err` on OOM, never panics.
/// Names exceeding [`STR_LEN_LIMIT`] are replaced with
/// `"[suspiciously large string]"`.
pub fn extract_function_name(func: &zend_function) -> Result<Cow<'static, str>, TryReserveError> {
    let method_name: &[u8] = func.name().unwrap_or(b"");

    if method_name.is_empty() {
        return Ok(COW_PHP_OPEN_TAG);
    }

    let module_name = func.module_name().unwrap_or(b"");
    let class_name = func.scope_name().unwrap_or(b"");

    let (has_module, has_class) = (!module_name.is_empty(), !class_name.is_empty());
    let module_len = has_module as usize * "|".len() + module_name.len();
    let class_name_len = has_class as usize * "::".len() + class_name.len();
    let len = module_len + class_name_len + method_name.len();

    if len >= STR_LEN_LIMIT {
        return Ok(COW_LARGE_STRING);
    }

    let mut buffer = Vec::<u8>::new();
    buffer.try_reserve_exact(len)?;

    if has_module {
        buffer.try_extend_from_slice(module_name)?;
        buffer.try_extend_from_slice(b"|")?;
    }

    if has_class {
        buffer.try_extend_from_slice(class_name)?;
        buffer.try_extend_from_slice(b"::")?;
    }

    buffer.try_extend_from_slice(method_name)?;

    vec_ext::try_cow_from_utf8_lossy_vec(buffer)
}

/// Gets an opline reference after doing bounds checking to prevent segfaults.
#[inline]
fn safely_get_opline(execute_data: &zend_execute_data) -> Option<&zend_op> {
    let func = unsafe { execute_data.func.as_ref()? };
    let op_array = func.op_array()?;
    if opline_in_bounds(op_array, execute_data.opline) {
        unsafe { Some(&*execute_data.opline) }
    } else {
        None
    }
}

#[inline]
fn opline_in_bounds(op_array: &zend_op_array, opline: *const zend_op) -> bool {
    let opcodes_start = op_array.opcodes;
    if opcodes_start.is_null() || opline.is_null() {
        return false;
    }

    let begin = opcodes_start as usize;
    let end = begin + (op_array.last as usize) * core::mem::size_of::<zend_op>();
    (begin..end).contains(&(opline as usize))
}

mod detail {
    use super::*;
    use crate::bindings::ZEND_ACC_CALL_VIA_TRAMPOLINE;

    #[inline]
    pub fn rshutdown() {}

    /// Collects the stack trace.
    ///
    /// # Errors
    /// Returns [`CollectStackSampleError::TryReserveError`] if the vec
    /// holding the frames is unable to allocate memory.
    #[inline(never)]
    pub fn collect_stack_sample(
        top_execute_data: *mut zend_execute_data,
    ) -> Result<Backtrace, CollectStackSampleError> {
        #[cfg(feature = "tracing")]
        let _span = tracing::trace_span!("collect_stack_sample").entered();

        let max_depth = 512;
        let mut samples = Vec::new();
        let mut execute_data_ptr = top_execute_data;

        samples.try_reserve(max_depth >> 3)?;

        while let Some(execute_data) = unsafe { execute_data_ptr.as_ref() } {
            #[allow(unused_variables)]
            if let Some(func) = unsafe { execute_data.func.as_ref() } {
                #[cfg(php_frameless)]
                if !func.is_internal() {
                    if let Some(opline) = safely_get_opline(execute_data) {
                        match opline.opcode as u32 {
                            ZEND_FRAMELESS_ICALL_0
                            | ZEND_FRAMELESS_ICALL_1
                            | ZEND_FRAMELESS_ICALL_2
                            | ZEND_FRAMELESS_ICALL_3 => {
                                let flf = unsafe {
                                    &**zend_flf_functions.offset(opline.extended_value as isize)
                                };
                                if let Some(frame) = unsafe { collect_call_frame_for_func(flf, 0) }
                                {
                                    samples.try_push(frame)?;
                                }
                            }
                            _ => {}
                        }
                    }
                }

                let maybe_frame = unsafe { collect_call_frame(execute_data) };
                if let Some(frame) = maybe_frame {
                    samples.try_push(frame)?;

                    if samples.len() == max_depth - 1 {
                        samples.try_push(Frame::from_synthetic(SyntheticFrame::Truncated, 0))?;
                        break;
                    }
                }
            }

            execute_data_ptr = execute_data.prev_execute_data;
        }
        Ok(Backtrace::new(samples))
    }

    /// Resolves a `zend_function` to a [`Frame`] using its `reserved[handle]`
    /// slot, with the given line number. Used for frameless icalls where
    /// we have the function pointer but no execute_data.
    #[cfg(php_frameless)]
    unsafe fn collect_call_frame_for_func(func: &zend_function, line: u32) -> Option<Frame> {
        let g = shm_cache::shm_globals()?;

        if let Some(handle) = shm_cache::resource_handle() {
            let packed = unsafe { func.internal_function.reserved[handle] };
            let (function_name, filename) = shm_cache::unpack(packed);
            if function_name != ShmStringTable::EMPTY {
                return Some(Frame {
                    function_name,
                    filename,
                    line,
                });
            }
        }

        if log::log_enabled!(log::Level::Trace) {
            if let Ok(name) = extract_function_name(func) {
                log::trace!("internal function not pre-interned (frameless): {}", name);
            }
        }
        Some(Frame {
            function_name: g.internal_function,
            filename: ShmStringTable::EMPTY,
            line,
        })
    }

    unsafe fn collect_call_frame(execute_data: &zend_execute_data) -> Option<Frame> {
        let func = unsafe { execute_data.func.as_ref()? };
        let g = shm_cache::shm_globals()?;

        // Trampolines (__call, Closure::__invoke) have uninitialized
        // reserved[] slots — return the sentinel immediately.
        if unsafe { func.common.fn_flags } & ZEND_ACC_CALL_VIA_TRAMPOLINE != 0 {
            return Some(Frame {
                function_name: g.trampoline,
                filename: ShmStringTable::EMPTY,
                line: 0,
            });
        }

        // Try to read packed ShmStringIds from reserved[handle].
        if let Some(handle) = shm_cache::resource_handle() {
            let packed = if func.is_internal() {
                unsafe { func.internal_function.reserved[handle] }
            } else {
                unsafe { func.op_array.reserved[handle] }
            };

            let (function_name, filename) = shm_cache::unpack(packed);

            if function_name != ShmStringTable::EMPTY {
                let line = safely_get_opline(execute_data).map_or(0, |op| op.lineno);
                return Some(Frame {
                    function_name,
                    filename,
                    line,
                });
            }
        }

        // Fallback: no cached data -- use sentinel.
        if func.is_internal() {
            if log::log_enabled!(log::Level::Trace) {
                if let Ok(name) = extract_function_name(func) {
                    log::trace!("internal function not pre-interned: {}", name);
                }
            }
            Some(Frame {
                function_name: g.internal_function,
                filename: ShmStringTable::EMPTY,
                line: 0,
            })
        } else {
            if log::log_enabled!(log::Level::Trace) {
                if let Ok(name) = extract_function_name(func) {
                    log::trace!("user function not interned: {}", name);
                }
            }
            Some(Frame {
                function_name: g.user_function,
                filename: ShmStringTable::EMPTY,
                line: 0,
            })
        }
    }
}

pub use detail::*;

#[cfg(test)]
mod tests {
    use super::*;
    use crate::bindings as zend;

    extern "C" {
        fn ddog_php_test_create_fake_zend_function_with_name_len(
            len: libc::size_t,
        ) -> *mut zend::zend_function;
        fn ddog_php_test_free_fake_zend_function(func: *mut zend::zend_function);
    }

    #[test]
    fn test_extract_function_name_short_string() {
        unsafe {
            let func = ddog_php_test_create_fake_zend_function_with_name_len(10);
            assert!(!func.is_null());

            let name = extract_function_name(&*func).expect("should extract name");
            assert_eq!(name, "xxxxxxxxxx");

            ddog_php_test_free_fake_zend_function(func);
        }
    }

    #[test]
    fn test_extract_function_name_at_limit_minus_one() {
        unsafe {
            let func = ddog_php_test_create_fake_zend_function_with_name_len(STR_LEN_LIMIT - 1);
            assert!(!func.is_null());

            let name = extract_function_name(&*func).expect("should extract name");
            assert_eq!(name.len(), STR_LEN_LIMIT - 1);
            assert_ne!(name, COW_LARGE_STRING);

            ddog_php_test_free_fake_zend_function(func);
        }
    }

    #[test]
    fn test_extract_function_name_at_limit() {
        unsafe {
            let func = ddog_php_test_create_fake_zend_function_with_name_len(STR_LEN_LIMIT);
            assert!(!func.is_null());

            let name = extract_function_name(&*func).expect("should return large string marker");
            assert_eq!(name, COW_LARGE_STRING);

            ddog_php_test_free_fake_zend_function(func);
        }
    }

    #[test]
    fn test_extract_function_name_over_limit() {
        unsafe {
            let func = ddog_php_test_create_fake_zend_function_with_name_len(STR_LEN_LIMIT + 1000);
            assert!(!func.is_null());

            let name = extract_function_name(&*func).expect("should return large string marker");
            assert_eq!(name, COW_LARGE_STRING);

            ddog_php_test_free_fake_zend_function(func);
        }
    }
}
