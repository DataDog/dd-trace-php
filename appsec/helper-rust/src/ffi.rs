use std::{
    cell::UnsafeCell,
    ffi::CStr,
    marker::PhantomData,
    ops::Deref,
    sync::atomic::{AtomicBool, Ordering},
};

use crate::client::log::error;

pub mod sidecar_ffi;

#[macro_export]
macro_rules! sidecar_symbol {
    // form 1: inline function signature
    (
        static $static:ident =
        fn($($arg:ty),* $(, ...)? $(,)?) $(-> $ret:ty)? :
        $name:ident
    ) => {
        type $name = unsafe extern "C" fn(
            $($arg),*
            $(, ...)?
        ) $(-> $ret)?;

        static $static: SidecarSymbol<$name> =
            SidecarSymbol::new(::std::concat!(::std::stringify!($name), "\0"));

        const _: () = {
            let _: $name = $name;
        };
    };

    // form 2: type alias
    (
        static $static:ident =
        $ty:ty :
        $name:ident
    ) => {
        static $static: SidecarSymbol<$ty> =
            SidecarSymbol::new(unsafe {::std::ffi::CStr::from_bytes_with_nul_unchecked(::std::concat!(::std::stringify!($name), "\0").as_bytes())});

        const _: () = {
            let _: $ty = $name;
        };
    };
}

pub struct SidecarSymbol<Func> {
    // In order to implement Deref, we need to have a sort of rvalue for the function
    // So do not use an AtomicPtr that we check for null to determine if we're initialized
    func_ptr: UnsafeCell<*mut libc::c_void>,
    initialized: AtomicBool,
    name: &'static CStr,
    _phantom: PhantomData<Func>,
}
impl<Func> SidecarSymbol<Func> {
    pub const fn new(name: &'static CStr) -> Self {
        assert!(
            std::mem::size_of::<Func>() == std::mem::size_of::<*mut libc::c_void>(),
            "Func must be pointer-sized"
        );
        Self {
            func_ptr: UnsafeCell::new(std::ptr::null_mut()),
            initialized: AtomicBool::new(false),
            name,
            _phantom: PhantomData,
        }
    }

    pub fn resolve(&self) -> anyhow::Result<()> {
        if self.initialized.load(Ordering::Acquire) {
            return Ok(());
        }

        let func_ptr = unsafe { libc::dlsym(libc::RTLD_DEFAULT, self.name.as_ptr()) };
        if func_ptr.is_null() {
            return Err(anyhow::anyhow!("Failed to resolve symbol: {:?}", self.name));
        }
        unsafe { std::ptr::write(self.func_ptr.get(), func_ptr) };
        self.initialized.store(true, Ordering::Release);
        Ok(())
    }

    fn get(&self) -> Option<&Func> {
        if !self.initialized.load(Ordering::Acquire) {
            None
        } else {
            Some(unsafe { &*self.func_ptr.get().cast() })
        }
    }
}
unsafe impl<Func> Sync for SidecarSymbol<Func> {}
impl<Func> Deref for SidecarSymbol<Func> {
    type Target = Func;

    fn deref(&self) -> &Self::Target {
        match self.get() {
            Some(func) => func,
            None => {
                error!("Symbol is not resolved, will panic: {:?}", self);
                panic!("Symbol is not resolved: {:?}", self.name);
            }
        }
    }
}
impl<Func> std::fmt::Debug for SidecarSymbol<Func> {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        let initialized = self.initialized.load(Ordering::Acquire);
        let mut ds = f.debug_struct("SidecarSymbol");
        ds.field("name", &self.name)
            .field("name", &self.name)
            .field("initialized", &initialized);
        if initialized {
            ds.field("func_ptr", &self.func_ptr.get());
        }
        ds.finish()
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::ffi::CStr;
    use std::io::Write;
    use std::path::PathBuf;
    use std::process::Command;

    /// Compiles a minimal Rust cdylib with exported extern "C" functions
    /// Returns the path to the compiled shared library
    fn compile_test_library() -> PathBuf {
        let temp_dir = std::env::temp_dir().join("sidecar_symbol_test");
        std::fs::create_dir_all(&temp_dir).expect("Failed to create temp dir");

        let rust_source = r#"
#[no_mangle]
pub extern "C" fn test_add(a: i32, b: i32) -> i32 {
    a + b
}
"#;

        let rs_file = temp_dir.join("test_lib.rs");
        let mut file = std::fs::File::create(&rs_file).expect("Failed to create Rust file");
        file.write_all(rust_source.as_bytes())
            .expect("Failed to write Rust source");

        #[cfg(target_os = "macos")]
        let lib_name = "libtest_sidecar.dylib";
        #[cfg(target_os = "linux")]
        let lib_name = "libtest_sidecar.so";

        let lib_path = temp_dir.join(lib_name);

        let output = Command::new("rustc")
            .args([
                "--crate-type=cdylib",
                "-o",
                lib_path.to_str().unwrap(),
                rs_file.to_str().unwrap(),
            ])
            .output()
            .expect("Failed to run rustc");

        assert!(
            output.status.success(),
            "Failed to compile test library: {}",
            String::from_utf8_lossy(&output.stderr)
        );

        lib_path
    }

    fn load_library(path: &std::path::Path) -> *mut libc::c_void {
        let path_cstr = std::ffi::CString::new(path.to_str().unwrap()).expect("Invalid path");

        let handle =
            unsafe { libc::dlopen(path_cstr.as_ptr(), libc::RTLD_NOW | libc::RTLD_GLOBAL) };

        if handle.is_null() {
            let error = unsafe { libc::dlerror() };
            if !error.is_null() {
                let error_str = unsafe { CStr::from_ptr(error) };
                panic!("dlopen failed: {:?}", error_str);
            }
            panic!("dlopen failed with unknown error");
        }

        handle
    }

    fn unload_library(handle: *mut libc::c_void) {
        if !handle.is_null() {
            unsafe {
                libc::dlclose(handle);
            }
        }
    }

    type TestAddFn = unsafe extern "C" fn(i32, i32) -> i32;

    #[test]
    fn test_sidecar_symbol_resolve_and_call() {
        let lib_path = compile_test_library();
        let handle = load_library(&lib_path);

        static TEST_ADD_SYMBOL: SidecarSymbol<TestAddFn> = SidecarSymbol::new(c"test_add");
        TEST_ADD_SYMBOL
            .resolve()
            .expect("Failed to resolve test_add symbol");

        let result = unsafe { TEST_ADD_SYMBOL(3, 4) };
        assert_eq!(result, 7, "test_add(3, 4) should return 7");

        unload_library(handle);
    }

    #[test]
    fn test_sidecar_symbol_unresolved_symbol_fails() {
        static NONEXISTENT_SYMBOL: SidecarSymbol<TestAddFn> =
            SidecarSymbol::new(c"nonexistent_function_12345");

        let result = NONEXISTENT_SYMBOL.resolve();
        assert!(result.is_err(), "Resolving nonexistent symbol should fail");
    }

    #[test]
    fn test_sidecar_symbol_debug_format() {
        static DEBUG_TEST_SYMBOL: SidecarSymbol<TestAddFn> = SidecarSymbol::new(c"debug_test_func");

        let debug_str = format!("{:?}", DEBUG_TEST_SYMBOL);
        assert!(
            debug_str.contains("initialized: false"),
            "Unresolved symbol should show initialized: false"
        );
        assert!(
            debug_str.contains("debug_test_func"),
            "Debug output should contain the symbol name"
        );
    }
}
