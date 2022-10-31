use libc::free;

use crate::bindings::{
    zend_mm_set_custom_handlers, zend_mm_get_heap, __zend_malloc, __zend_realloc
};

pub fn init_allocation_profiling() {
    unsafe {
        zend_mm_set_custom_handlers(
            zend_mm_get_heap(),
            Some(alloc_profiling_malloc),
            Some(alloc_profiling_free),
            Some(alloc_profiling_realloc)
        );
    }
}

// #[no_mangle]
unsafe extern "C" fn alloc_profiling_malloc (len: u64) -> *mut ::libc::c_void {
    let ptr = __zend_malloc(len);
    print!("Allocating {} bytes at {:p}\n", len, ptr);
    ptr
}

// #[no_mangle]
unsafe extern "C" fn alloc_profiling_free(ptr: *mut ::libc::c_void) {
    print!("Freeing mem at {:p}\n", ptr);
    free(ptr);
}

// #[no_mangle]
unsafe extern "C" fn alloc_profiling_realloc (ptr: *mut ::libc::c_void, len: u64) -> *mut ::libc::c_void {
    let newptr = __zend_realloc(ptr, len);
    print!("Realloc mem from {:p} to {:p} size {} bytes\n", ptr, newptr, len);
    newptr
}
