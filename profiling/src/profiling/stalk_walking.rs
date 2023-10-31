use datadog_profiling::collections::identifiable::{
    small_non_zero_pprof_id, Id, Item, StringId, StringTable, StringTableReader, Table,
};

use crate::bindings::{zai_str_from_zstr, zend_execute_data, zend_function, ZEND_USER_FUNCTION};
use std::cell::RefCell;
use std::num::NonZeroU32;
use std::str::Utf8Error;

/// Used to help track the function run_time_cache hit rate. It glosses over
/// the fact that there are two cache slots used, and they don't have to be in
/// sync. However, they usually are, so we simplify.
#[cfg(php_run_time_cache)]
#[derive(Debug, Default)]
pub struct FunctionRunTimeCacheStats {
    hit: usize,
    missed: usize,
    not_applicable: usize,
}

#[cfg(php_run_time_cache)]
impl FunctionRunTimeCacheStats {
    pub fn hit_rate(&self) -> f64 {
        let denominator = (self.hit + self.missed + self.not_applicable) as f64;
        self.hit as f64 / denominator
    }
}

#[derive(Copy, Clone, Debug, Eq, PartialEq, Hash, PartialOrd, Ord)]
#[repr(C)]
pub struct AbridgedFunction {
    pub name: StringId,
    pub filename: StringId,
}

impl Item for AbridgedFunction {
    type Id = FunctionId;
}

pub struct FunctionTable {
    pub functions: Table<AbridgedFunction>,
    pub strings: StringTable,
}

#[derive(Copy, Clone, Debug, Eq, PartialEq, Hash, PartialOrd, Ord)]
#[repr(transparent)]
pub struct FunctionId(NonZeroU32);

impl Id for FunctionId {
    type RawId = u64;

    fn from_offset(offset: usize) -> Self {
        Self(small_non_zero_pprof_id(offset).expect("FunctionId to fit into a u32"))
    }

    fn to_offset(&self) -> usize {
        (self.0.get() - 1) as usize
    }

    fn to_raw_id(&self) -> Self::RawId {
        self.0.get().into()
    }
}

pub const STR_ID_PHP_OPEN: StringId = StringId::new(1);
pub const STR_ID_GC: StringId = StringId::new(2);
pub const STR_ID_INCLUDE: StringId = StringId::new(3);
pub const STR_ID_REQUIRE: StringId = StringId::new(4);
pub const STR_ID_TRUNCATED: StringId = StringId::new(5);

#[cfg(feature = "timeline")]
pub const STR_ID_EVAL: StringId = StringId::new(6);

fn new_string_table_with_known_strings(capacity: usize) -> anyhow::Result<StringTable> {
    let mut table = StringTable::with_capacity(capacity)?;
    assert_eq!(STR_ID_PHP_OPEN, table.insert("<?php")?);
    assert_eq!(STR_ID_GC, table.insert("[gc]")?);
    assert_eq!(STR_ID_INCLUDE, table.insert("[include]")?);
    assert_eq!(STR_ID_REQUIRE, table.insert("[require]")?);
    assert_eq!(STR_ID_TRUNCATED, table.insert("[truncated]")?);

    #[cfg(feature = "timeline")]
    assert_eq!(StringId::new(6), table.insert("[eval]")?);

    Ok(table)
}

thread_local! {
    pub static CACHED_STRINGS: RefCell<StringTable> = RefCell::new(new_string_table_with_known_strings(1024*1024*8).unwrap());

    #[cfg(php_run_time_cache)]
    pub static FUNCTION_CACHE_STATS: RefCell<FunctionRunTimeCacheStats> = RefCell::new(Default::default())
}

/// # Safety
/// Must be called in Zend Extension activate.
#[inline]
pub unsafe fn activate_run_time_cache() {
    CACHED_STRINGS
        .with(|cell| cell.replace(new_string_table_with_known_strings(1024 * 1024 * 8).unwrap()));
}

pub struct ZendFrame {
    pub reader: StringTableReader,
    pub function: AbridgedFunction,
    pub line: u32, // use 0 for no line info
}

/// Extract the "function name" component for the frame. This is a string which
/// looks like this for methods:
///     {module}|{class_name}::{method_name}
/// And this for functions:
///     {module}|{function_name}
/// Where the "{module}|" is present only if it's an internal function.
/// Namespaces are part of the class_name or function_name respectively.
/// Closures and anonymous classes get reformatted by the backend (or maybe
/// frontend, either way it's not our concern, at least not right now).
pub fn extract_function_name(func: &zend_function) -> Option<String> {
    // todo: pass string table in here instead of making a temporary String
    let method_name: &[u8] = func.name().unwrap_or(b"");

    /* The top of the stack seems to reasonably often not have a function, but
     * still has a scope. I don't know if this intentional, or if it's more of
     * a situation where scope is only valid if the func is present. So, I'm
     * erring on the side of caution and returning early.
     */
    if method_name.is_empty() {
        return None;
    }

    let mut buffer = Vec::<u8>::new();

    // User functions do not have a "module". Maybe one day use composer info?
    let module_name = func.module_name().unwrap_or(b"");
    if !module_name.is_empty() {
        buffer.extend_from_slice(module_name);
        buffer.push(b'|');
    }

    let class_name = func.scope_name().unwrap_or(b"");
    if !class_name.is_empty() {
        buffer.extend_from_slice(class_name);
        buffer.extend_from_slice(b"::");
    }

    buffer.extend_from_slice(method_name);

    Some(String::from_utf8_lossy(buffer.as_slice()).into_owned())
}

pub fn extract_function_name_id(
    func: &zend_function,
    string_table: &mut StringTable,
) -> Option<StringId> {
    let method_name: &[u8] = func.name().unwrap_or(b"");

    /* The top of the stack seems to reasonably often not have a function, but
     * still has a scope. I don't know if this intentional, or if it's more of
     * a situation where scope is only valid if the func is present. So, I'm
     * erring on the side of caution and returning early.
     */
    if method_name.is_empty() {
        return None;
    }

    let mut buffer = Vec::<u8>::new();

    // User functions do not have a "module". Maybe one day use composer info?
    let module_name = func.module_name().unwrap_or(b"");
    if !module_name.is_empty() {
        buffer.extend_from_slice(module_name);
        buffer.push(b'|');
    }

    let class_name = func.scope_name().unwrap_or(b"");
    if !class_name.is_empty() {
        buffer.extend_from_slice(class_name);
        buffer.extend_from_slice(b"::");
    }

    buffer.extend_from_slice(method_name);

    // todo: fix panic when full
    Some(
        string_table
            .insert(&String::from_utf8_lossy(buffer.as_slice()))
            .unwrap(),
    )
}

// #[cfg(php_run_time_cache)]
// #[inline]
// unsafe fn handle_file_cache_slot_helper(
//     execute_data: &zend_execute_data,
//     string_table: &mut StringTable,
//     cache_slots: &mut [usize; 2],
// ) -> Option<String> {
//     let file = if cache_slots[1] > 0 {
//         let offset = cache_slots[1] as u32;
//         let str = string_table.get_offset(offset);
//         String::from(str)
//     } else {
//         // Safety: if we have cache slots, we definitely have a func.
//         let func = &*execute_data.func;
//         if func.type_ != ZEND_USER_FUNCTION as u8 {
//             return None;
//         };

//         let file = zai_str_from_zstr(func.op_array.filename.as_mut()).into_string();
//         let offset = string_table.insert(file.as_ref());
//         cache_slots[1] = offset as usize;
//         file
//     };

//     Some(file)
// }

// #[cfg(php_run_time_cache)]
// unsafe fn handle_file_cache_slot(
//     execute_data: &zend_execute_data,
//     string_table: &mut StringTable,
//     cache_slots: &mut [usize; 2],
// ) -> (Option<String>, u32) {
//     match handle_file_cache_slot_helper(execute_data, string_table, cache_slots) {
//         Some(filename) => {
//             let lineno = match execute_data.opline.as_ref() {
//                 Some(opline) => opline.lineno,
//                 None => 0,
//             };
//             (Some(filename), lineno)
//         }
//         None => (None, 0),
//     }
// }

#[cfg(php_run_time_cache)]
unsafe fn handle_function_cache_slot(
    execute_data: &zend_execute_data,
    string_table: &mut StringTable,
    cache_slot: &mut AbridgedFunction,
) -> Option<(AbridgedFunction, u32)> {
    let name = if cache_slot.name.is_zero() {
        let name = extract_function_name_id(execute_data.func.as_ref()?, string_table);
        if let Some(name_id) = name {
            cache_slot.name = name_id;
        }
        name
    } else {
        Some(cache_slot.name)
    };

    let func = &*execute_data.func;
    let filename = if cache_slot.filename.is_zero() {
        if func.is_user_code() {
            StringId::ZERO
        } else {
            let filename = zai_str_from_zstr(func.op_array.filename.as_mut()).into_string();
            let filename_string_id = string_table.insert(filename.as_str()).unwrap();
            cache_slot.filename = filename_string_id;
            filename_string_id
        }
    } else {
        cache_slot.filename
    };

    let line = if func.is_user_code() {
        match execute_data.opline.as_ref() {
            Some(opline) => opline.lineno,
            None => 0,
        }
    } else {
        0
    };

    let name = if let Some(name_id) = name {
        name_id
    } else if filename.is_zero() {
        return None;
    } else {
        StringId::new(1)
    };

    Some((AbridgedFunction { name, filename }, line))
}

unsafe fn extract_file_and_line(
    execute_data: &zend_execute_data,
    string_table: &mut StringTable,
) -> (Option<StringId>, u32) {
    // This should be Some, just being cautious.
    match execute_data.func.as_ref() {
        Some(func) if func.is_user_code() => {
            // Safety: zai_str_from_zstr will return a valid ZaiStr.
            // todo: fix panic when full
            let file = string_table
                .insert(&zai_str_from_zstr(func.op_array.filename.as_mut()).to_string_lossy())
                .unwrap();
            let lineno = match execute_data.opline.as_ref() {
                Some(opline) => opline.lineno,
                None => 0,
            };
            (Some(file), lineno)
        }
        _ => (None, 0),
    }
}

#[cfg(php_run_time_cache)]
unsafe fn collect_call_frame(
    execute_data: &zend_execute_data,
    string_table: &mut StringTable,
) -> Option<ZendFrame> {
    #[cfg(not(feature = "stack_walking_tests"))]
    use crate::bindings::ddog_php_prof_function_run_time_cache;
    #[cfg(feature = "stack_walking_tests")]
    use crate::bindings::ddog_test_php_prof_function_run_time_cache as ddog_php_prof_function_run_time_cache;

    let func = execute_data.func.as_ref()?;
    let (function, filename, line) = match ddog_php_prof_function_run_time_cache(func) {
        Some(cache_slot) => {
            FUNCTION_CACHE_STATS.with(|cell| {
                let mut stats = cell.borrow_mut();
                if cache_slot.filename.is_zero() || cache_slot.name.is_zero() {
                    stats.missed += 1;
                } else {
                    stats.hit += 1;
                }
            });
            match handle_function_cache_slot(execute_data, string_table, cache_slot) {
                None => (None, None, 0),
                Some((function, line)) => (Some(function.name), Some(function.filename), line),
            }
        }

        None => {
            FUNCTION_CACHE_STATS.with(|cell| {
                let mut stats = cell.borrow_mut();
                stats.not_applicable += 1;
            });

            let function = extract_function_name_id(func, string_table);
            let (file, line) = extract_file_and_line(execute_data, string_table);

            (function, file, line)
        }
    };

    if function.is_some() || filename.is_some() {
        Some(ZendFrame {
            reader: string_table.get_reader(),
            function: AbridgedFunction {
                name: function.unwrap_or(STR_ID_PHP_OPEN),
                filename: filename.unwrap_or(StringId::ZERO),
            },
            line,
        })
    } else {
        None
    }
}

#[cfg(not(php_run_time_cache))]
unsafe fn collect_call_frame(
    execute_data: &zend_execute_data,
    string_table: &mut StringTable,
) -> Option<ZendFrame> {
    if let Some(func) = execute_data.func.as_ref() {
        let function = extract_function_name_id(func, string_table);
        let (file, line) = extract_file_and_line(execute_data, string_table);

        // Only create a new frame if there's file or function info.
        if file.is_some() || function.is_some() {
            // If there's no function name, use a fake name.
            let name = function.unwrap_or(STR_ID_PHP_OPEN);

            let file = file.unwrap_or(StringId::ZERO);
            return Some(ZendFrame {
                reader: string_table.get_reader(),
                function: AbridgedFunction {
                    name,
                    filename: file,
                },
                line,
            });
        }
    }
    None
}

#[inline]
fn collect_stack_sample_helper(
    top_execute_data: *mut zend_execute_data,
    string_table: &mut StringTable,
) -> Result<Vec<ZendFrame>, Utf8Error> {
    let max_depth = 512;
    let mut samples = Vec::with_capacity(max_depth >> 3);
    let mut execute_data_ptr = top_execute_data;

    while let Some(execute_data) = unsafe { execute_data_ptr.as_ref() } {
        let maybe_frame = unsafe { collect_call_frame(execute_data, string_table) };
        if let Some(frame) = maybe_frame {
            samples.push(frame);

            /* -1 to reserve room for the [truncated] message. In case the
             * backend and/or frontend have the same limit, without the -1
             * then ironically the [truncated] message would be truncated.
             */
            if samples.len() == max_depth - 1 {
                samples.push(ZendFrame {
                    reader: string_table.get_reader(),
                    function: AbridgedFunction {
                        name: STR_ID_TRUNCATED,
                        filename: StringId::ZERO,
                    },
                    line: 0,
                });
                break;
            }
        }

        execute_data_ptr = execute_data.prev_execute_data;
    }
    Ok(samples)
}

pub fn collect_stack_sample(
    top_execute_data: *mut zend_execute_data,
) -> Result<Vec<ZendFrame>, Utf8Error> {
    CACHED_STRINGS.with(|cell| {
        let mut string_table = cell.borrow_mut();
        collect_stack_sample_helper(top_execute_data, &mut string_table)
    })
}

#[cfg(all(test, feature = "stack_walking_tests"))]
mod tests {
    use super::*;
    use crate::bindings as zend;

    #[test]
    fn test_collect_stack_sample() {
        unsafe {
            let fake_execute_data = zend::ddog_php_test_create_fake_zend_execute_data(3);

            let stack = collect_stack_sample(fake_execute_data).unwrap();

            assert_eq!(stack.len(), 3);

            assert_eq!(
                stack[0].reader.try_get_id(stack[0].function.name).unwrap(),
                "function name 003"
            );
            assert_eq!(
                stack[0]
                    .reader
                    .try_get_id(stack[0].function.filename)
                    .unwrap(),
                "filename-003.php"
            );
            assert_eq!(stack[0].line, 0);

            assert_eq!(
                stack[0].reader.try_get_id(stack[1].function.name).unwrap(),
                "function name 002"
            );
            assert_eq!(
                stack[0]
                    .reader
                    .try_get_id(stack[1].function.filename)
                    .unwrap(),
                "filename-002.php"
            );
            assert_eq!(stack[1].line, 0);

            assert_eq!(
                stack[0].reader.try_get_id(stack[2].function.name).unwrap(),
                "function name 001"
            );
            assert_eq!(
                stack[0]
                    .reader
                    .try_get_id(stack[2].function.filename)
                    .unwrap(),
                "filename-001.php"
            );
            assert_eq!(stack[2].line, 0);

            // Free the allocated memory
            zend::ddog_php_test_free_fake_zend_execute_data(fake_execute_data);
        }
    }
}
